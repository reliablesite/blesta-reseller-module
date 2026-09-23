<?php
// Run with php -n: fake only the cURL boundary, exercise the production transport.
foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_CUSTOMREQUEST','CURLOPT_HTTPHEADER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_FOLLOWLOCATION','CURLOPT_PROTOCOLS','CURLOPT_VERBOSE','CURLOPT_POSTFIELDS','CURLPROTO_HTTPS','CURLINFO_HTTP_CODE','CURLINFO_CONTENT_TYPE'] as $i => $constant) { define($constant, $i + 1); }
$calls = [];
function curl_init($url) { global $calls; $calls[] = ['url' => $url, 'options' => []]; return count($calls) - 1; }
function curl_setopt_array($ch, $options) { global $calls; $calls[$ch]['options'] = $options; return true; }
function curl_exec($ch) { return json_encode($ch === 0 ? ['status' => true, 'message' => 'synthetic-jwt'] : ['status' => true, 'data' => ['server' => ['serverId' => 42, 'status' => 'Active', 'instantKvmAvailable' => true]]]); }
function curl_getinfo($ch, $type) { return $type === CURLINFO_HTTP_CODE ? 200 : 'application/json; charset=utf-8'; }
function curl_close($ch) {}
require dirname(__DIR__) . '/lib/reliablesite_instant_kvm.php';
try {
    $api = new ReliablesiteInstantKvm('synthetic key&', ['https://kvmproxy-ny1.reliablesite.net']);
    if (!$api->available(42) || count($calls) !== 2) { throw new Exception('Expected token and detail'); }
    if ($calls[0]['url'] !== 'https://dedicated-servers.reliablesite.dev/v2/Login/Token?ApiKey=synthetic%20key%26') { throw new Exception('Token query'); }
    foreach ($calls as $call) {
        $o = $call['options'];
        foreach ([CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_VERBOSE => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20] as $key => $value) { if (($o[$key] ?? null) !== $value) { throw new Exception('Unsafe transport option ' . $key); } }
        if (isset($o[CURLOPT_POSTFIELDS])) { throw new Exception('Request must have no body'); }
    }
    if ($calls[0]['options'][CURLOPT_CUSTOMREQUEST] !== 'POST' || $calls[1]['options'][CURLOPT_CUSTOMREQUEST] !== 'GET' || !in_array('Authorization: Bearer synthetic-jwt', $calls[1]['options'][CURLOPT_HTTPHEADER], true)) { throw new Exception('Method/auth'); }
    echo "PASS isolated transport: TLS, no redirects, no body, timeouts, exact token query, Bearer\n";
} catch (Throwable $e) { echo 'FAIL transport: ' . $e->getMessage() . "\n"; exit(1); }
