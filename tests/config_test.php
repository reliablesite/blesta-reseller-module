<?php
class Configure { public static $data=[]; static function set($k,$v) { self::$data[$k]=$v; } }
require dirname(__DIR__) . '/config/reliablesite.php';
if ((Configure::$data['Reliablesite.instant_kvm_origins'] ?? null) !== ['https://kvmproxy-ny1.reliablesite.net'] || (Configure::$data['Reliablesite.instant_kvm_trusted_proxies'] ?? null) !== []) { fwrite(STDERR, "FAIL explicit NY-only origin / no trusted proxies defaults\n"); exit(1); }
echo "PASS explicit NY-only origin / no trusted proxies defaults\n";
