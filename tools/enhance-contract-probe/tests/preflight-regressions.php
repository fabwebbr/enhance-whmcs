<?php
if (!defined('PROBE_TEST_ISOLATED')) throw new RuntimeException('isolated_runner_required');
require __DIR__ . '/FakeCurl.php';
function preflightRefused(callable $call, string $reason): void
{
    try { $call(); }
    catch (\EnhanceProbe\Refusal $e) {
        check($e->getMessage() === $reason);
        safe($e->getMessage()); safe(serialize($e)); safe((string) $e);
        return;
    }
    throw new RuntimeException('expected_specific_refusal');
}
foreach (['matching' => '93.184.216.34', 'mapped' => '::ffff:93.184.216.34',
    'different' => '93.184.216.36', 'missing' => '', 'invalid' => 'PROBE_SENTINEL_TOKEN_172a',
    'forbidden' => '127.0.0.1', 'mapped forbidden' => '::ffff:127.0.0.1'] as $case => $peer) {
    $tests['connected peer ' . $case] = function () use ($case, $peer) {
        [$p, $f, $c, $e] = setupProbe();
        $file = $c['session_directory'] . '/config.json'; file_put_contents($file, json_encode($c));
        \EnhanceProbe\FakeCurl::reset(['peer' => $peer, 'body' => json_encode(['items' => [], 'token' => SECRET])]);
        [$exit, $report] = \EnhanceProbe\Cli::run(['--execute', '--config', $file, '--operation', 'plans'],
            $e, new \EnhanceProbe\CurlTransport(), fn() => throw new RuntimeException('unexpected_confirmation'));
        $accepted = in_array($case, ['matching', 'mapped'], true);
        check($exit === ($accepted ? 0 : 2)); safe($report);
        check(count(\EnhanceProbe\FakeCurl::$requests) === 1 && \EnhanceProbe\FakeCurl::$closed === 1);
        check(\EnhanceProbe\FakeCurl::$info[0] === CURLINFO_PRIMARY_IP);
        if ($accepted) check($report['phase_1b_category'] === 'success');
        else {
            check(array_keys($report) === ['error', 'instruction']);
            check(\EnhanceProbe\FakeCurl::$info === [CURLINFO_PRIMARY_IP]);
        }
        foreach (['93.184.216.34', '93.184.216.36', '127.0.0.1'] as $ip) check(!str_contains(json_encode($report), $ip));
        check(\EnhanceProbe\FakeCurl::$requests[0][CURLOPT_CUSTOMREQUEST] === 'GET');
        check(\EnhanceProbe\FakeCurl::$requests[0][CURLOPT_RESOLVE] === ['lab.example.invalid:443:93.184.216.34']);
    };
}
$tests['untrusted response body discarded before any contract report'] = function () {
    \EnhanceProbe\FakeCurl::reset(['peer' => '', 'body' => implode(' ', SENSITIVE)]);
    $response = (new \EnhanceProbe\CurlTransport())->request(['host' => 'lab.example.invalid', 'ip' => '93.184.216.34'], SECRET, 'GET', '/licence', []);
    check(!$response->trusted && $response->body() === ''); safe($response); safe(serialize($response));
    rejects(fn() => \EnhanceProbe\Structure::report('licence', $response, 'local'));
};
$tests['peer divergence halts session and prevents subsequent mutation'] = function () {
    [$p, $f, $c, $e, $s] = prepare();
    \EnhanceProbe\FakeCurl::reset(['peer' => '93.184.216.36', 'body' => '{"id":"fictional-org","name":"Fictional"}']);
    $realCodeFakeNetwork = new \EnhanceProbe\Probe(new \EnhanceProbe\CurlTransport());
    rejects(fn() => $realCodeFakeNetwork->run('organisation', $c, $e, $s, true));
    rejects(fn() => $realCodeFakeNetwork->run('delete-org', $c, $e, $s, true, true, 'CONFIRM ' . $s));
    check(count(\EnhanceProbe\FakeCurl::$requests) === 1);
    $manifest = json_decode(file_get_contents($c['session_directory'] . '/session-' . $s . '.json'), true);
    check($manifest['data']['state'] === 'indeterminate'); safe($manifest);
    check(!str_contains(json_encode($manifest), '93.184.216.36'));
};
foreach (['licence', 'plans', 'organisations'] as $operation) {
    $tests['minimal read-only config ' . $operation] = function () use ($operation) {
        [$p, $f, $c, $e] = setupProbe(); $dir = $c['session_directory'];
        unset($c['plan_a'], $c['plan_b'], $c['session_directory'], $e['ENHANCE_PROBE_SESSION_KEY']);
        if ($operation === 'licence') unset($c['master_org']);
        $file = $dir . '/config.json'; file_put_contents($file, json_encode($c));
        $f->queue[] = new \EnhanceProbe\Response($operation === 'licence' ? '{"valid":true}' : '{"items":[]}', 200, 0, 1);
        [$exit, $report] = \EnhanceProbe\Cli::run(['--execute', '--config', $file, '--operation', $operation],
            $e, $f, fn() => throw new RuntimeException('unexpected_confirmation'));
        check($exit === ($operation === 'licence' ? 3 : 0)); safe($report);
        check(count($f->requests) === 1 && $f->requests[0]['method'] === 'GET' && $f->requests[0]['body'] === []);
        check(count(glob($dir . '/*')) === 1); // Only the test's config; no journal/manifest.
    };
}
foreach (['plans', 'organisations'] as $operation) {
    $tests['master required for ' . $operation] = function () use ($operation) {
        [$p, $f, $c, $e] = setupProbe(); unset($c['master_org']);
        rejects(fn() => $p->run($operation, $c, $e, null, true)); check($f->requests === []);
    };
}
foreach (\EnhanceProbe\Catalog::all() as $operation => $entry) {
    if ($entry[0] === 'GET') continue;
    $tests['mutation retains complete schema and gates ' . $operation] = function () use ($operation) {
        [$p, $f, $c, $e] = setupProbe();
        // Schema validation is tested directly so session/resource gates cannot
        // mask a missing field. A complete config must first be accepted.
        \EnhanceProbe\LocalConfig::validate($c, $operation);
        $s = $p->initialize($c, $e)['session'];
        $manifest = $c['session_directory'] . '/session-' . $s . '.json';
        $before = file_get_contents($manifest);
        $constructed = 0;
        $guarded = new \EnhanceProbe\Probe(static function () use (&$constructed, $f) {
            $constructed++;
            return $f;
        });
        foreach (array_keys($c) as $field) {
            $missing = $c; unset($missing[$field]);
            check(array_diff_key($c, $missing) === [$field => $c[$field]]);
            preflightRefused(fn() => \EnhanceProbe\LocalConfig::validate($missing, $operation), 'invalid_config_fields');
            // Also prove the public flow refuses at schema validation, before
            // opening the valid session or constructing its transport.
            preflightRefused(fn() => $guarded->run($operation, $missing, $e, $s, true, true, 'CONFIRM ' . $s), 'invalid_config_fields');
            check($constructed === 0 && $f->requests === []);
            check(file_get_contents($manifest) === $before);
        }
        preflightRefused(fn() => $guarded->run($operation, $c, $e, $s, true, false, 'CONFIRM ' . $s), 'mutation_opt_in_required');
        preflightRefused(fn() => $guarded->run($operation, $c, $e, null, true, true), 'session_confirmation_required');
        $withoutKey = $e; unset($withoutKey['ENHANCE_PROBE_SESSION_KEY']);
        preflightRefused(fn() => $guarded->run($operation, $c, $withoutKey, $s, true, true, 'CONFIRM ' . $s), 'invalid_session');
        preflightRefused(fn() => $guarded->run($operation, $c, $e, $s, true, true, 'wrong'), 'session_confirmation_required');
        check($constructed === 0 && $f->requests === []);
        check(file_get_contents($manifest) === $before);
    };
}
$tests['allowed metadata preserves structure without sensitive values'] = function () {
    $body = ['items' => [['id' => SENSITIVE[3], 'name' => SENSITIVE[5], 'url' => SENSITIVE[7],
        'domain' => SENSITIVE[6], 'message' => SECRET, 'Authorization' => SECRET,
        'ip' => '93.184.216.34', 'cookie' => SECRET]]];
    $r = \EnhanceProbe\Structure::report('organisations', new \EnhanceProbe\Response(json_encode($body), 200, 0, 7), 'local');
    check(array_keys($r) === ['version', 'correlation', 'method', 'route', 'http', 'errno', 'duration_ms',
        'body_present', 'format', 'json_valid', 'root', 'structure', 'consumed_fields_present', 'phase_1b_category']);
    check($r['route'] === '/orgs/{id}/customers' && $r['http'] === 200 && $r['duration_ms'] === 7);
    check($r['structure']['properties']->items['count'] === 1 && $r['phase_1b_category'] === 'success');
    safe($r); check(!str_contains(json_encode($r), '93.184.216.34'));
};

foreach (['licence', 'plans', 'organisations'] as $operation) {
    $tests['read-only required fields refuse before transport ' . $operation] = function () use ($operation) {
        [$p, $f, $c, $e] = setupProbe();
        $directory = $c['session_directory'];
        unset($c['plan_a'], $c['plan_b'], $c['session_directory'], $e['ENHANCE_PROBE_SESSION_KEY']);
        if ($operation === 'licence') unset($c['master_org']);
        \EnhanceProbe\LocalConfig::validate($c, $operation);
        $constructed = 0;
        $guarded = new \EnhanceProbe\Probe(static function () use (&$constructed, $f) {
            $constructed++;
            return $f;
        });
        foreach (array_keys($c) as $field) {
            $missing = $c; unset($missing[$field]);
            preflightRefused(fn() => \EnhanceProbe\LocalConfig::validate($missing, $operation), 'invalid_config_fields');
            preflightRefused(fn() => $guarded->run($operation, $missing, $e, null, true), 'invalid_config_fields');
            check($constructed === 0 && $f->requests === [] && glob($directory . '/*') === []);
        }
    };
}
