<?php
/** Standalone, dependency-free tests. Run: php -n tests/run.php */
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "PHP 8.1 or newer required.\n"); exit(2);
}
if (function_exists('curl_init')) {
    fwrite(STDERR, "Refusing to run with native cURL. Use php -n; tests never use real HTTP.\n"); exit(2);
}
require __DIR__ . '/fakes.php';
define('WHMCS', true);
require __DIR__ . '/../modules/servers/enhance/EnhanceApi.php';
require __DIR__ . '/../modules/addons/enhance_importer/enhance_importer.php';

const PASSWORD = 'SENTINEL_PASSWORD_9f3a';
const API_KEY = 'SENTINEL_API_TOKEN_7b2c';
const AUTH = 'Bearer SENTINEL_AUTH_4d8e';
const SSO = 'https://example.invalid/sso/SENTINEL_SSO_f1a6';
const EMAIL = 'sentinel-user@example.invalid';

function check(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
}
function secrets(): array {
    return [PASSWORD, API_KEY, AUTH, 'SENTINEL_AUTH_4d8e', SSO,
        'SENTINEL_SSO_f1a6', EMAIL, serialize(['password' => PASSWORD]),
        base64_encode(serialize(['password' => PASSWORD]))];
}
function assertSafe($value): void {
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    foreach (secrets() as $secret) {
        check(!str_contains($encoded, $secret), 'Sentinel leaked to a persistence/output candidate');
    }
}
function api(bool $debug): EnhanceApi {
    // Skip constructor schema/email side effects; no WHMCS or real DB is needed.
    $reflection = new ReflectionClass(EnhanceApi::class);
    $api = $reflection->newInstanceWithoutConstructor();
    foreach (['host' => 'example.invalid', 'masterOrgId' => 'fake-master', 'apiKey' => API_KEY] as $name => $value) {
        $reflection->getProperty($name)->setValue($api, $value);
    }
    $api->debug = $debug;
    return $api;
}
function reply($raw, int $status = 200, string $error = '', int $errno = 0): void {
    $GLOBALS['httpQueue'][] = compact('raw', 'status', 'error', 'errno');
}
function request(): array { return $GLOBALS['requests'][array_key_last($GLOBALS['requests'])]; }
function lastLog(): array { return $GLOBALS['moduleLogs'][array_key_last($GLOBALS['moduleLogs'])]; }
function payload(): array {
    return ['token' => API_KEY, 'password' => PASSWORD, 'authorization' => AUTH,
        'secret' => PASSWORD, 'apiKey' => API_KEY, 'ToKeN' => API_KEY,
        'PASSWORD' => PASSWORD, 'AUTHORIZATION' => AUTH, 'SECRET' => PASSWORD,
        'APIKEY' => API_KEY, 'url' => SSO, 'email' => EMAIL,
        'nested' => [['password' => PASSWORD, 'values' => [API_KEY, SSO]]],
        'serialized' => serialize(['password' => PASSWORD]),
        'base64' => base64_encode(serialize(['password' => PASSWORD]))];
}
function fingerprints(): array {
    $root = dirname(__DIR__);
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (str_starts_with($relative, '.git/')) { continue; }
        if ($file->isFile()) { $files[$relative] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($files);
    return $files;
}

$tests = [];
foreach ([false, true] as $debug) {
    $suffix = $debug ? 'debug on' : 'debug off';
    $tests['login request and response preserved / ' . $suffix] = static function () use ($debug): void {
        reply(json_encode(['id' => 'fake-login'] + payload()));
        $result = api($debug)->createLogin('fake-org', EMAIL, PASSWORD, 'Fake User');
        check($result['password'] === PASSWORD && $result['token'] === API_KEY, 'Response was destructively sanitized');
        check(json_decode(request()[CURLOPT_POSTFIELDS], true) === ['email' => EMAIL, 'password' => PASSWORD, 'name' => 'Fake User'], 'Login body changed');
        check(in_array('Authorization: Bearer ' . API_KEY, request()[CURLOPT_HTTPHEADER], true), 'API authentication changed');
        check(in_array(API_KEY, lastLog()['replace'], true) && in_array(PASSWORD, lastLog()['replace'], true), 'Missing secondary secret replacements');
        check(request()[CURLOPT_URL] === 'https://example.invalid/api/logins?orgId=fake-org', 'Login endpoint changed');
        $metadata = lastLog()['output'][3];
        check(array_key_exists('duration_ms', $metadata) === $debug, 'Debug metadata incorrect');
        if ($debug) { check($metadata['duration_ms'] === 125, 'Duration incorrect'); }
    };
    $tests['password recovery / ' . $suffix] = static function () use ($debug): void {
        reply('', 204);
        $result = api($debug)->triggerPasswordRecovery(EMAIL);
        check(json_decode(request()[CURLOPT_POSTFIELDS], true) === ['email' => EMAIL], 'Recovery email changed');
        check($result === ['_raw' => '', '_httpCode' => 204], '204 return changed');
    };
    foreach ([SSO, json_encode(SSO), json_encode(['url' => SSO]), json_encode(['loginUrl' => SSO])] as $index => $raw) {
        $tests['SSO preserved format ' . $index . ' / ' . $suffix] = static function () use ($debug, $raw): void {
            reply(json_encode(['items' => [['id' => 'fake-owner', 'roles' => ['Owner'], 'isActive' => true]]]));
            reply($raw);
            check(api($debug)->getOwnerSsoUrl('fake-org') === SSO, 'SSO URL not returned intact');
        };
    }
    $tests['nested request and JSON response / ' . $suffix] = static function () use ($debug): void {
        reply(json_encode(payload()));
        $result = api($debug)->send('POST', '/logins', payload());
        check($result === payload() + ['_httpCode' => 200], 'Nested response changed');
        check(json_decode(request()[CURLOPT_POSTFIELDS], true) === payload(), 'Nested body changed');
        foreach (secrets() as $secret) {
            if (in_array($secret, ['SENTINEL_AUTH_4d8e', 'SENTINEL_SSO_f1a6'], true)) { continue; }
            check(in_array($secret, lastLog()['replace'], true), 'Nested redaction control missing');
        }
    };
    foreach ([200, 400, 401, 403, 429, 500] as $status) {
        foreach ([false, true] as $json) {
            $tests['HTTP ' . $status . ($json ? ' JSON / ' : ' raw / ') . $suffix] = static function () use ($debug, $status, $json): void {
                $raw = $json ? json_encode(payload()) : implode(' ', secrets());
                reply($raw, $status);
                $result = api($debug)->send('GET', '/orgs/fake-org');
                check($result['_httpCode'] === $status, 'HTTP code changed');
                if ($json) {
                    check($result['password'] === PASSWORD && $result['authorization'] === AUTH, 'Error/success JSON altered');
                } else {
                    check($result[$status >= 400 ? 'message' : '_raw'] === $raw, 'Raw response altered');
                }
                if ($status >= 400) { check($result['code'] === 'http_' . $status, 'HTTP error contract changed'); }
                check(lastLog()['output'][3]['result'] === ($status >= 400 ? 'http_error' : 'http_response'), 'Wrong generic HTTP category');
            };
        }
    }
    $tests['cURL error / ' . $suffix] = static function () use ($debug): void {
        $error = implode(' ', secrets());
        reply(false, 0, $error, 28);
        $result = api($debug)->send('GET', '/licence');
        check($result === ['code' => 'curl_error', 'message' => $error, '_httpCode' => 0], 'cURL return changed');
        check(lastLog()['output'][3]['curl_code'] === 28, 'Numeric cURL error missing');
        check(lastLog()['output'][3]['result'] === 'transport_error', 'Wrong transport category');
    };
    $tests['query and dynamic path sanitization / ' . $suffix] = static function () use ($debug): void {
        $path = '/orgs/' . PASSWORD . '/members/' . API_KEY . '/sso?email=' . EMAIL . '&url=' . SSO;
        reply(SSO);
        api($debug)->send('GET', $path);
        check(request()[CURLOPT_URL] === 'https://example.invalid/api' . $path, 'Original URL changed');
        check(lastLog()['output'][2]['endpoint'] === '/orgs/{id}/members/{id}/sso', 'Dynamic IDs not removed');
        check(lastLog()['output'][4] === [], 'Processed payload must be empty');
    };
}
$tests['unknown paths and methods fail closed'] = static function (): void {
    foreach ([SSO, '/unknown/' . PASSWORD, '/orgs/' . API_KEY . '/unexpected', '/logins#' . PASSWORD] as $path) {
        check(EnhanceLog::endpoint($path) === '[unrecognized endpoint]', 'Unknown endpoint was echoed');
    }
    assertSafe(EnhanceLog::metadata(PASSWORD, SSO, 400, 0, 0.1, true));
};
$tests['all current endpoint templates are recognized'] = static function (): void {
    foreach (['/licence', '/logins', '/login/password-recovery', '/orgs/id',
        '/orgs/id/customers', '/orgs/id/customers/id/subscriptions', '/orgs/id/subscriptions/id',
        '/orgs/id/plans', '/orgs/id/members', '/orgs/id/members/id/sso', '/orgs/id/websites',
        '/orgs/id/websites/id', '/orgs/id/websites/id/domains', '/orgs/id/emails'] as $path) {
        check(EnhanceLog::endpoint($path) !== '[unrecognized endpoint]', 'Known endpoint not recognized');
    }
};
$tests['snapshot insert and update sinks omit payloads'] = static function (): void {
    foreach ([false, true] as $exists) {
        \WHMCS\Database\Capsule::$exists = $exists;
        enhance_importer_save_map(1, '2', 3, 'Fake plan', payload());
        enhance_importer_save_service_log(1, 'fake-org', '2', 3, 4, 5, 'Active', payload());
    }
    foreach (\WHMCS\Database\Capsule::$writes as [$table, $operation, $data]) {
        assertSafe($data);
        check(($data['snapshot'] ?? $data['plan_snapshot']) === '{"payload_omitted":true}', 'Snapshot payload retained');
    }
};
$tests['activity sink expressions omit exception and result messages'] = static function (): void {
    // Execute ONLY the logging statements, never hook registration/cron/actions.
    // This also covers the separated hook whose existing require paths are broken.
    $e = new RuntimeException(implode(' ', secrets()));
    $vars = ['userid' => 123];
    $server = (object) ['id' => 456];
    $result = ['message' => implode(' ', secrets()), 'checked' => 3, 'updated' => 2];
    $count = 0;
    foreach (['enhance.php', 'enhance_importer_daily_sync.php'] as $file) {
        $source = file_get_contents(__DIR__ . '/../includes/hooks/' . $file);
        preg_match_all('/logActivity\([^;]+\);/', $source, $matches);
        foreach ($matches[0] as $statement) { eval($statement); $count++; }
    }
    check($count === 5, 'Activity sink inventory changed; review coverage');
    assertSafe($GLOBALS['activityLogs']);
};
$tests['no production file/debug or alternative logging sinks'] = static function (): void {
    foreach (['modules', 'includes'] as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $tokens = token_get_all(file_get_contents($file->getPathname()));
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_STRING) {
                    check(!in_array(strtolower($token[1]), ['file_put_contents', 'fopen', 'fwrite', 'mkdir', 'error_log', 'syslog', 'var_dump', 'print_r', 'writelog'], true), 'Unexpected file/debug sink');
                }
            }
        }
    }
};

$before = fingerprints();
$passed = 0;
foreach ($tests as $name => $test) {
    $GLOBALS['moduleLogs'] = [];
    ob_start();
    try {
        $test();
        check($GLOBALS['httpQueue'] === [], 'Unused fake HTTP responses');
        foreach ($GLOBALS['moduleLogs'] as $log) {
            assertSafe($log['output']);
            check(in_array(API_KEY, $log['replace'], true), 'API key missing from redaction control');
            $allowed = ['method', 'endpoint', 'http_code', 'result', 'curl_code', 'duration_ms'];
            check(array_diff(array_keys($log['output'][3]), $allowed) === [], 'Unexpected metadata key');
        }
        $output = ob_get_clean();
        assertSafe($output);
        check($output === '', 'Unexpected debug output');
        $passed++;
        echo "PASS: " . $name . "\n";
    } catch (Throwable $e) {
        if (ob_get_level() > 0) { ob_end_clean(); }
        // Do not print exception payloads even when a security test fails.
        fwrite(STDERR, "FAIL: " . $name . " (details suppressed)\n");
        exit(1);
    }
}
check($before === fingerprints(), 'Tests changed repository files');
echo $passed . " tests passed; no real HTTP, WHMCS calls or file writes.\n";
