<?php
require dirname(__DIR__) . '/lib/reliablesite_api.php';
$api = new ReliablesiteApi('synthetic');
$reflection = new ReflectionClass($api);
$method = $reflection->getMethod('profilePath');
$method->setAccessible(true);
$path = $method->invoke($api, 'pop/a%2F+ ü', '001/a+b%');
if ($path !== 'DDoS/Profiles/pop%2Fa%252F%2B%20%C3%BC/001%2Fa%2Bb%25') {
    throw new Exception('Opaque identifier path mismatch: ' . $path);
}
echo "DDoS path contract PASS\n";
