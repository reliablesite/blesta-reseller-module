<?php
/**
 * ReliableSite REST API client.
 *
 * Clean rewrite of the legacy include/reliableSiteAPI.php. Preserves the
 * { success, data, errors } response envelope and the public method names used
 * by the module's admin screens and client tabs, while fixing the v1.7.0 bugs:
 *
 *   - SSL verification now honors Blesta's Blesta.curl_verify_ssl setting
 *     instead of being hard-disabled.
 *   - Robust rate-limit / non-JSON / HTTP-error handling.
 *   - Adds the DDoS Profiles endpoints and the JSON inventory-widget catalog
 *     (replacing the removed WSDL/SOAP Inventory API).
 *
 * @package reliablesite.lib
 */
class ReliablesiteApi
{
    /** @var string Bearer token used for authenticated calls */
    private $apiToken = '';

    /** @var string Base URL of the ReliableSite v2 REST API */
    private $apiUrl = 'https://dedicated-servers.reliablesite.dev/v2/';

    /** @var string Base URL of the public inventory-widget catalog */
    private $catalogUrl = 'https://inventory-widget.reliablesite.net/';

    /** @var array Pre-flight errors (e.g. missing/invalid token); short-circuits calls */
    private $errors = [];

    /** @var object|null The owning module (used for logging) */
    private $moduleObj = null;

    /**
     * @param string $apiToken A valid bearer token
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

    // -----------------------------------------------------------------
    // Account
    // -----------------------------------------------------------------

    public function GetProfile()
    {
        return $this->callAPI('Account/GetProfile');
    }

    // -----------------------------------------------------------------
    // Reseller customers
    // -----------------------------------------------------------------

    public function getCustomers($page = 1, $search = '', $filterField = '')
    {
        $search = trim($search);
        $filterField = trim($filterField);
        $request = ['page' => $page];
        if (!empty($search)) {
            $request['search'] = $search;
        }
        if (!empty($filterField)) {
            $request['filterField'] = $filterField;
        }

        return $this->callAPI('Reseller/GetCustomers', $request);
    }

    /**
     * Finds a single customer by exact (case-insensitive) email match.
     *
     * @param string $email
     * @return array|null The matching customer row, or null
     */
    public function findCustomerByEmail($email)
    {
        $response = $this->getCustomers(1, $email, 'emailAddress');
        if (empty($response['success'])) {
            return null;
        }
        $rows = $this->extractList($response['data']);
        foreach ($rows as $row) {
            $candidate = isset($row['emailAddress'])
                ? $row['emailAddress']
                : (isset($row['email']) ? $row['email'] : '');
            if (strcasecmp((string) $candidate, (string) $email) === 0) {
                return $row;
            }
        }

        return null;
    }

    public function addCustomer($username, $email, $password)
    {
        return $this->callAPI('Reseller/AddCustomer', [
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ], 'POST');
    }

    public function updateCustomer($username, $email = '', $password = '')
    {
        $request = ['username' => $username];
        if (!empty($email)) {
            $request['email'] = $email;
        }
        if (!empty($password)) {
            $request['password'] = $password;
        }

        return $this->callAPI('Reseller/UpdateCustomer', $request, 'POST');
    }

    public function deleteCustomer($username)
    {
        return $this->callAPI('Reseller/DeleteCustomer', ['username' => $username], 'DELETE');
    }

    public function assignServer($userName, $serverId)
    {
        return $this->callAPI('Reseller/AssignServer', [
            'username' => $userName,
            'serverId' => $serverId,
        ], 'POST');
    }

    public function unassignServer($serverId)
    {
        return $this->callAPI('Reseller/UnassignServer', ['serverId' => $serverId], 'POST');
    }

    // -----------------------------------------------------------------
    // Servers
    // -----------------------------------------------------------------

    public function getServers($page, $showReassignServer = 'true', $search = '', $filterField = '')
    {
        $request = [
            'page' => $page,
            'showReassignServer' => $showReassignServer,
        ];
        if (!empty($search)) {
            $request['search'] = $search;
        }
        if (!empty($filterField)) {
            $request['filterField'] = $filterField;
        }

        return $this->callAPI('Server/GetServers', $request);
    }

    public function getServerDetails($serverId)
    {
        return $this->callAPI('Server/' . $serverId);
    }

    public function ServerPoweOn($serverId)
    {
        return $this->callAPI('Server/' . $serverId . '/PowerOn', [], 'POST');
    }

    public function ServerPoweOff($serverId)
    {
        return $this->callAPI('Server/' . $serverId . '/PowerOff', [], 'POST');
    }

    public function getKVMDetails($serverId)
    {
        return $this->callAPI('Server/GetKVMDetails/' . $serverId);
    }

    public function ServerEnableKVM($serverId, $remoteIP)
    {
        return $this->callAPI('Server/' . $serverId . '/EnableKVM', ['remoteIP' => $remoteIP], 'POST');
    }

    public function ServerDisableKVM($serverId)
    {
        return $this->callAPI('Server/' . $serverId . '/DisableKVM', [], 'POST');
    }

    public function GetIPMISessions($serverId)
    {
        return $this->callAPI('Server/GetIPMISessions', [
            'page' => 1,
            'filterField' => 'ServerId',
            'search' => $serverId,
        ]);
    }

    public function SetMacAddess($serverId, $ip, $isCustomMacEnable, $customMac)
    {
        return $this->callAPI('Server/' . $serverId . '/SetMacAddress', [
            'ip' => $ip,
            'isCustomMacEnable' => $isCustomMacEnable,
            'customMac' => $customMac,
        ], 'POST');
    }

    public function GetCompatibleOS($serverId)
    {
        return $this->callAPI('Server/' . $serverId . '/GetOSInstallCompatibleOS');
    }

    public function GetPartitioningSchemes($operatingSystemId)
    {
        return $this->callAPI('Server/' . $operatingSystemId . '/GetCompatiblePartitioningSchemes');
    }

    public function OSInstallStart($serverId, $serverIP, $OSId, $partitioningSchemeId, $licenseKey = '')
    {
        $request = [
            'serverIp' => $serverIP,
            'operatingSystemId' => $OSId,
            'partitioningSchemeId' => $partitioningSchemeId,
        ];
        if (!empty($licenseKey)) {
            $request['licenseKey'] = $licenseKey;
        }

        return $this->callAPI('Server/' . $serverId . '/OSInstallStart', $request, 'POST');
    }

    public function OSInstallCancel($serverId)
    {
        return $this->callAPI('Server/' . $serverId . '/OSInstallCancel', [], 'DELETE');
    }

    public function GetOSInstallStatus($serverId)
    {
        return $this->callAPI('Server/' . $serverId . '/OSInstallStatus');
    }

    public function GetBandwidthGraph($serverId, $period = 'Hour', $timeZone = '')
    {
        $request = [];
        if (!empty($period)) {
            $request['period'] = $period;
        }
        if (!empty($timeZone)) {
            $request['timeZone'] = $timeZone;
        }

        return $this->callAPI('Server/' . $serverId . '/BandwidthGraph', $request, 'GET');
    }

    // -----------------------------------------------------------------
    // Backup storage
    // -----------------------------------------------------------------

    public function GetCustomerBackupStorage($serverId)
    {
        return $this->callAPI('Backup/GetCustomerBackupStorage', [
            'page' => 1,
            'filterField' => 'serverId',
            'search' => $serverId,
        ]);
    }

    public function GetBackupStorageDetails($backupSpaceId)
    {
        return $this->callAPI('Backup/' . $backupSpaceId);
    }

    public function SetFTPAccount($ftpAccountId, $enable, $password)
    {
        return $this->callAPI('Backup/SetFTPAccount', [
            'ftpAccountId' => $ftpAccountId,
            'enable' => $enable,
            'password' => $password,
        ], 'POST');
    }

    // -----------------------------------------------------------------
    // Reverse DNS
    // -----------------------------------------------------------------

    public function getReverseDNSIPs($ipAddressId)
    {
        return $this->callAPI('rDNS/' . $ipAddressId . '/GetIPs', []);
    }

    public function getReverseDNSRecord($ipAddressId, $ipAddress)
    {
        return $this->callAPI('rDNS/' . $ipAddressId . '/rDNSRecord', ['ipAddress' => $ipAddress]);
    }

    public function setReverseDNSRecord($ipAddressId, $ipAddress, $rDNS)
    {
        return $this->callAPI('rDNS/' . $ipAddressId . '/rDNSRecord', [
            'ipAddress' => $ipAddress,
            'rDNS' => $rDNS,
        ], 'POST');
    }

    // -----------------------------------------------------------------
    // Null routes
    // -----------------------------------------------------------------

    public function getNullRoutes($page = 1, $searchIp = '', $onlyActive = 'false')
    {
        $request = ['page' => $page];
        if (!empty($searchIp)) {
            $request['searchIP'] = $searchIp;
        }
        if (!empty($onlyActive)) {
            $request['onlyActive'] = $onlyActive;
        }

        return $this->callAPI('NullRoutes/GetNullRoutes', $request);
    }

    public function AddNullRoute($IPAddress, $scheduledRemove, $resellerLock)
    {
        return $this->callAPI('NullRoutes/AddNullRoute', [
            'nullRouteIP' => $IPAddress,
            'scheduledRemove' => $scheduledRemove,
            'resellerLock' => $resellerLock,
        ], 'POST', true);
    }

    public function RemoveNullRoute($IPAddress)
    {
        return $this->callAPI('NullRoutes/RemoveNullRoute', ['IPAddress' => $IPAddress], 'DELETE');
    }

    // -----------------------------------------------------------------
    // DDoS
    // -----------------------------------------------------------------

    public function GetDDoSAttacks($page = 1, $searchIp = '')
    {
        $request = ['page' => $page];
        if (!empty($searchIp)) {
            $request['searchIP'] = $searchIp;
        }

        return $this->callAPI('DDoS/GetDDoSAttacks', $request);
    }

    /**
     * Lists all DDoS protection profiles available to the reseller account.
     * Response data is shaped as { profiles: [ { profile, ips, dataSourceId,
     * dataCenterName } ] }.
     */
    public function ddosProfiles()
    {
        return $this->callAPI('DDoS/Profiles');
    }

    /**
     * Assigns an IP or CIDR to a DDoS profile. This endpoint expects a JSON body
     * (unlike most endpoints which take query-string params).
     */
    public function assignDdosProfileIp($dataSourceId, $profileId, $ipInput)
    {
        return $this->callAPI(
            'DDoS/Profiles/' . $dataSourceId . '/' . $profileId . '/AssignIp',
            ['ipInput' => $ipInput],
            'POST',
            true
        );
    }

    public function removeDdosProfileIp($dataSourceId, $profileId, $ipId)
    {
        return $this->callAPI(
            'DDoS/Profiles/' . $dataSourceId . '/' . $profileId . '/Ips/' . $ipId,
            [],
            'DELETE'
        );
    }

    // -----------------------------------------------------------------
    // Inventory-widget catalog (replaces the legacy WSDL Inventory API)
    // -----------------------------------------------------------------

    /**
     * Fetches the full product catalog from the public inventory widget.
     * Resolves full-latest.json -> latest_hash -> full-{hash}.json.
     *
     * @return array { success, data: { hash, products: [...] }, errors }
     */
    public function catalogProducts()
    {
        $manifest = $this->fetchCatalogJson('full-latest.json');
        if (empty($manifest['success'])) {
            return $manifest;
        }

        $hash = isset($manifest['data']['latest_hash']) ? $manifest['data']['latest_hash'] : null;
        if (!$hash) {
            return ['success' => false, 'data' => '', 'errors' => ['Catalog manifest missing latest_hash']];
        }

        $products = $this->fetchCatalogJson('full-' . $hash . '.json');
        if (empty($products['success'])) {
            return $products;
        }

        $list = is_array($products['data']) ? $products['data'] : [];
        // Some manifests wrap the list under a "products" key.
        if (isset($list['products']) && is_array($list['products'])) {
            $list = $list['products'];
        }

        return [
            'success' => true,
            'data' => ['hash' => $hash, 'products' => $list],
            'errors' => [],
        ];
    }

    /**
     * Fetches the full configurable-options catalog from the public inventory
     * widget. Resolves full-options-latest.json -> latest_hash ->
     * full-options-{hash}.json.
     *
     * The feed exposes every option group globally; which groups apply to a
     * given product is decided by {@see ReliablesiteInventoryOptions}.
     *
     * @return array { success, data: { hash, groups }, errors }
     */
    public function inventoryOptions()
    {
        $manifest = $this->fetchCatalogJson('full-options-latest.json');
        if (empty($manifest['success'])) {
            return $manifest;
        }

        $hash = isset($manifest['data']['latest_hash']) ? $manifest['data']['latest_hash'] : null;
        if (!$hash) {
            return ['success' => false, 'data' => '', 'errors' => ['Options manifest missing latest_hash']];
        }

        $options = $this->fetchCatalogJson('full-options-' . $hash . '.json');
        if (empty($options['success'])) {
            return $options;
        }

        $groups = is_array($options['data']) ? $options['data'] : [];
        // Some manifests wrap the groups under a "groups" key.
        if (isset($groups['groups']) && is_array($groups['groups'])) {
            $groups = $groups['groups'];
        }

        return [
            'success' => true,
            'data' => ['hash' => $hash, 'groups' => $groups],
            'errors' => [],
        ];
    }

    /**
     * Fetches and decodes an unauthenticated JSON document from the catalog host.
     *
     * @param string $path
     * @return array { success, data, errors }
     */
    private function fetchCatalogJson($path)
    {
        $url = $this->catalogUrl . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $this->applySslOptions($ch);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->myLog($url, $curlError, 'output', false);

            return ['success' => false, 'data' => '', 'errors' => ['Catalog fetch failed: ' . $curlError]];
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            $this->myLog($url, (string) $resp, 'output', false);

            return [
                'success' => false,
                'data' => '',
                'errors' => ['Invalid catalog response from ' . $path . ' (HTTP ' . $httpCode . ')'],
            ];
        }

        return ['success' => true, 'data' => $data, 'errors' => []];
    }

    /**
     * Normalizes a paginated response payload to a flat list of rows.
     *
     * @param mixed $data The "data" portion of a response
     * @return array
     */
    public function extractList($data)
    {
        if (!is_array($data)) {
            return [];
        }
        foreach (['records', 'items', 'list', 'results'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }
        // A bare list (sequential keys) is already what we want.
        if (array_keys($data) === range(0, count($data) - 1)) {
            return $data;
        }

        return [];
    }

    /**
     * Performs an authenticated REST call and normalizes the response.
     *
     * @param string $endPoint Endpoint path relative to the API base
     * @param array $request Parameters (query string, or JSON body when $json)
     * @param string $method HTTP verb
     * @param bool $json When true, send params as a JSON request body
     * @return array { success, data, errors }
     */
    private function callAPI($endPoint, $request = [], $method = 'GET', $json = false)
    {
        $response = ['success' => false, 'data' => '', 'errors' => []];

        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;

            return $response;
        }

        $url = $this->apiUrl . $endPoint;
        $headers = ['Authorization: Bearer ' . $this->apiToken];

        $jsonRequest = '';
        if ($json && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $headers[] = 'Content-Type: application/json';
            $jsonRequest = json_encode($request);
            $headers[] = 'Content-Length: ' . strlen($jsonRequest);
        } elseif ($method === 'POST') {
            // Query-string POST: the API reads params from the URL, not the body.
            $headers[] = 'Content-Length: 0';
        }

        if (!$json) {
            $query = http_build_query($request);
            if ($query !== '') {
                $url .= '?' . $query;
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $this->applySslOptions($ch);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($json) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRequest);
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($json) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRequest);
            }
        }

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $response['errors'][] = $curlError;
            $this->myLog($url, $jsonRequest, 'input', false);
            $this->myLog($url, json_encode(['curl_error' => $curlError]), 'output', false);

            return $response;
        }

        $parsedResponse = json_decode($resp, true);

        if (!is_array($parsedResponse)) {
            $error = 'Unexpected response (HTTP ' . $httpCode . ')';
            if ($httpCode === 405) {
                $error = 'Method Not Allowed';
            } elseif ($httpCode === 429 || stripos((string) $resp, 'rate limit') !== false
                || stripos((string) $resp, '1015') !== false) {
                $error = "You're going too quickly and triggered our rate limit. Please try again shortly.";
            } elseif ($httpCode === 401 || $httpCode === 403) {
                $error = 'Authentication failed. Please check the API key.';
            } elseif (is_string($resp) && $resp !== '' && strlen($resp) < 500) {
                $error = 'ReliableSite API error (HTTP ' . $httpCode . '): ' . trim($resp);
            }
            $response['errors'][] = $error;
            $this->myLog($url, $jsonRequest, 'input', false);
            $this->myLog($url, (string) $resp, 'output', false);

            return $response;
        }

        if (isset($parsedResponse['status']) && (int) $parsedResponse['status'] === 1) {
            $response['success'] = true;
            $response['data'] = isset($parsedResponse['data']) ? $parsedResponse['data'] : '';

            // Some success responses carry their payload in "message" instead of "data".
            if ((!is_array($response['data']) && $response['data'] === '')
                && !empty($parsedResponse['message'])
                && $parsedResponse['message'] !== 'Successful') {
                $response['data'] = $parsedResponse['message'];
            }
            $this->myLog($url, $jsonRequest, 'input', true);

            return $response;
        }

        $error = '';
        if (isset($parsedResponse['title'])) {
            $error = $parsedResponse['title'];
        } elseif (isset($parsedResponse['message'])) {
            $error = $parsedResponse['message'];
        } else {
            $error = 'ReliableSite API error';
        }
        $response['errors'][] = $error;
        $this->myLog($url, $jsonRequest, 'input', false);
        $this->myLog($url, json_encode($parsedResponse), 'output', false);

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
