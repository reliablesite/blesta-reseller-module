<?php
// Executes the real response emitter against synthetic data, on loopback only.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
define('DS', DIRECTORY_SEPARATOR);
class Module { public $base_uri = '/synthetic/'; public $view; }
class Loader { static function loadHelpers($o, $helpers) {} }
class View {
    public $base_uri; private $data = [];
    function __construct($name, $type) {}
    function setDefaultView($path) {}
    function set($name, $value) { $this->data[$name] = $value; }
    function fetch() { extract($this->data); ob_start(); require dirname(__DIR__) . '/views/default/instant_kvm.pdt'; return ob_get_clean(); }
}
require dirname(__DIR__) . '/reliablesite.php';
class ResponseFixture extends Reliablesite {
    function __construct() {}
    function send() {
        $response = ['status' => 200, 'headers' => ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'], 'view' => 'instant_kvm', 'data' => ['console_error' => 'SYNTHETIC response fixture — no live launch', 'console_config' => null]];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $response['json'] = ['JavascriptUrl' => 'https://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js?token=SYNTHETIC-NEVER-REQUESTED']; }
        $this->emitInstantKvmResponse($response);
        echo 'FAIL: framework fallthrough';
    }
}
(new ResponseFixture())->send();
