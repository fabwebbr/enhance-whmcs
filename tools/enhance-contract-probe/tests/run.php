<?php
if (PHP_SAPI !== 'cli') exit(2);
foreach (array_merge(get_extension_funcs('curl') ?: [], get_extension_funcs('sockets') ?: [],
    ['curl_init', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'stream_socket_sendto', 'exec', 'shell_exec', 'proc_open']) as $function) {
    if (function_exists($function)) { fwrite(STDERR, "Isolation required.\n"); exit(2); }
}
if (ini_get('allow_url_fopen')) exit(2);
define('PROBE_TEST_ISOLATED', true);
set_error_handler(static function (): never { throw new RuntimeException('local_test_error'); });
require dirname(__DIR__) . '/Cli.php';
require __DIR__ . '/FakeTransport.php';
$fixtures = require __DIR__ . '/fixtures.php';
use EnhanceProbe\Probe;
use EnhanceProbe\Cli;
use EnhanceProbe\Response;
use EnhanceProbe\Structure;
use EnhanceProbe\Tests\FakeTransport;
const SECRET = 'PROBE_SENTINEL_KEY_184d';
const SESSION_SECRET = 'PROBE_SENTINEL_SESSION_KEY_249c_32characters';
const SENSITIVE = ['PROBE_SENTINEL_KEY_184d', 'PROBE_SENTINEL_SESSION_KEY_249c_32characters',
    'PROBE_SENTINEL_PASSWORD_847c', 'PROBE_SENTINEL_ID_426d', 'PROBE_SENTINEL_TOKEN_172a',
    'probe-sentinel-mail@example.invalid', 'probe-sentinel-domain.invalid',
    'https://example.invalid/sso/PROBE_SENTINEL_SSO_32e9', 'PROBE_SENTINEL_SSO_32e9', 'Authorization'];
function check($condition): void { if (!$condition) throw new RuntimeException('assertion_failed'); }
function safe($value): void {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    foreach (SENSITIVE as $secret) check(!str_contains($json, $secret));
}
function rejects(callable $call): void {
    try { $call(); } catch (Throwable $e) { safe($e->getMessage()); if ($e instanceof \EnhanceProbe\Refusal) { safe(serialize($e)); safe((string) $e); safe($e->__debugInfo()); } return; }
    throw new RuntimeException('expected_refusal');
}
function setupProbe(): array {
    $directory = sys_get_temp_dir() . '/enhance-probe-test-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $GLOBALS['directories'][] = $directory;
    $config = ['environment' => 'homologation', 'host' => 'lab.example.invalid', 'pinned_ip' => '93.184.216.34',
        'tls_verify' => true, 'blocked_hosts' => ['production.example.invalid'], 'blocked_ips' => ['93.184.216.35'],
        'master_org' => 'fictional-master', 'plan_a' => 1, 'plan_b' => 2, 'session_directory' => $directory];
    $env = ['ENHANCE_PROBE_ENV' => 'homologation', 'ENHANCE_PROBE_API_KEY' => SECRET, 'ENHANCE_PROBE_SESSION_KEY' => SESSION_SECRET];
    $transport = new FakeTransport();
    return [new Probe($transport), $transport, $config, $env];
}
function prepare(): array {
    [$probe, $fake, $config, $env] = setupProbe();
    $session = $probe->initialize($config, $env)['session'];
    foreach (['org', 'login', 'subscription', 'website'] as $kind) {
        $fake->queue[] = new Response($GLOBALS['fixtures'][$kind], 201, 0, 1);
        $report = $probe->run('create-' . $kind, $config, $env, $session, true, true, 'CONFIRM ' . $session);
        check($report['phase_1b_category'] === 'success'); safe($report);
    }
    return [$probe, $fake, $config, $env, $session];
}
$tests = [];
$tests['default CLI is dry-run without environment or config'] = function () {
    $fake = new FakeTransport();
    [$exit, $output] = Cli::run([], [], $fake, fn() => '');
    check($exit === 0 && $output['mode'] === 'dry-run' && $fake->requests === []); safe($output);
};
$tests['every operation without execute stays dry-run'] = function () {
    [$probe, $fake] = setupProbe();
    foreach (EnhanceProbe\Catalog::all() as $operation => $entry) {
        check($probe->run($operation, [], [])['requests'] === 0);
    }
    check($fake->requests === []);
};
foreach (['marker', 'config-marker', 'host', 'ip', 'tls', 'blocklist', 'token'] as $case) {
    $tests['refuse invalid environment ' . $case] = function () use ($case) {
        [$probe, $fake, $config, $env] = setupProbe();
        switch ($case) {
            case 'marker': unset($env['ENHANCE_PROBE_ENV']); break;
            case 'config-marker': $config['environment'] = 'production'; break;
            case 'host': $config['host'] = 'alias.production.example.invalid'; break;
            case 'ip': $config['pinned_ip'] = '93.184.216.35'; break;
            case 'tls': $config['tls_verify'] = false; break;
            case 'blocklist': $config['blocked_ips'] = []; break;
            case 'token': unset($env['ENHANCE_PROBE_API_KEY']); break;
        }
        rejects(fn() => $probe->run('licence', $config, $env, null, true)); check($fake->requests === []);
    };
}
foreach (['--insecure', '--api-key', '--id', '--url', '--header'] as $option) {
    $tests['CLI rejects unsafe option ' . $option] = function () use ($option) {
        $fake = new FakeTransport();
        [$exit, $output] = Cli::run([$option, SECRET], [], $fake, fn() => '');
        check($exit === 2 && $fake->requests === []); safe($output);
    };
}
$tests['mutation requires second opt-in'] = function () {
    [$p, $f, $c, $e] = setupProbe();
    rejects(fn() => $p->run('create-org', $c, $e, null, true)); check($f->requests === []);
};
$tests['DELETE cannot take an ID without a manifest'] = function () {
    [$p, $f, $c, $e] = setupProbe();
    rejects(fn() => $p->run('delete-org', $c, $e, null, true, true)); check($f->requests === []);
};
$tests['session must have resources created by this probe'] = function () {
    [$p, $f, $c, $e] = setupProbe(); $s = $p->initialize($c, $e)['session'];
    rejects(fn() => $p->run('delete-org', $c, $e, $s, true, true, 'CONFIRM ' . $s)); check($f->requests === []);
};
$tests['typed confirmation must match session'] = function () {
    [$p, $f, $c, $e] = setupProbe(); $s = $p->initialize($c, $e)['session'];
    rejects(fn() => $p->run('create-org', $c, $e, $s, true, true, 'CONFIRM wrong')); check($f->requests === []);
};
$tests['edited manifest cannot inject preexisting ID'] = function () {
    [$p, $f, $c, $e, $s] = prepare();
    $file = $c['session_directory'] . '/session-' . $s . '.json';
    $data = json_decode(file_get_contents($file), true);
    $data['data']['resources']['org']['id'] = 'preexisting-org';
    file_put_contents($file, json_encode($data));
    rejects(fn() => $p->run('delete-org', $c, $e, $s, true, true, 'CONFIRM ' . $s)); check(count($f->requests) === 4);
};
$tests['manifest cannot cross target or master'] = function () {
    [$p, $f, $c, $e, $s] = prepare(); $c['master_org'] = 'other-master';
    rejects(fn() => $p->run('delete-org', $c, $e, $s, true, true, 'CONFIRM ' . $s)); check(count($f->requests) === 4);
};
$tests['created resources persisted with provenance and no credentials'] = function () {
    [$p, $f, $c, $e, $s] = prepare();
    $data = json_decode(file_get_contents($c['session_directory'] . '/session-' . $s . '.json'), true); safe($data);
    check(count($data['data']['resources']) === 4);
    foreach ($data['data']['resources'] as $r) check($r['created_by_session'] === $s);
    check(($f->requests[1]['body']['email']) === 'probe-' . $s . '@example.invalid');
    check($f->requests[3]['body']['domain'] === 'probe-' . $s . '.invalid');
    rejects(fn() => $p->run('create-org', $c, $e, $s, true, true, 'CONFIRM ' . $s)); check(count($f->requests) === 4);
};
$tests['nested response keys and values become structure only'] = function () {
    $value = (object) ['id' => SENSITIVE[3], 'name' => SENSITIVE[5], 'items' => [(object) ['name' => SENSITIVE[6],
        SENSITIVE[7] => (object) ['id' => SECRET]]], 'Authorization' => SECRET, 'token' => SECRET, 'url' => SENSITIVE[7]];
    $report = Structure::report('organisations', new Response(json_encode($value), 200, 0, 2), 'local-correlation');
    safe($report); check($report['structure']['properties']->id === '<string>');
    check($report['structure']['properties']->items['count'] === 1);
    check($report['structure']['properties']->items['item_shapes'][0]['unknown_value_shapes'][0]['properties']->id === '<string>');
};
foreach (['error-http', 'error-curl', 'invalid-json', 'null', 'list', 'string'] as $case) {
    $tests['sanitized response ' . $case] = function () use ($case) {
        $raw = match ($case) { 'invalid-json' => '{' . SECRET, 'null' => 'null', 'list' => json_encode(SENSITIVE), 'string' => json_encode(SECRET), default => implode(' ', SENSITIVE) };
        $report = Structure::report('licence', new Response($raw, $case === 'error-http' ? 500 : 200, $case === 'error-curl' ? 28 : 0, 9), 'local');
        safe($report);
        check(!in_array($report['phase_1b_category'], ['success'], true));
    };
}
$tests['transport exception message does not become a diagnostic'] = function () {
    [$p, $f, $c, $e] = setupProbe(); $f->queue[] = new RuntimeException(implode(' ', SENSITIVE));
    $r = $p->run('licence', $c, $e, null, true); safe($r); check($r['errno'] === 2 && count($f->requests) === 1);
};
foreach (['timeout', 'indeterminate', 'http-error'] as $failure) {
    $tests['mutation ' . $failure . ' locks session without retry'] = function () use ($failure) {
        [$p, $f, $c, $e, $s] = prepare();
        $f->queue[] = new Response(implode(' ', SENSITIVE), $failure === 'http-error' ? 500 : 200, $failure === 'timeout' ? 28 : 0, 1);
        if ($failure === 'indeterminate') $f->queue[0] = new Response('{}', 200, 0, 1);
        $r = $p->run('rename-org', $c, $e, $s, true, true, 'CONFIRM ' . $s);
        safe($r); check($r['session_state'] === 'indeterminate');
        rejects(fn() => $p->run('delete-org', $c, $e, $s, true, true, 'CONFIRM ' . $s));
        check(count($f->requests) === 5);
    };
}
foreach (['owner', 'rename-org', 'suspend-org', 'reactivate-org', 'change-plan', 'suspend-subscription',
    'reactivate-subscription', 'recover-password', 'delete-website', 'delete-subscription-soft', 'delete-subscription-hard', 'delete-org'] as $operation) {
    $tests['unknown contract remains closed ' . $operation] = function () use ($operation) {
        [$p, $f, $c, $e, $s] = prepare(); $f->queue[] = new Response('{}', 200, 0, 1);
        $r = $p->run($operation, $c, $e, $s, true, true, 'CONFIRM ' . $s);
        check($r['phase_1b_category'] === 'indeterminate' && $r['session_state'] === 'indeterminate'); safe($r);
        check(!str_contains($r['route'], '?') && !str_contains($r['route'], 'fictional'));
        rejects(fn() => $p->run('delete-org', $c, $e, $s, true, true, 'CONFIRM ' . $s)); check(count($f->requests) === 5);
    };
}
$tests['CLI fake execution only prints sanitized report'] = function () {
    [$p, $f, $c, $e] = setupProbe();
    $file = $c['session_directory'] . '/config.json'; file_put_contents($file, json_encode($c));
    $f->queue[] = new Response(json_encode(['valid' => true, 'Authorization' => SECRET]), 200, 0, 1);
    [$exit, $out] = Cli::run(['--execute', '--config', $file], $e, $f, fn() => '');
    check($exit === 3 && count($f->requests) === 1); safe($out);
};
$tests['indeterminate read cannot be followed by a mutation'] = function () {
    [$p, $f, $c, $e, $s] = prepare(); $f->queue[] = new Response('{}', 202, 0, 1);
    $r = $p->run('organisation', $c, $e, $s, true);
    check($r['session_state'] === 'indeterminate');
    rejects(fn() => $p->run('delete-org', $c, $e, $s, true, true, 'CONFIRM ' . $s)); check(count($f->requests) === 5);
};
$tests['master ID in creation response never becomes disposable'] = function () {
    [$p, $f, $c, $e] = setupProbe(); $s = $p->initialize($c, $e)['session'];
    $f->queue[] = new Response('{"id":"fictional-master"}', 201, 0, 1);
    $r = $p->run('create-org', $c, $e, $s, true, true, 'CONFIRM ' . $s);
    check($r['session_state'] === 'indeterminate');
    rejects(fn() => $p->run('delete-org', $c, $e, $s, true, true, 'CONFIRM ' . $s)); check(count($f->requests) === 1);
};
$tests['synthetic structural fixture is reproducible without promoting success'] = function () {
    $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/examples/fixture.synthetic.json'), true, 64, JSON_THROW_ON_ERROR);
    $report = Structure::report('licence', new Response('{"valid":true}', 200, 0, 1), 'local');
    $actual = json_decode(json_encode(array_intersect_key($report, $fixture['observation'])), true);
    check($actual === $fixture['observation'] && $report['phase_1b_category'] === 'indeterminate'); safe($actual);
};
foreach ([
    'licence' => ['valid' => true],
    'plans' => ['items' => [['id' => 1, 'name' => 'Fictional']]],
    'organisations' => ['items' => [['id' => 'fictional-org', 'name' => 'Fictional']]],
    'organisation' => ['id' => 'fictional-org', 'name' => 'Fictional'],
    'members' => ['items' => [['id' => 'fictional-member', 'roles' => ['Owner'], 'isActive' => true]]],
    'emails' => ['total' => 0],
    'logins' => ['items' => [['id' => 'fictional-login', 'email' => SENSITIVE[5]]]],
    'subscriptions' => ['items' => [['id' => 123]]],
    'subscription' => ['id' => 123, 'isSuspended' => false],
    'websites' => ['items' => [['id' => 'fictional-website']]],
    'subscription-websites' => ['items' => [['id' => 'fictional-website']]],
    'website' => ['id' => 'fictional-website'],
    'domains' => ['items' => [SENSITIVE[6]]],
] as $operation => $body) {
    $tests['prepared read uses only fake transport ' . $operation] = function () use ($operation, $body) {
        [$p, $f, $c, $e, $s] = prepare(); $f->queue[] = new Response(json_encode($body), 200, 0, 1);
        $r = $p->run($operation, $c, $e, $s, true);
        check($r['phase_1b_category'] === ($operation === 'licence' ? 'indeterminate' : 'success'));
        check(count($f->requests) === 5 && $f->requests[4]['method'] === 'GET');
        check(!str_contains($r['route'], '?') && !str_contains($r['route'], 'fictional'));
        safe($r);
    };
}
$tests['private response serialization omits raw body'] = function () {
    $response = new Response(implode(' ', SENSITIVE), 200, 0, 1);
    safe(serialize($response)); safe($response->__debugInfo()); safe($response);
};
$tests['CLI never signals success for locally blocked creation'] = function () {
    [$p, $f, $c, $e] = setupProbe(); $s = $p->initialize($c, $e)['session'];
    $file = $c['session_directory'] . '/config.json'; file_put_contents($file, json_encode($c));
    $f->queue[] = new Response('{"id":"fictional-master"}', 201, 0, 1);
    [$exit, $report] = Cli::run(['--execute', '--allow-mutation', '--config', $file,
        '--operation', 'create-org', '--session', $s], $e, $f, fn() => 'CONFIRM ' . $s);
    check($exit === 3 && $report['session_state'] === 'indeterminate'); safe($report);
};
require __DIR__ . '/p1-regressions.php';
$passed = 0;
try {
    foreach ($tests as $name => $test) {
        ob_start(); $test(); $output = ob_get_clean(); check($output === ''); safe($output);
        $passed++; echo 'PASS: ' . $name . PHP_EOL;
    }
    echo $passed . " probe tests passed; fake transport only.\n";
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR, 'FAIL: ' . ($name ?? 'setup') . " (details suppressed)\n"); exit(1);
} finally {
    foreach ($GLOBALS['directories'] ?? [] as $directory) {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}
