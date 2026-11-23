<?php
class Module {}
class Language { public static function _($key, $return) { return $key; } }
require dirname(__DIR__) . '/reliablesite.php';
class SyntheticDdosApi {
    public $writes = [];
    public $profiles = [];
    public function ddosProfiles() { return ['success' => true, 'data' => ['profiles' => $this->profiles]]; }
    public function getServerDetails($id) { return ['success' => true, 'data' => ['ipAddressList' => [['ipDescription' => '203.0.113.0/24']]]]; }
    public function assignDdosProfileIp($ds, $pid, $ip) { $this->writes[] = ['assign', $ds, $pid, $ip]; return ['success' => true, 'data' => ['assigned' => [$ip]]]; }
    public function removeDdosProfileIp($ds, $pid, $id) { $this->writes[] = ['remove', $ds, $pid, $id]; return ['success' => true]; }
}
$module = (new ReflectionClass('Reliablesite'))->newInstanceWithoutConstructor();
$method = new ReflectionMethod('Reliablesite', 'handleDdosAction');
$method->setAccessible(true);
$api = new SyntheticDdosApi();
$api->profiles = [['profile' => ['id' => '001/a%+ü'], 'dataSourceId' => 'loc/a', 'ips' => [['id' => 0, 'ip' => '203.0.113.10']]]];
$assign = ['data_source_id' => 'loc/a', 'profile_id' => '001/a%+ü', 'ip_input' => '203.0.113.10'];
$result = $method->invoke($module, $api, 'server1', $assign, 'ddos_assign');
if ($result['error'] !== null || $api->writes !== [['assign', 'loc/a', '001/a%+ü', '203.0.113.10']]) throw new Exception('Opaque pair assignment failed');
$remove = ['data_source_id' => 'loc/a', 'profile_id' => '001/a%+ü', 'ip_id' => '0'];
$result = $method->invoke($module, $api, 'server1', $remove, 'ddos_remove');
if ($result['error'] !== null || $api->writes[1] !== ['remove', 'loc/a', '001/a%+ü', 0]) throw new Exception('Zero assignment removal failed');
foreach (['-1', '1.0', 'foo', '01'] as $invalid) {
    $remove['ip_id'] = $invalid;
    $result = $method->invoke($module, $api, 'server1', $remove, 'ddos_remove');
    if (!$result['error'] || count($api->writes) !== 2) throw new Exception('Invalid assignment ID accepted');
}
$assign['profile_id'] = '1/a%+ü';
$result = $method->invoke($module, $api, 'server1', $assign, 'ddos_assign');
if (!$result['error'] || count($api->writes) !== 2) throw new Exception('Stale pair accepted');
$assign['profile_id'] = '001/a%+ü'; $assign['ip_input'] = '198.51.100.4';
$result = $method->invoke($module, $api, 'server1', $assign, 'ddos_assign');
if (!$result['error'] || count($api->writes) !== 2) throw new Exception('Foreign IP accepted');
foreach (['203.0.113.10/not-a-mask', '203.0.113.10/33', '203.0.113.10/-1', 'not-an-ip/24', '203.0.113.10/24/8'] as $bad) {
    $assign['ip_input'] = $bad;
    $result = $method->invoke($module, $api, 'server1', $assign, 'ddos_assign');
    if (!$result['error'] || count($api->writes) !== 2) throw new Exception('Malformed CIDR reached provider: ' . $bad);
}
echo "DDoS handler synthetic PASS\n";
