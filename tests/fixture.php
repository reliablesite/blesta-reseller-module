<?php
// Synthetic, loopback-only fixture. Never bootstraps Blesta or calls a provider.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/response') { require __DIR__ . '/http_response_fixture.php'; exit; }
if ($path === '/bootstrap.css') { header('Content-Type: text/css'); readfile(dirname($root, 3) . '/app/views/client/bootstrap/css/application.min.css'); exit; }
if ($path === '/module.css') { header('Content-Type: text/css'); readfile($root . '/views/default/css/rs-client.css'); exit; }
if ($path === '/console') {
    $console_config = ['origins' => ['https://kvmproxy-ny1.reliablesite.net'], 'csrf' => 'SYNTHETIC', 'nonce' => 'SYNTHETIC'];
    $console_error = ($_GET['state'] ?? '') === 'unavailable' ? 'Instant KVM is unavailable for this server.' : null;
    require $root . '/views/default/instant_kvm.pdt'; exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
class FixtureHtml { function safe($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
class FixtureForm { function create() { echo '<form method="post">'; } function end() { echo '</form>'; } }
class FixtureWidget {
    private $tabs = []; private $links = [];
    function clear() {} function setTabs($tabs) { $this->tabs = $tabs; } function setLinkButtons($links) { $this->links = $links; } function setNavStyle($style) {}
    function create($title, $attrs) { echo '<section class="card"><div class="card-header"><h2>' . htmlspecialchars($title) . '</h2>'; foreach ($this->links as $link) { echo '<a href="#">' . htmlspecialchars($link['name']) . '</a>'; } echo '</div><div class="card-body"><ul class="nav nav-pills mb-3">'; foreach ($this->tabs as $tab) { echo '<li class="nav-item"><a class="nav-link" href="#">' . $tab['name'] . '</a></li>'; } echo '</ul>'; }
    function end() { echo '</div></section>'; }
}
class FixtureView {
    public $Html; public $Form; public $Widget;
    function __construct() { $this->Html = new FixtureHtml(); $this->Form = new FixtureForm(); $this->Widget = new FixtureWidget(); }
    function render($file, $data) { extract($data); require $file; }
}
$data = ['page' => 'kvm', 'kvm' => ['accessEnabled' => false], 'notice' => null, 'error' => null, 'client_ip' => 'SYNTHETIC IP', 'server_id' => '42', 'srv_base' => '/?scr=manageserver&rsid=42&', 'back_link' => '#', 'is_assigned' => true, 'server_label' => 'Synthetic dedicated server', 'username' => 'fixture', 'manage_link' => '#', 'assign_link' => '#', 'instant_kvm_available' => ($_GET['state'] ?? '') !== 'unavailable', 'instant_kvm_url' => '/console'];
$views = ['client' => 'client_kvm', 'admin' => 'manage_server', 'service' => 'tab_admin_manage'];
$view = $views[$_GET['view'] ?? 'client'] ?? 'client_kvm';
header('Cache-Control: no-store');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SYNTHETIC module render fixture</title><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/module.css"><style>body{padding:16px} .fixture-label{background:#fff0b3;color:#241d00;padding:12px;margin-bottom:16px} .rs-module{max-width:1160px;margin:auto} .nav{flex-wrap:wrap} h2{font-size:22px}</style></head><body><div class="fixture-label">SYNTHETIC FIXTURE — no account, real server or provider controls. Framework chrome not reproduced.</div><main class="rs-module"><?php (new FixtureView())->render($root . '/views/default/' . $view . '.pdt', $data); ?></main></body></html>
