<?php
/**
 * ReliableSite Order API client.
 *
 * Wraps the customer-facing Order API (https://order-api.reliablesite.dev/v1/)
 * used to submit fresh dedicated-server orders on the reseller's ReliableSite
 * account. This is a *separate* API from the v2 management API wrapped by
 * {@see ReliablesiteApi}: different host, a required per-request
 * Idempotency-Key header on order creation, and plain REST status codes
 * (200/201/400/409) rather than the v2 { status:1, data:... } envelope - which
 * is why it is a standalone client.
 *
 * Authentication reuses the same reseller API key (GUID) from the module row;
 * the bearer token is resolved and cached by the module (see
 * Reliablesite::getOrderApiToken()) and passed in here.
 *
 * Every public call returns a normalized array:
 *   ['success' => bool, 'httpCode' => int, 'data' => mixed, 'errors' => string[]]
 *
 * Blesta port of the Paymenter module's Support\OrderApiClient.
 *
 * @package reliablesite.lib
 */
class ReliablesiteOrderApi
{
    /** @var string Base URL of the ReliableSite Order API */
    private $apiUrl = 'https://order-api.reliablesite.dev/v1/';

    /** @var string Bearer token used for authenticated calls */
    private $apiToken = '';

    /** @var array Pre-flight errors (e.g. missing/invalid token); short-circuits calls */
    private $errors = [];

    /** @var object|null The owning module (used for logging) */
    private $moduleObj = null;

    /**
     * @param string $apiToken A valid Order API bearer token
     * @param object $moduleObj The owning module, exposing myLog()
     */
    public function __construct($apiToken, $moduleObj = null)
    {
        $this->apiToken = $apiToken;
        $this->moduleObj = $moduleObj;
    }

    /**
     * Sets pre-flight errors. When non-empty, every call short-circuits and
     * returns them instead of hitting the network.
     *
     * @param array $errors
     */
    public function setError($errors)
    {
        $this->errors = is_array($errors) ? $errors : [];
    }

    /**
     * POST /v1/Orders - create one unpaid order + invoice.
     *
     * The Idempotency-Key header is required; reuse the same key only when
     * retrying the exact same request.
     *
     * @param array $request The order payload
     * @param string $idempotencyKey Unique per order
     * @return array { success, httpCode, data, errors }
     */
    public function createOrder(array $request, $idempotencyKey)
    {
        return $this->callAPI('Orders', 'POST', [], $request, ['Idempotency-Key: ' . $idempotencyKey]);
    }

    /**
     * GET /v1/PaymentMethods - gateways available for ordering.
     *
     * @param int|null $productId Optional product to scope the methods to
     * @return array { success, httpCode, data, errors }
     */
    public function paymentMethods($productId = null)
    {
        $query = [];
        if ($productId !== null && (int) $productId > 0) {
            $query['productId'] = (int) $productId;
        }

        return $this->callAPI('PaymentMethods', 'GET', $query);
    }

    /**
     * GET /v1/Products/{productId}/Stock - live orderable stock.
     *
     * @param int $productId
     * @return array { success, httpCode, data, errors }
     */
    public function productStock($productId)
    {
        return $this->callAPI('Products/' . (int) $productId . '/Stock', 'GET');
    }

    /**
     * GET /v1/Reseller/PromoCodes - active reseller promo codes.
     * Returns HTTP 403 when the account is not a reseller (callers treat that
     * as "unavailable", not a hard failure).
     *
     * @param int|null $configurationId
     * @return array { success, httpCode, data, errors }
     */
    public function resellerPromoCodes($configurationId = null)
    {
        $query = [];
        if ($configurationId !== null && (int) $configurationId > 0) {
            $query['configurationId'] = (int) $configurationId;
        }

        return $this->callAPI('Reseller/PromoCodes', 'GET', $query);
    }

    /**
     * GET /v1/Login/VerifyToken - confirm the cached bearer token is valid.
     *
     * @return array { success, httpCode, data, errors }
     */
    public function verifyToken()
    {
        return $this->callAPI('Login/VerifyToken', 'GET');
    }

    /**
     * Convenience helper returning a [module => displayName] map for building a
     * payment-method <select>, or an empty array when the API is unreachable.
     *
     * @param int|null $productId
     * @return array
     */
    public function paymentMethodOptions($productId = null)
    {
        $response = $this->paymentMethods($productId);
        if (empty($response['success']) || !is_array($response['data'])) {
            return [];
        }

        $methods = isset($response['data']['paymentMethods']) && is_array($response['data']['paymentMethods'])
            ? $response['data']['paymentMethods']
            : $response['data'];

        $options = [];
        foreach ((array) $methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $module = isset($method['module']) ? $method['module'] : null;
            if (!$module) {
                continue;
            }
            $options[(string) $module] = isset($method['displayName']) ? $method['displayName']
                : (isset($method['name']) ? $method['name'] : (string) $module);
        }

        return $options;
    }

    /**
     * Performs a request against the Order API and normalizes the response.
     *
     * @param string $endPoint Endpoint path relative to the API base
     * @param string $method HTTP verb
     * @param array $query Query-string parameters
     * @param array|null $jsonBody JSON request body (for POST/PUT/PATCH)
     * @param array $extraHeaders Additional raw headers
     * @return array { success, httpCode, data, errors }
     */
    private function callAPI($endPoint, $method = 'GET', $query = [], $jsonBody = null, $extraHeaders = [])
    {
        $response = ['success' => false, 'httpCode' => 0, 'data' => null, 'errors' => []];

        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;

            return $response;
        }

        $url = $this->apiUrl . ltrim($endPoint, '/');
        $query = is_array($query) ? $query : [];
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Accept: application/json',
        ];
        foreach ((array) $extraHeaders as $header) {
            $headers[] = $header;
        }

        $jsonRequest = '';
        if ($jsonBody !== null) {
            $jsonRequest = json_encode((object) $jsonBody);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($jsonRequest);
        } elseif ($method === 'POST') {
            $headers[] = 'Content-Length: 0';
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $this->applySslOptions($ch);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($jsonBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRequest);
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($jsonBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRequest);
            }
        }

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $response['httpCode'] = $httpCode;

        if ($curlError) {
            $response['errors'][] = 'Unable to reach the ReliableSite Order API. Please try again shortly.';
            $this->myLog($url, $jsonRequest, 'input', false);
            $this->myLog($url, json_encode(['curl_error' => $curlError]), 'output', false);

            return $response;
        }

        $parsed = json_decode($resp, true);
        $response['data'] = $parsed;
        $response['success'] = ($httpCode >= 200 && $httpCode < 300);

        if (!$response['success']) {
            $error = '';
            if (is_array($parsed)) {
                $error = isset($parsed['message']) ? $parsed['message']
                    : (isset($parsed['title']) ? $parsed['title'] : '');
            }
            if ($error === '') {
                if ($httpCode === 401 || $httpCode === 403) {
                    $error = 'Authentication failed. Please check the API key.';
                } else {
                    $error = 'ReliableSite Order API returned HTTP ' . $httpCode . '.';
                }
            }
            $response['errors'][] = $error;
            $this->myLog($url, $jsonRequest, 'input', false);
            $this->myLog($url, (string) $resp, 'output', false);

            return $response;
        }

        $this->myLog($url, $jsonRequest, 'input', true);

        return $response;
    }

    /**
     * Applies SSL verification options, honoring Blesta's curl_verify_ssl config.
     *
     * @param resource $ch
     */
    private function applySslOptions($ch)
    {
        $verify = false;
        if (class_exists('Configure')) {
            $verify = (bool) Configure::get('Blesta.curl_verify_ssl');
        }
        if ($verify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
    }

    /**
     * Proxies logging to the owning module when available.
     */
    private function myLog($url, $data, $direction, $success)
    {
        if ($this->moduleObj !== null && method_exists($this->moduleObj, 'myLog')) {
            $this->moduleObj->myLog($url, $data, $direction, $success);
        }
    }
}
