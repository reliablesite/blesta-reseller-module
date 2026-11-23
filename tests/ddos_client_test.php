<?php
class Module {}
class Language { public static function _($key, $return) { return $key; } }
require dirname(__DIR__) . '/reliablesite.php';
class ClientForm { function verifyCsrfToken($key, $token) { return $key === 'reliablesite-ddos-client' && $token === 'client-token'; } function getCsrfToken($key) { return 'client-token'; } }
class ClientApi {
    public $writes = [];
    function ddosProfiles() { return ['success' => true, 'data' => ['profiles' => [['profile' => ['id' => 'profile'], 'dataSourceId' => 'ds', 'ips' => [['id' => 7, 'ip' => '203.0.113.10']]]]]]; }
    function getServerDetails($id) { return ['success' => true, 'data' => ['ipAddressList' => [['ipDescription' => '203.0.113.0/24']]]]; }
    function assignDdosProfileIp($ds, $pid, $ip) { $this->writes[] = ['assign', $ip]; return ['success' => true]; }
    function removeDdosProfileIp($ds, $pid, $id) { $this->writes[] = ['remove', $id]; return ['success' => true]; }
}
$m = (new ReflectionClass('Reliablesite'))->newInstanceWithoutConstructor(); $m->Form = new ClientForm();
$api = new ClientApi(); $handle = new ReflectionMethod('Reliablesite', 'handleClientAction'); $handle->setAccessible(true);
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach (['ddos_assign', 'ddos_remove'] as $action) {
    $post = ['action' => $action, 'data_source_id' => 'ds', 'profile_id' => 'profile', 'ip_input' => '203.0.113.10', 'ip_id' => '7'];
    foreach ([null, 'admin-token', 'client-token'] as $token) {
        if ($token !== null) $post['_csrf_token'] = $token;
        $before = count($api->writes);
        $result = $handle->invoke($m, $api, 'server1', null, $post);
        if ($token === 'client-token' ? ($result['error'] !== null || count($api->writes) !== $before + 1) : (!$result['error'] || count($api->writes) !== $before)) throw new Exception('Client CSRF mismatch: ' . $action . '/' . (string)$token);
    }
}
$view = file_get_contents(dirname(__DIR__) . '/views/default/client_ddos.pdt');
if (substr_count($view, 'name="_csrf_token"') !== 2 || substr_count($view, '$ddos_csrf') !== 2) throw new Exception('Both forms must emit keyed token');
echo "Client DDoS CSRF synthetic PASS\n";
