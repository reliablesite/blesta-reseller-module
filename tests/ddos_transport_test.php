<?php
// php -n tests/ddos_transport_test.php; replace cURL only, never contact provider.
foreach (['CURLOPT_HTTPHEADER','CURLOPT_RETURNTRANSFER','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_CUSTOMREQUEST','CURLINFO_HTTP_CODE'] as $index => $name) define($name, $index + 1);
$requests = []; $fixture = ['code' => 200, 'body' => '', 'error' => ''];
if (!function_exists('curl_init')) {
function curl_init($url) { global $requests; $requests[] = ['url' => $url, 'options' => []]; return count($requests) - 1; }
function curl_setopt($handle, $key, $value) { global $requests; $requests[$handle]['options'][$key] = $value; }
function curl_exec($handle) { global $fixture; return $fixture['body']; }
function curl_getinfo($handle, $type) { global $fixture; return $fixture['code']; }
function curl_error($handle) { global $fixture; return $fixture['error']; }
function curl_close($handle) {}
}
require dirname(__DIR__) . '/lib/reliablesite_api.php';
$api = new ReliablesiteApi('synthetic');
function requireCondition($truth, $message) { if (!$truth) throw new Exception($message); }
$fixture = ['code' => 200, 'body' => '{"status":false,"message":"already assigned","data":null}', 'error' => ''];
$r = $api->assignDdosProfileIp('loc/a%2F+ ü', '001/a%+ü', '203.0.113.10');
requireCondition(!$r['success'] && $r['errors'][0] === 'already assigned', 'Envelope refusal must not succeed');
requireCondition($GLOBALS['requests'][0]['url'] === 'https://dedicated-servers.reliablesite.dev/v2/DDoS/Profiles/loc%2Fa%252F%2B%20%C3%BC/001%2Fa%25%2B%C3%BC/AssignIp', 'Opaque path bytes');
requireCondition(json_decode($GLOBALS['requests'][0]['options'][CURLOPT_POSTFIELDS], true) === ['ipInput' => '203.0.113.10'], 'JSON body');
$fixture = ['code' => 500, 'body' => '{"status":false,"message":"safe unknown","data":null}', 'error' => ''];
$r = $api->removeDdosProfileIp('loc', 'profile', 0);
requireCondition(!$r['success'] && !empty($r['unknown_outcome']) && count($GLOBALS['requests']) === 2, 'Unknown outcome must not retry');
$fixture = ['code' => 500, 'body' => '<html>proxy failure</html>', 'error' => ''];
$r = $api->removeDdosProfileIp('loc', 'profile', 0);
requireCondition(!$r['success'] && !empty($r['unknown_outcome']) && count($GLOBALS['requests']) === 3, 'Non-JSON unknown outcome');
$fixture = ['code' => 200, 'body' => '{"status":true,"data":{"assigned":["203.0.113.10"],"skipped":["203.0.113.11"]}}', 'error' => ''];
$r = $api->assignDdosProfileIp('loc', 'profile', '203.0.113.10');
requireCondition($r['success'] && count($r['data']['skipped']) === 1, 'Partial success retained');
$fixture = ['code' => 400, 'body' => '{"status":true,"data":{}}', 'error' => ''];
$r = $api->removeDdosProfileIp('loc', 'profile', 0);
requireCondition(!$r['success'], 'HTTP failure cannot be envelope success');
echo "DDoS transport synthetic PASS\n";
