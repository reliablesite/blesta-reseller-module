<?php
/** Isolated Instant KVM transport: never use the legacy request logger. */
class ReliablesiteInstantKvm
{
    private $apiKey;
    private $origins;
    private $transport;
    private $token = '';

    public function __construct($apiKey, array $origins, ?callable $transport = null)
    {
        $this->apiKey = $apiKey;
        $this->origins = $origins;
        $this->transport = $transport ?? [$this, 'send'];
    }

    private function send($method, $path, $token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Instant KVM requires cURL.');
        }
        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($method === 'POST') {
            $headers[] = 'Content-Length: 0';
        }
        $ch = curl_init('https://dedicated-servers.reliablesite.dev/v2' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_VERBOSE => false,
        ]);
        $body = curl_exec($ch);
        $response = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'body' => is_string($body) ? $body : ''];
        curl_close($ch);
        return $response;
    }

    private function request($method, $path)
    {
        // Do not include paths, raw provider errors or response bodies in exceptions.
        try {
            $response = call_user_func($this->transport, $method, $path, $this->token);
        } catch (Throwable $e) {
            throw new RuntimeException('Instant KVM request failed. Please request a fresh launch.');
        }
        $data = json_decode($response['body'] ?? '', true);
        if (($response['code'] ?? 0) !== 200
            || !preg_match('~^application/json(?:\\s*;|$)~i', $response['type'] ?? '')
            || !is_array($data) || ($data['status'] ?? null) !== true) {
            throw new RuntimeException('Instant KVM request failed. Please request a fresh launch.');
        }
        return $data;
    }

    public static function serverId($value)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)
            || (float) $value > 2147483647) {
            throw new RuntimeException('Instant KVM is unavailable for this server.');
        }
        return (string) $value;
    }

    /** Trusted proxies must overwrite X-Forwarded-For with one public literal IP. */
    public static function sourceIp(array $request, array $trustedProxies)
    {
        $remote = $request['REMOTE_ADDR'] ?? '';
        if (is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) && in_array($remote, $trustedProxies, true)) {
            return self::publicIp($request['HTTP_X_FORWARDED_FOR'] ?? '');
        }
        return self::publicIp($remote);
    }

    private static function publicIp($value)
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('A public browser IP address is required for Instant KVM.');
        }
        $packed = inet_pton($value);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            self::publicIp(inet_ntop(substr($packed, 12)));
        } elseif ((strlen($packed) === 4 && (ord($packed[0]) >= 224
                || (ord($packed[0]) === 100 && ord($packed[1]) >= 64 && ord($packed[1]) <= 127)))
            || (strlen($packed) === 16 && ord($packed[0]) === 255)
            || (defined('FILTER_FLAG_GLOBAL_RANGE') && !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))) {
            throw new RuntimeException('A public browser IP address is required for Instant KVM.');
        }
        return $value;
    }

    public function available($serverId)
    {
        $serverId = self::serverId($serverId);
        if ($this->token === '') {
            $auth = $this->request('POST', '/Login/Token?ApiKey=' . rawurlencode($this->apiKey));
            if (!isset($auth['message']) || !is_string($auth['message']) || $auth['message'] === ''
                || preg_match('/[\\x00-\\x20\\x7f]/', $auth['message'])) {
                throw new RuntimeException('Instant KVM authentication failed.');
            }
            $this->token = $auth['message'];
        }
        $response = $this->request('GET', '/Server/' . $serverId);
        $server = $response['data']['server'] ?? [];
        return isset($server['serverId']) && self::serverId($server['serverId']) === $serverId
            && isset($server['status']) && is_string($server['status']) && strtolower($server['status']) === 'active'
            && ($server['instantKvmAvailable'] ?? null) === true;
    }

    public function launch($serverId, $sourceIp)
    {
        $serverId = self::serverId($serverId);
        $sourceIp = self::publicIp($sourceIp);
        if (!$this->available($serverId)) {
            throw new RuntimeException('Instant KVM is unavailable for this server.');
        }
        $response = $this->request('POST', '/Server/' . $serverId . '/LaunchInstantKVM?sourceIP=' . rawurlencode($sourceIp));
        $url = $response['data']['JavascriptUrl'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        $origin = $parts ? ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '') : '';
        if (!$parts || !filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $url)
            || ($parts['scheme'] ?? '') !== 'https' || !in_array($origin, $this->origins, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '/embed/v1/kvm.js') {
            throw new RuntimeException('The Instant KVM provider returned an unapproved script URL.');
        }
        return $url;
    }
}
