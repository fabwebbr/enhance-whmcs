<?php
if (!defined('ENHANCE_TEST_ISOLATED')) throw new RuntimeException('Use the isolated runner');
// Synthetic inputs derived from orchd 12.25.12, never recorded customer data.
$documentedCases = [
    'licence' => ['GET', '/licence', 200, '{"status":"active"}'],
    'owner' => ['POST', '/orgs/fake-org/members', 201, '{"id":"11111111-2222-4333-8444-555555555555"}'],
    'org-name' => ['PATCH', '/orgs/fake-org', 204, ''],
    'org-suspend' => ['PATCH', '/orgs/fake-org', 204, ''],
    'org-reactivate' => ['PATCH', '/orgs/fake-org', 204, ''],
    'subscription-plan' => ['PATCH', '/orgs/fake-org/subscriptions/123', 204, ''],
    'subscription-suspend' => ['PATCH', '/orgs/fake-org/subscriptions/123', 204, ''],
    'subscription-reactivate' => ['PATCH', '/orgs/fake-org/subscriptions/123', 204, ''],
    'delete-website' => ['DELETE', '/orgs/fake-org/websites/fake-site', 204, ''],
    'delete-subscription-soft' => ['DELETE', '/orgs/fake-org/subscriptions/123?force=false', 204, ''],
    'delete-subscription-hard' => ['DELETE', '/orgs/fake-org/subscriptions/123?force=true', 204, ''],
    'delete-org' => ['DELETE', '/orgs/fake-org', 204, ''],
    'recovery' => ['PUT', '/login/password-recovery', 200, ''],
];
foreach ($documentedCases as $label => [$method, $path, $status, $body]) {
    $tests['documented positive ' . $label] = static function () use ($method, $path, $status, $body): void {
        consumerSetup([]); $before = $GLOBALS['httpAttempts'];
        reply($body, $status);
        $response = api(true)->send($method, $path, payload());
        check($response === ($body === '' ? ['_raw' => ''] : json_decode($body, true)) + ['_httpCode' => $status], 'Wrong positive adapter');
        check($GLOBALS['httpAttempts'] === $before + 1 && \WHMCS\Database\Capsule::$writes === [], 'Unexpected continuation');
        check(request()[CURLOPT_URL] === 'https://example.invalid/api' . $path, 'Request route/query changed');
        check(json_decode(request()[CURLOPT_POSTFIELDS] ?? '{}', true) === ($method === 'GET' ? [] : payload()), 'Request payload changed');
    };
    $cases = [
        '401' => [ERROR_SENTINEL, 401, 0, 'auth_error'],
        '403' => [ERROR_SENTINEL, 403, 0, 'auth_error'],
        '404' => [ERROR_SENTINEL, 404, 0, 'http_error'],
        '429' => [ERROR_SENTINEL, 429, 0, 'rate_limited'],
        '500' => [ERROR_SENTINEL, 500, 0, 'remote_error'],
        'curl' => [false, 0, 7, 'transport_error'],
        'timeout after send' => [false, 0, 28, 'transport_error'],
        'unexpected JSON' => [json_encode(payload()), $status, 0, 'invalid_schema'],
        'broken JSON' => ['{"secret":"' . PASSWORD, $status, 0, $body === '' ? 'invalid_schema' : 'invalid_json'],
        'HTML' => ['<html>' . ERROR_SENTINEL, $status, 0, $body === '' ? 'invalid_schema' : 'non_json'],
        'whitespace' => [" \n", $status, 0, $body === '' ? 'invalid_schema' : 'empty_response'],
    ];
    if ($body !== '') $cases['missing body'] = ['', $status, 0, 'empty_response'];
    foreach ([200, 201, 202, 204, 206] as $wrong) {
        if ($wrong !== $status) $cases['wrong positive ' . $wrong] = [$body, $wrong, 0, $wrong === 204 ? 'unexpected_empty_response' : 'indeterminate'];
    }
    foreach ($cases as $case => [$raw, $http, $errno, $category]) {
        $tests['documented negative ' . $label . ' ' . $case] = static function () use ($method, $path, $raw, $http, $errno, $category): void {
            consumerSetup([]); $before = $GLOBALS['httpAttempts'];
            reply($raw, $http, $errno ? ERROR_SENTINEL : '', $errno);
            expectFailure(static fn() => api(true)->send($method, $path, payload()), $category, $http);
            check($GLOBALS['httpAttempts'] === $before + 1 && \WHMCS\Database\Capsule::$writes === [], 'Failure retried or mutated');
            check(lastLog()['output'][3]['result'] === $category, 'Unsafe classification');
        };
    }
}
foreach ([null, '', 'fake-id', 123, [], '11111111-2222-4333-8444-55555555555Z'] as $index => $id) {
    $tests['documented owner rejects UUID ' . $index] = static function () use ($id): void {
        reply(json_encode(['id' => $id, 'secret' => PASSWORD]), 201);
        expectFailure(static fn() => api(true)->linkMemberAsOwner('fake-org', 'fake-login'), 'invalid_schema', 201);
    };
}
foreach (['active', 'cancelled', 'suspended', 'trial', 'unpaid', 'unknown'] as $status) {
    $tests['documented licence state ' . $status] = static function () use ($status): void {
        reply(json_encode(['status' => $status]));
        check(api(true)->getLicense()['status'] === $status, 'Licence state corrupted');
    };
}
foreach ([['status' => 'enabled'], ['status' => false], ['status' => 'active', 'key' => PASSWORD]] as $index => $body) {
    $tests['documented licence schema invalid ' . $index] = static function () use ($body): void {
        reply(json_encode($body)); expectFailure(static fn() => api(true)->getLicense(), 'invalid_schema', 200);
    };
}
foreach (['PATCH', 'DELETE', 'PUT', 'POST'] as $method) {
    $tests['documented ACK cannot authorize other route ' . $method] = static function () use ($method): void {
        reply('', 204);
        expectFailure(static fn() => api(true)->send($method, '/unknown', payload()), 'unexpected_empty_response', 204);
        reply('{}', 200);
        expectFailure(static fn() => api(true)->send($method, '/unknown', payload()), 'indeterminate', 200);
    };
}
foreach ([true, false] as $accepted) {
    $tests['documented rename consumer ' . (int) $accepted] = static function () use ($accepted): void {
        consumerSetup(['tblcustomfieldsvalues:value' => ['fake-org']]);
        reply('{"id":"fake-org","name":"Old name"}');
        reply($accepted ? '' : ERROR_SENTINEL, 204);
        if ($accepted) reply('{"items":[{"id":"owner","roles":["Owner"]}]}');
        $before = $GLOBALS['httpAttempts'];
        if ($accepted) {
            $result = api(true)->syncCustomerFromWhmcs(5, 'Fake Client', EMAIL);
            check($result['success'] === true && in_array('org_name_updated', $result['changed'], true), 'ACK did not continue normal sync');
        } else expectFailure(static fn() => api(true)->syncCustomerFromWhmcs(5, 'Fake Client', EMAIL), 'invalid_schema', 204);
        check($GLOBALS['httpAttempts'] === $before + ($accepted ? 3 : 2), 'Wrong continuation');
        check(\WHMCS\Database\Capsule::$writes === [], 'Unexpected writes');
    };
    $tests['documented owner consumer ' . (int) $accepted] = static function () use ($accepted): void {
        consumerSetup(['tblcustomfieldsvalues:value' => ['fake-org']]);
        reply('{"id":"fake-org","name":"Fake Client"}'); reply('{"items":[]}');
        reply('{"id":"fake-login"}', 201);
        reply($accepted ? '{"id":"11111111-2222-4333-8444-555555555555"}' : json_encode(payload()), 201);
        $before = $GLOBALS['httpAttempts'];
        if ($accepted) {
            $r = api(true)->syncCustomerFromWhmcs(5, 'Fake Client', EMAIL);
            check($r['success'] && in_array('owner_created', $r['changed'], true), 'Owner not confirmed');
        } else expectFailure(static fn() => api(true)->syncCustomerFromWhmcs(5, 'Fake Client', EMAIL), 'invalid_schema', 201);
        check($GLOBALS['httpAttempts'] === $before + 4 && \WHMCS\Database\Capsule::$writes === [], 'Unexpected mutation after owner');
    };
}
foreach (['resetPassword', 'suspendOrg', 'reactivateOrg', 'deleteOrg'] as $operation) {
    foreach ([true, false] as $accepted) {
        $tests['documented admin ACK ' . $operation . ' ' . (int) $accepted] = static function () use ($operation, $accepted): void {
            consumerSetup(['tblservers:first' => [(object) ['hostname' => 'example.invalid', 'username' => 'fake-master', 'accesshash' => API_KEY]],
                'tblcustomfields:id' => [1], 'tblcustomfieldsvalues:value' => ['fake-org']]);
            $_POST = ['enhance_action' => $operation, 'token' => 'fake-csrf', 'orgId' => 'fake-org'];
            $before = $GLOBALS['httpAttempts']; $expected = 1;
            if ($operation === 'resetPassword') { reply(json_encode(['id' => 'fake-org', 'name' => 'Fake', 'ownerEmail' => EMAIL])); $expected++; }
            reply($accepted ? '' : ERROR_SENTINEL, $operation === 'resetPassword' ? 200 : 204);
            if ($accepted) {
                if ($operation === 'deleteOrg') { reply('{"items":[]}'); $expected++; }
                else { reply('{"id":"fake-org","name":"Fake"}'); reply('{"total":0}'); $expected += 2; }
            }
            try {
                $r = $GLOBALS['hooks']['AdminClientProfileTabFields'](['userid' => 5]); assertSafe($r);
                check($GLOBALS['httpAttempts'] === $before + $expected, 'Admin continued unexpectedly');
                if (!$accepted) check($r['Enhance'] === _ehAlert('danger', 'The Enhance operation could not be confirmed. Check its state before trying again.'), 'Unsafe admin error');
                else check(array_keys($r) === [''] && !str_contains($r[''], 'could not be confirmed'), 'ACK not accepted');
                check(count(\WHMCS\Database\Capsule::$writes) === ($accepted && $operation === 'deleteOrg' ? 1 : 0), 'Unexpected admin persistence');
            } finally { $_POST = []; }
        };
    }
}
foreach (['suspend', 'unsuspend', 'change', 'soft', 'hard'] as $action) {
    foreach ([true, false] as $accepted) {
        $tests['documented service continuation ' . $action . ' ' . (int) $accepted] = static function () use ($action, $accepted): void {
            consumerSetup(['tblcustomfields:id' => [1, 1, 1], 'tblcustomfieldsvalues:value' => ['fake-org', '123']]);
            syncedCustomerReplies();
            // Legacy read fixture retained: official subscription-state adaptation is deferred.
            reply(json_encode(['id' => 123, 'isSuspended' => $action === 'unsuspend']));
            $expected = 4;
            if ($action === 'unsuspend') { reply('{"id":"fake-org","name":"Fake"}'); $expected++; }
            if ($action === 'hard') {
                reply('{"items":[{"id":"fake-site"}]}'); $expected++;
                reply($accepted ? '' : ERROR_SENTINEL, 204);
                if ($accepted) { reply('', 204); $expected++; }
            } else reply($accepted ? '' : ERROR_SENTINEL, 204);
            if ($accepted && in_array($action, ['suspend', 'unsuspend'], true)) {
                reply(json_encode(['id' => 123, 'isSuspended' => $action === 'suspend'])); $expected++;
            }
            $params = consumerParams(); $params['configoption2'] = $action;
            $before = $GLOBALS['httpAttempts'];
            $r = match ($action) {
                'suspend' => enhance_SuspendAccount($params),
                'unsuspend' => enhance_UnsuspendAccount($params),
                'change' => enhance_ChangePackage($params),
                default => enhance_TerminateAccount($params),
            };
            check($r === ($accepted ? 'success' : 'Enhance request failed (invalid_schema).'), 'Wrong public return'); assertSafe($r);
            check($GLOBALS['httpAttempts'] === $before + $expected, 'Wrong service HTTP count');
            check(count(\WHMCS\Database\Capsule::$writes) === ($accepted && in_array($action, ['soft', 'hard'], true) ? 1 : 0), 'Unexpected persistence');
            if ($accepted && in_array($action, ['soft', 'hard'], true)) {
                check(request()[CURLOPT_URL] === 'https://example.invalid/api/orgs/fake-org/subscriptions/123?force=' . ($action === 'hard' ? 'true' : 'false'), 'Force semantics changed');
            }
        };
    }
}
$tests['documented licence TestConnection preserves public format'] = static function (): void {
    consumerSetup(['tblcustomfields:id' => [1]]); reply('{"status":"active"}');
    $before = $GLOBALS['httpAttempts'];
    check(enhance_TestConnection(consumerParams()) === ['success' => true, 'error' => ''], 'Connection format changed');
    check($GLOBALS['httpAttempts'] === $before + 1 && \WHMCS\Database\Capsule::$writes === [], 'Connection mutated');
};
$tests['documented licence key remains private'] = static function (): void {
    $key = '11111111-2222-4333-8444-555555555555';
    reply(json_encode(['status' => 'active', 'key' => $key]));
    check(api(true)->getLicense()['key'] === $key, 'Functional key changed');
    check(!str_contains(json_encode(lastLog()['output']), $key), 'Licence key leaked');
    $r = EnhanceHttpResult::classify(json_encode(['status' => 'active', 'key' => $key]), 200, 0, EnhanceHttpResult::contract('GET', '/licence'));
    check(!str_contains(serialize($r), $key) && !str_contains(json_encode($r), $key), 'Private payload leaked');
};
