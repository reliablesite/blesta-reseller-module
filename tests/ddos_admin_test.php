<?php
class Loader { static function loadComponents($o, $names) {} static function loadModels($o, $names) {} static function loadHelpers($o, $names) {} }
class Configure { static function get($key) { return $key === 'Blesta.company_id' ? 1 : null; } }
require __DIR__ . '/ddos_handler_test.php';
class AdminDdosSession { public $values = ['blesta_id' => 1, 'blesta_staff_id' => 7]; function read($key) { return $this->values[$key] ?? null; } function write($key, $value) { $this->values[$key] = $value; } function clear($key) { unset($this->values[$key]); } }
class AdminDdosForm { function verifyCsrfToken($key, $token) { return $key === 'reliablesite-ddos-admin' && $token === 'csrf'; } function getCsrfToken($key) { return 'csrf'; } }
class AdminDdosStaff { public $active = true; function get($id, $company) { return (object)['status' => $this->active ? 'active' : 'inactive', 'group' => (object)['id' => 2, 'company_id' => $company]]; } }
class AdminDdosAcl { public $allowed = true; function check($aro, $aco, $action) { return $this->allowed && $aro === 'staff_group_2' && $aco === 'admin_company_modules' && $action === 'manage'; } }
class AdminDdosApi extends SyntheticDdosApi { public $accessible = true; public function getServerDetails($id) { return $this->accessible ? parent::getServerDetails($id) : ['success' => false, 'data' => []]; } }
class AdminDdosModule extends Reliablesite {
    public $Session; public $Form; public $Staff; public $Acl; public $api; public $module;
    function __construct() { $this->base_uri = '/blesta/admin/'; $this->Session = new AdminDdosSession(); $this->Form = new AdminDdosForm(); $this->Staff = new AdminDdosStaff(); $this->Acl = new AdminDdosAcl(); $this->api = new AdminDdosApi(); $this->module = (object)['id' => 3, 'class' => 'reliablesite', 'company_id' => 1, 'rows' => [(object)['id' => 9, 'module_id' => 3, 'meta' => (object)['api_key' => 'synthetic']]]]; }
    public function getApi() { return $this->api; }
    public function getModuleId() { return 3; }
    public $selected = 9;
    protected function selectedDdosRowId() { return $this->selected; }
    function response($post) { return $this->adminDdosActionResponse($this->module, $post); }
    protected function emitAdminDdosAction(array $result) { throw new AdminDdosEmitted($result); }
}
class AdminDdosEmitted extends Exception { public $result; function __construct($result) { $this->result = $result; } }
function ddosCheck($ok, $message) { if (!$ok) throw new Exception($message); }
$m = new AdminDdosModule();
$m->api->profiles = [['profile' => ['id' => '001/a%+ü'], 'dataSourceId' => 'loc/a', 'ips' => [['id' => 0, 'ip' => '203.0.113.10']]]];
$post = ['action' => 'ddos_assign', 'rsid' => 'server1', 'data_source_id' => 'loc/a', 'profile_id' => '001/a%+ü', 'ip_input' => '203.0.113.10', '_csrf_token' => 'csrf'];
$_SERVER['REQUEST_METHOD'] = 'GET'; ddosCheck($m->response($post)['status'] === 405 && !$m->api->writes, 'GET denied');
$_SERVER['REQUEST_METHOD'] = 'POST';
$post['_csrf_token'] = 'wrong'; ddosCheck($m->response($post)['status'] === 403 && !$m->api->writes, 'CSRF denied');
$post['_csrf_token'] = 'csrf'; $m->Acl->allowed = false; ddosCheck($m->response($post)['status'] === 403 && !$m->api->writes, 'ACL denied');
$m->Acl->allowed = true; $m->Session->values['blesta_staff_id'] = null; ddosCheck($m->response($post)['status'] === 403 && !$m->api->writes, 'Staff denied');
$m->Session->values['blesta_staff_id'] = 7; $m->module->company_id = 2; ddosCheck($m->response($post)['status'] === 403 && !$m->api->writes, 'Foreign module denied');
$m->module->company_id = 1; $m->selected = 10; ddosCheck($m->response($post)['status'] === 403 && !$m->api->writes, 'Other credential row denied');
$m->selected = 9; $m->module->rows[0]->module_id = 4; ddosCheck($m->response($post)['status'] === 403 && !$m->api->writes, 'Foreign row denied');
$m->module->rows[0]->module_id = 3; $m->api->accessible = false; ddosCheck($m->response($post)['status'] === 404 && !$m->api->writes, 'Inaccessible server denied');
$m->api->accessible = true; $post['rsid'] = 'server1/../other'; ddosCheck($m->response($post)['status'] === 400 && !$m->api->writes, 'Bad server denied');
$post['rsid'] = 'server1'; $first = $m->response($post); ddosCheck($first['status'] === 303 && $m->api->writes === [['assign', 'loc/a', '001/a%+ü', '203.0.113.10']], 'Exact pair assigned once');
ddosCheck(strpos($first['redirect'], '?scr=manageserver&rsid=server1&p=ddos') !== false && strpos($m->Session->read('reliablesite_ddos_flash'), 'server1') !== false, '303 and scoped flash');
$post['action'] = 'ddos_remove'; unset($post['ip_input']); $post['ip_id'] = '0'; ddosCheck($m->response($post)['status'] === 303 && $m->api->writes[1] === ['remove', 'loc/a', '001/a%+ü', 0], 'Zero removal');
$_GET = ['scr' => 'ddosaction']; $post['action'] = 'ddos_assign'; $post['ip_input'] = '203.0.113.10';
try { $m->manageModule($m->module, $post); throw new Exception('Admin route fell through to metadata persistence'); }
catch (AdminDdosEmitted $e) { ddosCheck($e->result['status'] === 303 && count($m->api->writes) === 3, 'Admin route intercepted POST'); }
foreach (['missing' => [], 'invalid' => ['scr' => 'manageserver']] as $label => $route) {
    $_GET = $route;
    $before = count($m->api->writes);
    $post['_csrf_token'] = 'wrong';
    try { $m->manageModule($m->module, $post); throw new Exception('DDoS POST fell through: ' . $label); }
    catch (AdminDdosEmitted $e) { ddosCheck($e->result['status'] === 403 && count($m->api->writes) === $before, 'DDoS POST consumed without metadata persistence: ' . $label); }
}
echo "Admin DDoS synthetic PASS\n";
