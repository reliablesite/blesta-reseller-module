<?php
define('DS', DIRECTORY_SEPARATOR);
// Native dependencies replaced only at boundaries; real module methods are exercised.
class Module { public $base_uri = '/blesta/client/'; public function serviceFieldsToObject($fields) { return (object)$fields; } public function getModuleRow() { return (object)['id' => 1, 'meta' => (object)['api_key' => 'synthetic']]; } }
class Loader { static function loadComponents($o, $names) {} static function loadModels($o, $names) {} static function loadHelpers($o, $names) {} static function load($file) { require_once $file; } }
class Configure { static function get($key) { return ['Blesta.company_id' => 1, 'Reliablesite.instant_kvm_origins' => ['https://kvmproxy-ny1.reliablesite.net'], 'Reliablesite.instant_kvm_trusted_proxies' => []][$key] ?? null; } }
class FakeSession { public $data = ['blesta_id' => 9, 'blesta_client_id' => 7]; function read($k) { return $this->data[$k] ?? null; } function write($k, $v) { $this->data[$k] = $v; } }
class FakeForm { function getCsrfToken($key = null) { return 'synthetic-csrf:' . (string) $key; } function verifyCsrfToken($key = null, $token = null) { return $token === $this->getCsrfToken($key === null ? ($_SERVER['REQUEST_URI'] ?? '') : $key); } }
class FakeStaff { public $group = true; function get($id, $company) { return (object)['status' => 'active', 'group' => $this->group ? (object)['id' => 2, 'company_id' => 1] : null]; } }
class FakeAcl { public $allowed = true; public $denyManage = false; public $calls = []; function check($aro, $aco, $action) { $this->calls[] = [$aro,$aco,$action]; return $this->allowed && !($action === 'manage' && $this->denyManage); } }
class FakeInstantApi { public $launches = 0; public $availableCalls = 0; public $eligible = true; function available($id) { $this->availableCalls++; return $this->eligible; } function launch($id, $ip) { $this->launches++; return 'https://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js?token=SYNTHETIC'; } }
require dirname(__DIR__) . '/reliablesite.php';
require dirname(__DIR__) . '/lib/reliablesite_instant_kvm.php';
class TestModule extends Reliablesite {
    public $Session; public $Form; public $Staff; public $Acl; public $fake;
    function __construct() { $this->Session = new FakeSession(); $this->Form = new FakeForm(); $this->Staff = new FakeStaff(); $this->Acl = new FakeAcl(); $this->fake = new FakeInstantApi(); }
    public function getApi() { throw new LogicException('Legacy API must not be used by console route'); }
    protected function getInstantKvmApi() { return $this->fake; }
    function response($id, $post, $service) { return $this->instantKvmResponse($id, $post, $service); }
    function ui($id, $service = null) { return $this->instantKvmUi($id, $service); }
    public function getModuleId() { return 3; }
    protected function emitInstantKvmResponse(array $response) { throw new ConsoleResponse($response); }
}
$tests = [];
function check($value, $message) { if (!$value) { throw new Exception($message); } }
function testcase($name, $fn) { global $tests; $tests[$name] = $fn; }
function service($change = []) { return (object)array_merge(['id' => 11, 'module_row_id' => 1, 'client_id' => 7, 'status' => 'active'], $change); }
testcase('authorized client GET returns standalone config, never launch; POST has CSRF, nonce and no-store', function () {
    check(method_exists('Reliablesite', 'instantKvmResponse'), 'Missing authenticated console response');
    $m = new TestModule(); $_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '8.8.8.8'];
    $r = $m->response('42', [], service());
    check($r['status'] === 200 && $r['view'] === 'instant_kvm', 'Standalone view');
    check($r['headers']['Cache-Control'] === 'no-store' && $m->fake->launches === 0, 'GET cannot launch/cache');
    check(($r['headers']['X-Frame-Options'] ?? '') === 'DENY', 'Privileged auto-launch document cannot be framed');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $post = ['action' => 'instant_kvm_launch', '_csrf_token' => $r['data']['console_config']['csrf'], 'nonce' => $r['data']['console_config']['nonce']];
    $_POST = $post;
    $r = $m->response('42', [], service());
    check($r['status'] === 200 && isset($r['json']['JavascriptUrl']) && $m->fake->launches === 1, 'Raw form fields launch when the controller callback post array is empty');
    $r = $m->response('42', $post, service());
    check($r['status'] === 403 && $m->fake->launches === 1, 'Nonce cannot replay');
});
testcase('client ownership, login, active service and request method enforced before API', function () {
    foreach ([['client_id' => 8], ['status' => 'suspended']] as $change) {
        $m = new TestModule(); $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $r = $m->response('42', [], service($change));
        check($r['status'] === 403 && $m->fake->availableCalls === 0, 'Denied client must not read capability');
    }
    $m = new TestModule(); $m->Session->data = []; $_SERVER = ['REQUEST_METHOD' => 'GET'];
    check($m->response('42', [], service())['status'] === 403, 'Anonymous denied');
    $m = new TestModule(); $_SERVER['REQUEST_METHOD'] = 'PUT';
    check($m->response('42', [], service())['status'] === 405 && $m->fake->availableCalls === 0, 'PUT denied');
});
testcase('admin must be active staff in current company with module-management ACL', function () {
    $m = new TestModule(); $_SERVER = ['REQUEST_METHOD' => 'GET'];
    check($m->response('42', [], null)['status'] === 403, 'Client cannot enter admin route');
    $m->Session->data['blesta_staff_id'] = 5; $m->Staff->group = false;
    check($m->response('42', [], null)['status'] === 403, 'Foreign company denied');
    $m->Staff->group = true; $m->Acl->allowed = false;
    check($m->response('42', [], null)['status'] === 403, 'ACL denied');
    $m->Acl->allowed = true;
    check($m->response('42', [], null)['status'] === 200, 'Authorized admin');
    check(end($m->Acl->calls) === ['staff_group_2', 'admin_company_modules', 'manage'], 'Native module manage action ACL');
});
testcase('launch rejects bad action, expired or cross-server nonce, and throttles fresh attempts', function () {
    foreach (['action', 'csrf', 'expired', 'server', 'missing'] as $mode) {
        $m = new TestModule(); $_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '8.8.8.8'];
        $r = $m->response('42', [], service()); $nonce = $r['data']['console_config']['nonce'];
        if ($mode === 'expired') { $m->Session->data['reliablesite_kvm_nonces'][$nonce]['expires'] = 0; }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $r = $m->response($mode === 'server' ? '43' : '42', ['action' => $mode === 'action' ? 'wrong' : 'instant_kvm_launch', '_csrf_token' => $mode === 'csrf' ? 'bad' : 'synthetic-csrf:reliablesite-instant-kvm', 'nonce' => $mode === 'missing' ? '' : $nonce], service());
        check($r['status'] === 403 && $m->fake->launches === 0, 'Reject ' . $mode);
        check(($r['json']['reason'] ?? '') === ($mode === 'action' ? 'invalid_action' : ($mode === 'csrf' ? 'invalid_csrf' : 'invalid_nonce')), 'Identify rejected launch stage without secrets: ' . $mode);
    }
    $m = new TestModule();
    for ($i = 0; $i < 2; $i++) {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '8.8.8.8'];
        $r = $m->response('42', [], service()); $_SERVER['REQUEST_METHOD'] = 'POST';
        $r = $m->response('42', ['action' => 'instant_kvm_launch', '_csrf_token' => 'synthetic-csrf:reliablesite-instant-kvm', 'nonce' => $r['data']['console_config']['nonce']], service());
        check($r['status'] === ($i ? 429 : 200), 'Session launch throttle');
    }
    check($m->fake->launches === 1, 'No second provider launch');
});
testcase('launch UI is capability gated and uses only module-authenticated routes', function () {
    check(method_exists('Reliablesite', 'instantKvmUi'), 'Missing capability-gated UI data');
    $m = new TestModule(); $s = service(['id' => 11]);
    $r = $m->ui('42', $s);
    check($r['instant_kvm_available'] && $r['instant_kvm_url'] === '/blesta/client/services/manage/11/tabClientManage/?p=instantkvm', 'Client scoped route');
    $m->fake->eligible = false;
    check(!$m->ui('42', $s)['instant_kvm_available'], 'Capability false');
    $m->fake->eligible = true; $m->Session->data['blesta_staff_id'] = 5; $m->base_uri = '/blesta/admin/';
    $r = $m->ui('42');
    check($r['instant_kvm_available'] && $r['instant_kvm_url'] === '/blesta/admin/settings/company/modules/manage/3/?scr=instantkvm&rsid=42', 'Admin route avoids addrow side effects');
    $m->Acl->allowed = false;
    check(!$m->ui('42')['instant_kvm_available'], 'Admin denied hides button');
});
class ConsoleResponse extends Exception { public $response; function __construct($response) { $this->response = $response; } }
testcase('client and admin controller hooks emit dedicated responses before any legacy dispatch', function () {
    $_SERVER = ['REQUEST_METHOD' => 'GET']; $m = new TestModule();
    try { $m->tabClientManage(null, service(['id' => 11, 'fields' => ['reliablesite_server_id' => '42']]), ['p' => 'instantkvm'], []); throw new Exception('Did not emit'); }
    catch (ConsoleResponse $e) { check($e->response['view'] === 'instant_kvm', 'Client standalone'); }
    $m->Session->data['blesta_staff_id'] = 5; $_GET = ['scr' => 'instantkvm', 'rsid' => '42']; $post = [];
    try { $m->manageModule((object)['id' => 3], $post); throw new Exception('Did not emit'); }
    catch (ConsoleResponse $e) { check($e->response['view'] === 'instant_kvm', 'Admin standalone before metadata writes'); }
});
class CapturedView extends Exception { public $data; function __construct($data) { $this->data = $data; } }
class View { public $base_uri; private $data = []; function __construct($a,$b) {} function setDefaultView($v) {} function set($k,$v) { $this->data[$k] = $v; } function fetch() { throw new CapturedView($this->data); } }
class FakeModuleManager { function getInstalled() { return []; } }
class FakeLegacy { function getKVMDetails($id) { return ['status' => true, 'data' => ['accessEnabled' => false]]; } }
class UiModule extends TestModule { public $view; public $ModuleManager; function __construct() { parent::__construct(); $this->ModuleManager = new FakeModuleManager(); } public function getApi() { return new FakeLegacy(); } }
testcase('real client and admin service tabs pass capability data into their views', function () {
    $m = new UiModule(); $s = service(['id' => 11, 'fields' => ['reliablesite_server_id' => '42']]); $_SERVER = ['REMOTE_ADDR' => '8.8.8.8'];
    try { $m->tabClientManage(null, $s, ['p' => 'kvm'], []); throw new Exception('No view'); }
    catch (CapturedView $e) { check($e->data['instant_kvm_available'] ?? false, 'Client must receive capability'); }
    $m->Session->data['blesta_staff_id'] = 5;
    try { $m->tabAdminManage(null, $s, [], []); throw new Exception('No view'); }
    catch (CapturedView $e) { check($e->data['instant_kvm_available'] ?? false, 'Admin service must receive capability'); }
});
testcase('admin server KVM screen receives capability data', function () {
    $m = new UiModule(); $m->Session->data['blesta_staff_id'] = 5;
    (new ReflectionProperty(Reliablesite::class, 'pending_badge_count'))->setValue($m, 0);
    $_GET = ['rsid' => '42', 'p' => 'kvm', 'scr' => 'manageserver']; $_SERVER = ['REMOTE_ADDR' => '8.8.8.8']; $vars = [];
    try { $m->manageAddRow($vars); throw new Exception('No view'); }
    catch (CapturedView $e) { check($e->data['instant_kvm_available'] ?? false, 'Admin server must receive capability'); }
});
class ScopedModuleManager {
    public $company = 1;
    function getRow($id) { return (object)['id' => $id, 'module_id' => 3, 'meta' => (object)['api_key' => 'synthetic']]; }
    function get($id) { return (object)['id' => $id, 'class' => 'reliablesite', 'company_id' => $this->company]; }
}
class ApiModule extends Reliablesite { public $ModuleManager; function __construct() { $this->ModuleManager = new ScopedModuleManager(); } function apiForTest() { return $this->getInstantKvmApi(); } }
testcase('sensitive API refuses a credential row from another company', function () {
    $m = new ApiModule(); check($m->apiForTest() instanceof ReliablesiteInstantKvm, 'Scoped API');
    $m->ModuleManager->company = 2;
    try { $m->apiForTest(); throw new Exception('Foreign company credential accepted'); } catch (RuntimeException $e) {}
});
testcase('explicit module-manage denial overrides permission to list modules', function () {
    $m = new TestModule(); $m->Session->data['blesta_staff_id'] = 5; $m->Acl->denyManage = true; $_SERVER = ['REQUEST_METHOD' => 'GET'];
    check($m->response('42', [], null)['status'] === 403 && $m->fake->availableCalls === 0, 'Manage denied must not query provider');
});
// Model native service-tab row binding on each fresh request; provider calls stay fake.
class TwoRowModule extends UiModule {
    public $rowId = 1; public $accounts;
    function __construct() { parent::__construct(); $this->accounts = [1 => new FakeInstantApi(), 2 => new FakeInstantApi()]; $this->accounts[1]->eligible = false; $this->Session->data = ['blesta_id' => 9, 'blesta_staff_id' => 5]; $this->base_uri = '/blesta/admin/'; }
    public function getModuleRow() { return (object)['id' => $this->rowId, 'module_id' => 3, 'meta' => (object)['api_key' => 'synthetic-' . $this->rowId]]; }
    protected function getInstantKvmApi() { return $this->accounts[$this->rowId]; }
}
function adminConsole($m, $s, $post = []) {
    try { $m->tabAdminManage(null, $s, ['p' => 'instantkvm', 'rsid' => '999', 'module_row_id' => 1], $post); }
    catch (ConsoleResponse $e) { return $e->response; }
    throw new Exception('Admin service did not emit standalone console');
}
testcase('admin service link preserves account B through fresh service-tab GET and POST', function () {
    $s = service(['id' => 11, 'module_row_id' => 2, 'fields' => ['reliablesite_server_id' => '42']]);
    $m = new TwoRowModule(); $m->rowId = 2; $_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '8.8.8.8'];
    try { $m->tabAdminManage(null, $s, [], []); throw new Exception('No view'); }
    catch (CapturedView $e) {
        check($e->data['instant_kvm_available'], 'Account B is eligible');
        check($e->data['instant_kvm_url'] === '/blesta/admin/clients/servicetab/7/11/tabAdminManage/?p=instantkvm', 'Link must retain service-bound credential row');
    }
    $fresh = new TwoRowModule(); $fresh->rowId = $s->module_row_id; // admin_clients::processModuleTab
    $r = adminConsole($fresh, $s);
    check($r['status'] === 200 && $r['view'] === 'instant_kvm', 'Account B standalone console');
    check($fresh->accounts[1]->availableCalls === 0 && $fresh->accounts[2]->availableCalls === 1, 'Only account B capability');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $post = ['action' => 'instant_kvm_launch', '_csrf_token' => 'synthetic-csrf:reliablesite-instant-kvm', 'nonce' => $r['data']['console_config']['nonce']];
    $next = new TwoRowModule(); $next->rowId = $s->module_row_id; $next->Session = $fresh->Session;
    $r = adminConsole($next, $s, $post);
    check($r['status'] === 200 && $next->accounts[2]->launches === 1 && $next->accounts[1]->launches === 0, 'Launch only bound account B');
});
testcase('admin service console rejects inactive or mismatched bound credential rows before provider access', function () {
    foreach (['inactive', 'wrong-row', 'client', 'acl'] as $mode) {
        $m = new TwoRowModule(); $m->rowId = $mode === 'wrong-row' ? 1 : 2;
        $s = service(['id' => 11, 'module_row_id' => 2, 'status' => $mode === 'inactive' ? 'suspended' : 'active', 'fields' => ['reliablesite_server_id' => '42']]);
        if ($mode === 'client') { $m->Session->data = ['blesta_id' => 9, 'blesta_client_id' => 7]; }
        if ($mode === 'acl') { $m->Acl->allowed = false; }
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $r = adminConsole($m, $s);
        check($r['status'] === 403 && $m->accounts[1]->availableCalls === 0 && $m->accounts[2]->availableCalls === 0, 'Reject ' . $mode . ' before API');
    }
});
testcase('admin service nonce cannot cross services or a reassigned credential row', function () {
    foreach (['service', 'row'] as $mode) {
        $m = new TwoRowModule(); $m->rowId = 2;
        $s = service(['id' => 11, 'module_row_id' => 2, 'fields' => ['reliablesite_server_id' => '42']]);
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '8.8.8.8'];
        $r = adminConsole($m, $s);
        $post = ['action' => 'instant_kvm_launch', '_csrf_token' => 'synthetic-csrf:reliablesite-instant-kvm', 'nonce' => $r['data']['console_config']['nonce']];
        if ($mode === 'service') { $s->id = 12; }
        else { $s->module_row_id = 1; $m->rowId = 1; }
        $_SERVER['REQUEST_METHOD'] = 'POST'; $r = adminConsole($m, $s, $post);
        check($r['status'] === 403 && $m->accounts[1]->launches === 0 && $m->accounts[2]->launches === 0, 'Nonce cannot cross ' . $mode);
    }
});
$failed = 0;
foreach ($tests as $name => $fn) { try { $fn(); echo "PASS $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; } }
echo count($tests) . " module tests, $failed failures\n"; exit($failed ? 1 : 0);
