<?php
// Standalone deterministic tests. No Blesta bootstrap, database or live network.
$path = dirname(__DIR__) . '/lib/reliablesite_instant_kvm.php';
if (is_file($path)) { require_once $path; }
$tests = [];
function test($name, $fn) { global $tests; $tests[$name] = $fn; }
function same($expected, $actual) { if ($expected !== $actual) { throw new Exception('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }
function rejects($fn) { try { $fn(); } catch (RuntimeException $e) { return; } throw new Exception('Expected rejection'); }
function response($data, $code = 200, $type = 'application/json') { return ['code' => $code, 'type' => $type, 'body' => json_encode($data)]; }
function server($changes = []) { return array_merge(['serverId' => 42, 'status' => 'Active', 'instantKvmAvailable' => true], $changes); }
function api($queue, &$calls) {
    $calls = [];
    return new ReliablesiteInstantKvm('synthetic-key', ['https://kvmproxy-ny1.reliablesite.net'], function ($method, $path, $token) use (&$queue, &$calls) {
        $calls[] = [$method, $path, $token];
        if (!$queue) { throw new Exception('Unexpected request'); }
        return array_shift($queue);
    });
}
test('launch exchanges backend token, checks exact server, posts once and preserves script URL', function () {
    same(true, class_exists('ReliablesiteInstantKvm'));
    $url = 'https://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js?token=SYNTHETIC%2Bonly&x=1';
    $client = api([response(['status' => true, 'message' => 'synthetic-jwt']), response(['status' => true, 'data' => ['server' => server()]]), response(['status' => true, 'data' => ['JavascriptUrl' => $url]])], $calls);
    same($url, $client->launch('42', '8.8.8.8'));
    same([['POST', '/Login/Token?ApiKey=synthetic-key', ''], ['GET', '/Server/42', 'synthetic-jwt'], ['POST', '/Server/42/LaunchInstantKVM?sourceIP=8.8.8.8', 'synthetic-jwt']], $calls);
});
test('launch rejects malformed server IDs and non-public literal browser IPs before network', function () {
    foreach (['0', '-1', '2147483648', '0042', '42junk', '4.2', ' 42', [], true, 42.0] as $id) {
        $client = api([], $calls);
        rejects(function () use ($client, $id) { $client->launch($id, '8.8.8.8'); });
        same([], $calls);
    }
    foreach (['', 'localhost', '8.8.8.8:80', '8.8.8.8,1.1.1.1', '8.8.8.8/32', '127.0.0.1', '10.0.0.1', '::1', 'fe80::1', []] as $ip) {
        $client = api([], $calls);
        rejects(function () use ($client, $ip) { $client->launch(42, $ip); });
        same([], $calls);
    }
});
test('capability requires exact active eligible server and launch rechecks it', function () {
    foreach ([['serverId' => 43], ['serverId' => '42x'], ['status' => 'Suspended'], ['status' => 'unknown'], ['instantKvmAvailable' => false], ['instantKvmAvailable' => 1], ['instantKvmAvailable' => 'true']] as $change) {
        $client = api([response(['status' => true, 'message' => 'synthetic-jwt']), response(['status' => true, 'data' => ['server' => server($change)]])], $calls);
        rejects(function () use ($client) { $client->launch(42, '8.8.8.8'); });
        same(2, count($calls));
    }
    $client = api([response(['status' => true, 'message' => 'synthetic-jwt']), response(['status' => true, 'data' => ['server' => server()]])], $calls);
    same(true, $client->available(42));
    same(2, count($calls));
});
test('provider response failures are generic and never retry launch', function () {
    foreach ([response(['status' => true, 'data' => ['JavascriptUrl' => 'secret']], 401), response(['status' => false, 'message' => 'SECRET']), response(['status' => 1]), response(['title' => 'SECRET'], 400), ['code' => 200, 'type' => 'text/html', 'body' => '<html>SECRET</html>'], ['code' => 0, 'type' => '', 'body' => 'SECRET']] as $bad) {
        $client = api([response(['status' => true, 'message' => 'synthetic-jwt']), response(['status' => true, 'data' => ['server' => server()]]), $bad], $calls);
        try { $client->launch(42, '8.8.8.8'); throw new Exception('Expected rejection'); }
        catch (RuntimeException $e) { same(false, str_contains($e->getMessage(), 'SECRET')); }
        same(3, count($calls));
    }
    foreach (['', [], "bad\r\ntoken"] as $token) {
        $client = api([response(['status' => true, 'message' => $token])], $calls);
        rejects(function () use ($client) { $client->available(42); });
        same(1, count($calls));
    }
});
test('script URL must be exact HTTPS allowlisted provider script, not HTML or credentials', function () {
    foreach (['http://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js?t=1', 'https://evil.test/embed/v1/kvm.js?t=1', 'https://kvmproxy-ny1.reliablesite.net.evil.test/embed/v1/kvm.js', 'https://user@kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js', 'https://kvmproxy-ny1.reliablesite.net:444/embed/v1/kvm.js', 'https://kvmproxy-ny1.reliablesite.net/console.html', "https://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js?x=\n", 'javascript:alert(1)', '', [], 'https://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js#fragment'] as $url) {
        $client = api([response(['status' => true, 'message' => 'synthetic-jwt']), response(['status' => true, 'data' => ['server' => server()]]), response(['status' => true, 'data' => ['JavascriptUrl' => $url]])], $calls);
        rejects(function () use ($client) { $client->launch(42, '8.8.8.8'); });
    }
    $client = api([response(['status' => true, 'message' => 'synthetic-jwt']), response(['status' => true, 'data' => ['server' => server()]]), response(['status' => true, 'data' => ['javascriptUrl' => 'https://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js']])], $calls);
    rejects(function () use ($client) { $client->launch(42, '8.8.8.8'); });
});
test('source IP ignores spoofed headers and trusts only explicitly configured literal proxies', function () {
    same(true, method_exists('ReliablesiteInstantKvm', 'sourceIp'));
    same('8.8.8.8', ReliablesiteInstantKvm::sourceIp(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1'], []));
    same('2606:4700:4700::1111', ReliablesiteInstantKvm::sourceIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '2606:4700:4700::1111'], ['127.0.0.1']));
    foreach (['', '1.1.1.1, 8.8.8.8', '127.0.0.1', '1.1.1.1:443', []] as $value) {
        rejects(function () use ($value) { ReliablesiteInstantKvm::sourceIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => $value], ['127.0.0.1']); });
    }
    rejects(function () { ReliablesiteInstantKvm::sourceIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8'], []); });
});
test('browser IP rejects mapped private, shared and multicast addresses', function () {
    foreach (['::ffff:127.0.0.1', '::ffff:10.0.0.1', '100.64.0.1', '224.0.0.1', 'ff02::1'] as $ip) {
        rejects(function () use ($ip) { ReliablesiteInstantKvm::sourceIp(['REMOTE_ADDR' => $ip], []); });
    }
    same('::ffff:8.8.8.8', ReliablesiteInstantKvm::sourceIp(['REMOTE_ADDR' => '::ffff:8.8.8.8'], []));
});
$failed = 0;
foreach ($tests as $name => $fn) { try { $fn(); echo "PASS $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; } }
echo count($tests) . " tests, $failed failures\n";
exit($failed ? 1 : 0);
