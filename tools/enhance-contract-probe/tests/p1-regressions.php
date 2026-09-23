<?php
if (!defined('PROBE_TEST_ISOLATED')) throw new RuntimeException('isolated_runner_required');
use EnhanceProbe\LocalConfig;
use EnhanceProbe\IpPolicy;
use EnhanceProbe\Probe;
use EnhanceProbe\Cli;
use EnhanceProbe\Response;

final class P1ForbiddenWrapper
{
    public $context;
    public static int $calls = 0;
    public function stream_open(...$args): bool { self::$calls++; throw new RuntimeException(SECRET); }
    public function url_stat(...$args): array { self::$calls++; throw new RuntimeException(SECRET); }
}
function configRefused(string $path): void {
    $fake = new \EnhanceProbe\Tests\FakeTransport();
    [$exit, $report] = Cli::run(['--execute', '--config', $path], [], $fake, fn() => '');
    check($exit === 2 && $fake->requests === []); safe($report);
}
foreach (['http', 'https', 'ftp', 'php', 'data', 'phar', 'zip', 'glob', 'p1custom'] as $scheme) {
    $tests['P1 wrapper never invoked ' . $scheme] = function () use ($scheme) {
        $existed = in_array($scheme, stream_get_wrappers(), true);
        if ($existed) stream_wrapper_unregister($scheme);
        stream_wrapper_register($scheme, P1ForbiddenWrapper::class);
        P1ForbiddenWrapper::$calls = 0;
        try {
            configRefused($scheme . '://' . SECRET);
            configRefused(strtoupper($scheme) . '://' . SECRET);
            check(P1ForbiddenWrapper::$calls === 0);
        } finally {
            stream_wrapper_unregister($scheme);
            if ($existed) stream_wrapper_restore($scheme);
        }
    };
}
foreach (['', 'file:///tmp/config.json', ' HTTP://example.invalid/config', "http:\t//example.invalid", 'http%3a%2f%2fexample.invalid',
    '%68ttp://example.invalid', 'php%253A%252F%252Fmemory', "http:\\example.invalid", "config\0.json", "config\n.json", 'config file.json',
    'config-é.json', '../config.json', '//tmp/config.json', 'http：//example.invalid'] as $index => $path) {
    $tests['P1 nonlocal or ambiguous config path ' . $index] = static fn() => configRefused($path);
}
foreach (['missing', 'directory', 'symlink', 'ancestor-symlink', 'device', 'oversize', 'fifo'] as $kind) {
    $tests['P1 reject config file kind ' . $kind] = function () use ($kind) {
        [$p, $f, $c, $e] = setupProbe(); $dir = $c['session_directory'];
        $file = $dir . '/config.json'; file_put_contents($file, json_encode($c));
        switch ($kind) {
            case 'missing': $path = $dir . '/missing.json'; break;
            case 'directory': $path = $dir; break;
            case 'symlink': $path = $dir . '/link.json'; symlink($file, $path); break;
            case 'ancestor-symlink':
                $path = $dir . '/linked'; symlink($dir, $path); $path .= '/config.json'; break;
            case 'device': $path = '/dev/null'; break;
            case 'oversize': $path = $dir . '/large.json'; file_put_contents($path, str_repeat(' ', LocalConfig::MAX_BYTES + 1)); break;
            case 'fifo':
                // The validation container must provide POSIX; do not silently skip.
                check(function_exists('posix_mkfifo'));
                $path = $dir . '/pipe'; check(posix_mkfifo($path, 0600)); break;
        }
        configRefused($path);
    };
}
foreach (['absolute', 'relative'] as $kind) {
    $tests['P1 local regular config accepted ' . $kind] = function () use ($kind) {
        [$p, $f, $c, $e] = setupProbe(); $file = $c['session_directory'] . '/config.json';
        file_put_contents($file, json_encode($c)); $cwd = getcwd();
        try {
            if ($kind === 'relative') { chdir($c['session_directory']); $file = './config.json'; }
            check(LocalConfig::load($file) === $c);
            $f->queue[] = new Response('{}', 200, 0, 1);
            [$exit, $report] = Cli::run(['--config', $file, '--execute'], $e, $f, fn() => '');
            check($exit === 3 && count($f->requests) === 1); safe($report);
        } finally { chdir($cwd); }
    };
}
foreach (['unknown-key', 'config-include', 'missing-key', 'wrong-type'] as $case) {
    $tests['P1 full configuration validation ' . $case] = function () use ($case) {
        [$p, $f, $c, $e] = setupProbe();
        if ($case === 'unknown-key') $c['api_key'] = SECRET;
        if ($case === 'config-include') $c['include'] = 'p1custom://ignored';
        if ($case === 'missing-key') unset($c['plan_b']);
        if ($case === 'wrong-type') $c['tls_verify'] = 'true';
        $file = $c['session_directory'] . '/config.json'; file_put_contents($file, json_encode($c));
        [$exit, $r] = Cli::run(['--execute', '--config', $file], $e, $f, fn() => '');
        check($exit === 2 && $f->requests === []); safe($r);
    };
}
foreach ([
    '93.184.216.34' => '93.184.216.34', '::ffff:93.184.216.34' => '93.184.216.34',
    '0:0:0:0:0:FFFF:5DB8:D822' => '93.184.216.34',
    '2606:4700:4700::1111' => '2606:4700:4700::1111',
    '2606:4700:4700:0000:0000:0000:0000:1111' => '2606:4700:4700::1111',
    '2A00:1450:4001:081A:0000:0000:0000:200E' => '2a00:1450:4001:81a::200e',
] as $input => $expected) {
    $tests['P1 canonical IP ' . $input] = function () use ($input, $expected) {
        check(IpPolicy::canonical($input) === $expected && IpPolicy::same($input, $expected));
        [$p, $f, $c, $e] = setupProbe(); $c['pinned_ip'] = $input; $f->queue[] = new Response('{}', 200, 0, 1);
        $r = $p->run('licence', $c, $e, null, true);
        check(count($f->requests) === 1 && $f->requests[0]['target']['ip'] === $expected); safe($r);
    };
}
foreach ([
    '0.0.0.0', '0.255.255.255', '10.0.0.0', '10.255.255.255', '100.64.0.0', '100.127.255.255',
    '127.0.0.1', '127.255.255.255', '169.254.0.0', '169.254.169.254', '169.254.255.255',
    '172.16.0.0', '172.31.255.255', '192.0.0.0', '192.0.0.255', '192.0.2.0', '192.0.2.255',
    '192.88.99.0', '192.88.99.255', '192.168.0.0', '192.168.255.255', '198.18.0.0', '198.19.255.255',
    '198.51.100.0', '198.51.100.255', '203.0.113.0', '203.0.113.255', '224.0.0.0', '239.255.255.255',
    '240.0.0.0', '255.255.255.255',
    '::', '::1', '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:192.0.2.20',
    '64:ff9b::808:808', '64:ff9b:1::1', '100::', '100::ffff:ffff:ffff:ffff',
    '2001:db8::', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', '2001:10::', '2001:1f:ffff:ffff:ffff:ffff:ffff:ffff',
    '2001::1', '2001:2::1', '2001:20::1', '2002:0808:0808::1',
    'fc00::', 'fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', 'fe80::1', 'febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
    'ff00::1', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '3ffe::1', '3fff::1', '5f00::1',
] as $ip) {
    $tests['P1 deny special range ' . $ip] = function () use ($ip) {
        [$p, $f, $c, $e] = setupProbe(); $c['pinned_ip'] = $ip;
        rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
        if (!str_contains($ip, ':')) {
            $c['pinned_ip'] = '::ffff:' . $ip;
            rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
        }
    };
}
foreach (['2130706433', '0x7f000001', '0177.0.0.1', '127.1', '127.0.1', '127.000.0.1', '1.2.3.04',
    '0x7f.0.0.1', '1.2.3.256', '1.2.3.4.', '1.2.3.4:443', 'fe80::1%eth0', '2606:4700::1111%1',
    '::ffff:0127.0.0.1', '::ffff:127.1', '[2606:4700::1111]', 'example.invalid', ' 8.8.8.8', "8.8.8.8\n", '', SECRET] as $index => $ip) {
    $tests['P1 reject invalid IP notation ' . $index] = function () use ($ip) {
        [$p, $f, $c, $e] = setupProbe(); $c['pinned_ip'] = $ip;
        rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
    };
}
foreach (['2130706433', '0x7f000001', '0177.0.0.1', '127.1', '127.0.1', '127.000.0.1', '0x7f.0.0.1',
    'lab.example.invalid.', 'https://lab.example.invalid', 'lab.example.invalid:443', 'user@lab.example.invalid',
    'lab.example.invalid/path', 'lab.example.invalid?query', 'lab.example.invalid#fragment', 'lab%2eexample.invalid', 'labé.example.invalid'] as $index => $host) {
    $tests['P1 hostname does not reinterpret unsafe notation ' . $index] = function () use ($host) {
        [$p, $f, $c, $e] = setupProbe(); $c['host'] = $host;
        rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
    };
}
foreach ([['93.184.216.34', '::ffff:93.184.216.34'], ['::ffff:93.184.216.34', '93.184.216.34'],
    ['0:0:0:0:0:FFFF:5DB8:D822', '93.184.216.34'],
    ['2606:4700:4700::1111', '2606:4700:4700:0:0:0:0:1111']] as $index => [$ip, $blocked]) {
    $tests['P1 production IP canonical equivalence ' . $index] = function () use ($ip, $blocked) {
        [$p, $f, $c, $e] = setupProbe(); $c['pinned_ip'] = $ip; $c['blocked_ips'] = [$blocked];
        rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
    };
}
foreach (['not-an-ip', '127.1', '::ffff:127.1', 'fe80::1%1', SECRET] as $index => $invalid) {
    $tests['P1 malformed production list fails closed ' . $index] = function () use ($invalid) {
        [$p, $f, $c, $e] = setupProbe(); $c['blocked_ips'][] = $invalid;
        rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
    };
}
foreach (['PRODUCTION.EXAMPLE.INVALID', 'alias.Production.Example.Invalid'] as $host) {
    $tests['P1 production hostname case and subdomain ' . $host] = function () use ($host) {
        [$p, $f, $c, $e] = setupProbe(); $c['host'] = $host;
        rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
    };
}
$tests['P1 known alias cannot bypass blocked IP'] = function () {
    [$p, $f, $c, $e] = setupProbe(); $c['host'] = 'other-alias.example.invalid'; $c['blocked_ips'][] = $c['pinned_ip'];
    rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
};
$tests['P1 lazy transport constructed only after all gates'] = function () {
    [$p, $f, $c, $e] = setupProbe(); $constructed = 0;
    $factory = function () use (&$constructed, $f) { $constructed++; return $f; };
    $p = new Probe($factory);
    $p->run('licence', [], []); check($constructed === 0);
    $bad = $c; $bad['pinned_ip'] = '127.0.0.1';
    rejects(fn() => $p->run('licence', $bad, $e, null, true));
    rejects(fn() => $p->run('create-org', $c, $e, null, true, true));
    check($constructed === 0 && $f->requests === []);
    $f->queue[] = new Response('{}', 200, 0, 1);
    $p->run('licence', $c, $e, null, true); check($constructed === 1 && count($f->requests) === 1);
};

foreach ([['--execute=false'], ['--exec'], ['--execute', '--execute'],
    ['--operation', 'licence', '--operation', 'plans'], ['--EXECUTE'],
    ['--execute', '--init-session', '--operation', 'licence']] as $index => $args) {
    $tests['P1 strict argument parsing ' . $index] = function () use ($args) {
        $calls = 0;
        $factory = function () use (&$calls) { $calls++; return new \EnhanceProbe\Tests\FakeTransport(); };
        [$exit, $report] = Cli::run($args, [], $factory, fn() => '');
        check($exit === 2 && $calls === 0); safe($report);
    };
}
foreach (['0', 'false', 'no', '', ' ', 'homologation '] as $index => $marker) {
    $tests['P1 exact environment marker ' . $index] = function () use ($marker) {
        [$p, $f, $c, $e] = setupProbe(); $e['ENHANCE_PROBE_ENV'] = $marker;
        rejects(fn() => $p->run('licence', $c, $e, null, true)); check($f->requests === []);
    };
}
