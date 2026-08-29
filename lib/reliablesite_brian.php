<?php
/**
 * Brian AI chat client and white-label configuration helpers.
 *
 * Brian is reachable through two upstream endpoints, and this module talks to
 * both:
 *
 *   /api/web-chat-reslr             Reseller (staff) sales engineer. Answers as
 *                                   ReliableSite, from the central catalog. This
 *                                   is what the admin "Ask Brian" screen uses.
 *
 *   /api/reseller-chat-whitelabel   Customer-facing assistant. Answers as the
 *                                   reseller's own brand, from the reseller's
 *                                   Blesta catalog, and links into the
 *                                   reseller's own order form. This is what the
 *                                   public api.php?action=chat endpoint proxies
 *                                   to, so the reseller's website can embed a
 *                                   chat that is entirely theirs.
 *
 * Both carry the reseller API key in an x-api-key header, which is why neither
 * is ever called from a browser: the module proxies every turn server-side so
 * the key stays on the Blesta host.
 *
 * This class is deliberately free of Blesta base classes so api.php - which
 * bootstraps the framework by hand and never instantiates the module - can load
 * and use it exactly as the module does.
 *
 * @package reliablesite.lib
 */
class ReliablesiteBrian
{
    /** @var string Reseller (staff) sales-engineer chat */
    const RESELLER_CHAT_URL = 'https://api-brian.reliablesite.net/api/web-chat-reslr';

    /** @var string White-label (customer-facing) reseller chat */
    const WHITELABEL_CHAT_URL = 'https://api-brian.reliablesite.net/api/reseller-chat-whitelabel';

    /** @var string The billing platform this module reports upstream */
    const BILLING_PLATFORM = 'blesta';

    /** @var int Longest message the service accepts */
    const MAX_MESSAGE_LENGTH = 2000;

    /** @var int Default per-visitor message allowance */
    const DEFAULT_RATE_LIMIT = 20;

    /** @var int Default per-visitor window, in seconds */
    const DEFAULT_RATE_WINDOW = 600;

    /** @var string Reseller API key, sent as x-api-key */
    private $apiKey = '';

    /** @var object|null Anything exposing myLog($url, $data, $direction, $success) */
    private $logger = null;

    /** @var int Request timeout in seconds */
    private $timeout = 60;

    /**
     * @param string $apiKey The reseller API key
     * @param object $logger Optional logger exposing myLog()
     */
    public function __construct($apiKey, $logger = null)
    {
        $this->apiKey = (string) $apiKey;
        $this->logger = $logger;
    }

    /**
     * @param int $seconds Request timeout
     */
    public function setTimeout($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds > 0) {
            $this->timeout = $seconds;
        }
    }

    // -----------------------------------------------------------------
    // Chat
    // -----------------------------------------------------------------

    /**
     * One turn of the reseller (staff) chat.
     *
     * @param string $message
     * @param string $sessionId
     * @param string $assignmentId
     * @param string $clientIp
     * @return array See post()
     */
    public function sendReseller($message, $sessionId, $assignmentId, $clientIp = '')
    {
        $body = ['message' => $message];
        if ($sessionId !== '') {
            $body['sessionId'] = $sessionId;
        }
        if ($assignmentId !== '') {
            $body['assignmentId'] = $assignmentId;
        }

        return $this->post(self::RESELLER_CHAT_URL, $body, $clientIp);
    }

    /**
     * One turn of the white-label (customer-facing) chat.
     *
     * The caller supplies the whole body because the reseller block is built
     * from stored settings plus the live request; see buildWhiteLabelBody().
     *
     * @param array $body
     * @param string $clientIp
     * @return array See post()
     */
    public function sendWhiteLabel(array $body, $clientIp = '')
    {
        return $this->post(self::WHITELABEL_CHAT_URL, $body, $clientIp);
    }

    /**
     * Assembles the white-label request body.
     *
     * `reseller.catalog` is additive to what the upstream endpoint documents.
     * The documented contract derives the catalog feeds from billingBaseUrl,
     * which works for WHMCS because its feed paths are fixed. Blesta has no
     * query-string front controller, so its feeds live at a path that depends on
     * the install directory and on the module folder name - not derivable from
     * the base URL alone. Sending them explicitly means the service never has to
     * guess, and a consumer that ignores the block loses nothing it had before.
     *
     * @param array $config From whiteLabelConfig()
     * @param string $baseUrl Public base URL of this Blesta install (trailing slash)
     * @param string $apiPath Path of this module's api.php, relative to $baseUrl
     * @param array $turn message / sessionId / assignmentId / email
     * @return array
     */
    public static function buildWhiteLabelBody(array $config, $baseUrl, $apiPath, array $turn)
    {
        $endpoint = rtrim($baseUrl, '/') . '/' . ltrim($apiPath, '/');

        $reseller = [
            'agentName' => $config['agent_name'],
            'companyName' => $config['company_name'],
            'billingPlatform' => self::BILLING_PLATFORM,
            'billingBaseUrl' => rtrim($baseUrl, '/'),
            'catalog' => [
                'pricingUrl' => $endpoint . '?action=pricing',
                'currenciesUrl' => $endpoint . '?action=currencies',
                'checkoutUrlTemplate' => $endpoint . '?action=widget&id={inventory_id}&currency={currency}',
            ],
        ];
        if ($config['currency'] !== '') {
            $reseller['currency'] = $config['currency'];
        }

        $body = ['message' => $turn['message'], 'reseller' => $reseller];
        foreach (['sessionId', 'assignmentId', 'email'] as $key) {
            if (isset($turn[$key]) && $turn[$key] !== '') {
                $body[$key] = $turn[$key];
            }
        }

        return $body;
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    /**
     * Normalizes the Brian settings held in module row meta, applying defaults
     * so callers never have to.
     *
     * @param object|array|null $meta Module row meta
     * @return array
     */
    public static function whiteLabelConfig($meta)
    {
        $get = function ($key, $default = '') use ($meta) {
            if (is_array($meta)) {
                return isset($meta[$key]) ? $meta[$key] : $default;
            }
            if (is_object($meta)) {
                return isset($meta->{$key}) ? $meta->{$key} : $default;
            }

            return $default;
        };

        $limit = (int) $get('brian_rate_limit', 0);
        $window = (int) $get('brian_rate_window', 0);

        return [
            'enabled' => ((string) $get('brian_chat_enabled') === '1'),
            'agent_name' => trim((string) $get('brian_agent_name')),
            'company_name' => trim((string) $get('brian_company_name')),
            'currency' => strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $get('brian_currency'))),
            'allowed_origins' => self::parseAllowedOrigins($get('brian_allowed_origins')),
            'rate_limit' => $limit > 0 ? $limit : self::DEFAULT_RATE_LIMIT,
            'rate_window' => $window > 0 ? $window : self::DEFAULT_RATE_WINDOW,
            'daily_cap' => max(0, (int) $get('brian_daily_cap', 0)),
            'behind_proxy' => ((string) $get('brian_behind_proxy') === '1'),
            'base_url' => rtrim(trim((string) $get('brian_public_base_url')), '/'),
        ];
    }

    /**
     * Lists the reasons the white-label endpoint cannot serve traffic yet, as
     * display-ready sentences for the admin screen. Empty means ready.
     *
     * @param array $config From whiteLabelConfig()
     * @param string $apiKey The module's reseller API key
     * @param string $baseUrl The public base URL the module would report upstream
     * @return array
     */
    public static function whiteLabelProblems(array $config, $apiKey, $baseUrl)
    {
        $problems = [];

        if (trim((string) $apiKey) === '') {
            $problems[] = 'No ReliableSite API key is configured. Set one under Settings.';
        }
        if ($config['agent_name'] === '' || $config['company_name'] === '') {
            $problems[] = 'The assistant name and your company name are both required.';
        }
        if (empty($config['allowed_origins'])) {
            $problems[] = 'No allowed websites are listed, so browser requests will be refused.';
        }
        if (stripos((string) $baseUrl, 'https://') !== 0) {
            $problems[] = 'This install is not reachable over https://. The assistant reads your'
                . ' catalog over the public internet and will quote no prices without it.';
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    // Origins
    // -----------------------------------------------------------------

    /**
     * Parses the admin's allowed-websites list into comparable hostnames.
     *
     * Accepts one entry per line (or comma separated), written as a full URL, a
     * bare hostname, or a *.example.com wildcard - admins write all three and
     * all three mean the same thing here.
     *
     * @param string $text
     * @return array Lowercase hostname patterns
     */
    public static function parseAllowedOrigins($text)
    {
        $out = [];
        foreach (preg_split('/[\r\n,]+/', (string) $text) as $line) {
            $line = strtolower(trim($line));
            if ($line === '') {
                continue;
            }
            if (strpos($line, '//') !== false) {
                $host = parse_url($line, PHP_URL_HOST);
                if ($host) {
                    $line = $host;
                }
            }
            // Trailing path, then port: an admin pasting a browser URL gets both.
            $line = preg_replace('#/.*$#', '', $line);
            $line = preg_replace('/:\d+$/', '', $line);
            if ($line !== '' && preg_match('/^[a-z0-9.*\-]+$/', $line)) {
                $out[$line] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Whether a browser Origin header is covered by the allowed list.
     *
     * @param string $origin The raw Origin header (non-empty)
     * @param array $allowed From parseAllowedOrigins()
     * @return bool
     */
    public static function originAllowed($origin, array $allowed)
    {
        $host = parse_url((string) $origin, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $host = strtolower($host);

        foreach ($allowed as $pattern) {
            if ($pattern === '*') {
                return true;
            }
            if (strpos($pattern, '*.') === 0) {
                $base = substr($pattern, 2);
                $suffix = '.' . $base;
                // *.example.com covers example.com itself, matching how admins
                // read it and how the WHMCS panel behaves.
                if ($host === $base
                    || (strlen($host) > strlen($suffix)
                        && substr($host, -strlen($suffix)) === $suffix)) {
                    return true;
                }
                continue;
            }
            if ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Session ids
    // -----------------------------------------------------------------

    /**
     * A stable, non-reversible id for this install, so conversations from one
     * reseller group together upstream without exposing the API key.
     *
     * @param string $host
     * @param string $apiKey
     * @return string 12 hex characters
     */
    public static function installToken($host, $apiKey)
    {
        return substr(sha1(strtolower((string) $host) . '|' . (string) $apiKey), 0, 12);
    }

    /**
     * A fresh white-label session id: an install-identifying prefix plus a
     * random tail.
     *
     * The tail matters. The prefix alone would be identical for every visitor to
     * the same site, so they would all share one conversation history.
     *
     * @param string $host
     * @param string $apiKey
     * @return string
     */
    public static function whiteLabelSessionId($host, $apiKey)
    {
        return 'web-res-blesta-wl-' . self::installToken($host, $apiKey) . '-' . self::randomHex(12);
    }

    /**
     * Whether a client-supplied session or assignment id is safe to forward.
     *
     * @param string $id
     * @return bool
     */
    public static function validId($id)
    {
        return (bool) preg_match('/^[A-Za-z0-9._:\-]{1,190}$/', (string) $id);
    }

    /**
     * @param int $length Number of hex characters to return
     * @return string
     */
    private static function randomHex($length)
    {
        $length = max(1, (int) $length);

        if (function_exists('random_bytes')) {
            try {
                return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
            } catch (Exception $e) {
                // Fall through to the weaker source below.
            }
        }

        return substr(sha1(uniqid((string) mt_rand(), true)), 0, $length);
    }

    // -----------------------------------------------------------------
    // Transport
    // -----------------------------------------------------------------

    /**
     * POSTs one JSON body to Brian.
     *
     * @param string $url
     * @param array $body
     * @param string $clientIp Forwarded so upstream rate limiting sees the
     *  visitor rather than this server
     * @return array {
     *   ok bool          2xx with a truthy success field
     *   status int       HTTP status, 0 when the request never completed
     *   body array|null  Decoded JSON, null when the response was not JSON
     *   error string     Transport error message, '' when the request completed
     *   timed_out bool   True when the failure was a timeout
     *   raw string       Raw response body
     * }
     */
    private function post($url, array $body, $clientIp = '')
    {
        $result = [
            'ok' => false,
            'status' => 0,
            'body' => null,
            'error' => '',
            'timed_out' => false,
            'raw' => '',
        ];

        if (!function_exists('curl_init')) {
            $result['error'] = 'The cURL extension is not available.';

            return $result;
        }

        $payload = json_encode($body);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . $this->apiKey,
        ];
        if ($clientIp !== '') {
            $headers[] = 'x-client-ip: ' . $clientIp;
        }

        $this->log($url, $payload, 'input', true);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        $verify = class_exists('Configure') ? (bool) Configure::get('Blesta.curl_verify_ssl') : false;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $result['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result['raw'] = is_string($raw) ? $raw : '';

        if ($errno !== 0) {
            $result['error'] = $error;
            // 28 is CURLE_OPERATION_TIMEDOUT; the constant is spelled
            // inconsistently across PHP versions, so match on the number.
            $result['timed_out'] = ($errno === 28);
            $this->log($url, ($result['raw'] !== '' ? $result['raw'] : $error), 'output', false);

            return $result;
        }

        $parsed = json_decode($result['raw'], true);
        $result['body'] = is_array($parsed) ? $parsed : null;
        $result['ok'] = ($result['status'] >= 200 && $result['status'] < 300
            && is_array($parsed) && !empty($parsed['success']));

        $this->log($url, $result['raw'], 'output', $result['ok']);

        return $result;
    }

    /**
     * Writes to the module log when a logger was supplied, with the API key
     * redacted in case it ever appears in a payload.
     *
     * @param string $url
     * @param string $data
     * @param string $direction
     * @param bool $success
     */
    private function log($url, $data, $direction, $success)
    {
        if ($this->logger === null || !method_exists($this->logger, 'myLog')) {
            return;
        }

        $data = (string) $data;
        if ($this->apiKey !== '') {
            $data = str_replace($this->apiKey, '[redacted]', $data);
        }

        try {
            $this->logger->myLog($url, $data, $direction, $success);
        } catch (Exception $e) {
            // Logging must never break a chat turn.
        }
    }
}
