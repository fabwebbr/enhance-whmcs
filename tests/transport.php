<?php
// Appended to the same isolated runner, retaining all 47 original scenarios.
if (!defined('ENHANCE_TEST_ISOLATED')) { throw new RuntimeException('Use the isolated runner'); }

foreach ([200, 201] as $status) {
    $tests['transport successful login JSON ' . $status] = static function () use ($status): void {
        $response = ['id' => 'fake-login', 'extra' => [BODY_SENTINEL, ERROR_SENTINEL]] + payload();
        reply(json_encode($response), $status);
        $result = api(true)->createLogin('fake-org', EMAIL, PASSWORD, 'Fake');
        check($result === $response + ['_httpCode' => $status], 'Valid payload not preserved internally');
        check(lastLog()['output'][3]['result'] === 'success', 'Success not classified');
        check(request()[CURLOPT_TIMEOUT] === 45 && request()[CURLOPT_SSL_VERIFYHOST] === 2
            && request()[CURLOPT_SSL_VERIFYPEER] === true, 'Transport security options changed');
    };
}

foreach ([400 => 'validation_error', 401 => 'auth_error', 403 => 'auth_error',
    404 => 'http_error', 409 => 'validation_error', 422 => 'validation_error',
    429 => 'rate_limited', 500 => 'remote_error', 502 => 'remote_error', 503 => 'remote_error',
    0 => 'invalid_http_status', 100 => 'http_error', 302 => 'http_error',
    202 => 'indeterminate', 206 => 'indeterminate'] as $status => $category) {
    $tests['transport rejects HTTP ' . $status] = static function () use ($status, $category): void {
        reply(json_encode(['id' => 'fake-login', 'code' => ERROR_SENTINEL] + payload()), $status);
        expectFailure(static fn() => api(true)->createLogin('fake-org', EMAIL, PASSWORD, 'Fake'), $category, $status);
        check(lastLog()['output'][3]['result'] === $category, 'Log classification differs');
        check(json_decode(request()[CURLOPT_POSTFIELDS], true)['password'] === PASSWORD, 'Request secret missing internally');
    };
}

foreach ([28 => 'timeout', 6 => 'DNS', 60 => 'TLS', 7 => 'connection refused', 55 => 'other cURL'] as $errno => $label) {
    $tests['transport simulated ' . $label] = static function () use ($errno): void {
        reply(false, 0, ERROR_SENTINEL . ' ' . implode(' ', secrets()), $errno);
        $failure = expectFailure(static fn() => api(true)->getOrg('fake-org'), 'transport_error', 0);
        check($failure->curlCode === $errno, 'cURL code lost');
        check(lastLog()['output'][3]['curl_code'] === $errno, 'cURL numeric diagnostic missing');
    };
}
$tests['cURL failure overrides apparent HTTP success'] = static function (): void {
    reply(json_encode(['id' => 'fake-org', 'name' => 'Fake']), 200, ERROR_SENTINEL, 28);
    expectFailure(static fn() => api(false)->getOrg('fake-org'), 'transport_error', 200);
};
$tests['false cURL result with zero errno is never success'] = static function (): void {
    reply(false, 0);
    expectFailure(static fn() => api(false)->getOrg('fake-org'), 'transport_error', 0);
};

foreach ([
    'HTML' => ['<html>' . BODY_SENTINEL . '</html>', 'non_json', 'non_json'],
    'arbitrary text' => [BODY_SENTINEL, 'non_json', 'non_json'],
    'broken JSON' => ['{"password":"' . PASSWORD, 'invalid_json', 'invalid_json'],
    'empty' => ['', 'empty_response', 'empty'],
    'whitespace' => [" \r\n ", 'empty_response', 'empty'],
    'null JSON' => ['null', 'invalid_schema', 'json'],
    'list JSON' => ['[]', 'invalid_schema', 'json'],
    'scalar JSON' => ['true', 'invalid_schema', 'json'],
    'object missing id' => ['{}', 'invalid_schema', 'json'],
    'wrong id type' => ['{"id":[]}', 'invalid_schema', 'json'],
    'API error in 200' => [json_encode(['id' => 'fake', 'code' => ERROR_SENTINEL] + payload()), 'api_error', 'json'],
] as $label => [$raw, $category, $format]) {
    $tests['transport format ' . $label] = static function () use ($raw, $category, $format): void {
        reply($raw);
        $failure = expectFailure(static fn() => api(true)->createLogin('fake-org', EMAIL, PASSWORD, 'Fake'), $category, 200);
        check($failure->format === $format, 'Format distinction lost');
    };
}
$tests['explicit synthetic operation declares 404 absence only'] = static function (): void {
    // Synthetic contract, not an assertion about the real Enhance API.
    $result = EnhanceHttpResult::classify(ERROR_SENTINEL, 404, 0, ['shape' => 'id', 'not_found' => true]);
    check($result->category === 'not_found', 'Explicit absence not recognized');
    assertSafe($result);
    expectFailure(static fn() => $result->data(), 'not_found', 404);
    foreach ([401, 429, 500] as $status) {
        check(EnhanceHttpResult::classify(ERROR_SENTINEL, $status, 0, ['not_found' => true])->category !== 'not_found', 'Error became absence');
    }
    check(EnhanceHttpResult::contract('GET', '/orgs/fake')['not_found'] === false, 'Undocumented production 404 policy');
};
$tests['204 is restricted to password recovery fixture'] = static function (): void {
    reply('', 204);
    expectFailure(static fn() => api(true)->deleteOrg('fake-org'), 'unexpected_empty_response', 204);
    reply('', 204);
    check(api(true)->triggerPasswordRecovery(EMAIL) === ['_raw' => '', '_httpCode' => 204], 'Expected recovery 204 changed');
};
$tests['204 containing body rejected even on recovery'] = static function (): void {
    reply(BODY_SENTINEL, 204);
    expectFailure(static fn() => api(false)->triggerPasswordRecovery(EMAIL), 'unexpected_empty_response', 204);
};
$tests['unknown acknowledgement remains indeterminate'] = static function (): void {
    reply('{}');
    expectFailure(static fn() => api(false)->updateOrgName('fake-org', 'Fake'), 'indeterminate', 200);
};
$tests['unknown licence schema cannot confirm connection'] = static function (): void {
    reply('{"valid":true}');
    expectFailure(static fn() => api(false)->getLicense(), 'indeterminate', 200);
};
$tests['diagnostic serialization excludes successful private payload'] = static function (): void {
    $result = EnhanceHttpResult::classify(json_encode(['id' => 'fake'] + payload()), 200, 0, ['shape' => 'id']);
    assertSafe($result);
    assertSafe($result->__debugInfo());
    assertSafe(serialize($result));
    check($result->data()['password'] === PASSWORD, 'Private functional payload unavailable');
};
$tests['request encoding failure occurs before HTTP'] = static function (): void {
    $before = $GLOBALS['httpAttempts'];
    expectFailure(static fn() => api(false)->send('POST', '/logins', ['bad' => "\xB1", 'password' => PASSWORD]), 'request_encoding_error', 0);
    check($GLOBALS['httpAttempts'] === $before, 'Invalid body was transmitted');
};
$tests['sensitive query and nested errors never reach diagnostics'] = static function (): void {
    $path = '/logins?secret=' . API_KEY . '&email=' . EMAIL;
    reply(json_encode(['code' => ERROR_SENTINEL, 'nested' => payload()]), 422);
    expectFailure(static fn() => api(true)->send('POST', $path, payload()), 'validation_error', 422);
    check(request()[CURLOPT_URL] === 'https://example.invalid/api' . $path, 'Functional URL changed');
    check(json_decode(request()[CURLOPT_POSTFIELDS], true) === payload(), 'Functional body changed');
    check(lastLog()['output'][2]['endpoint'] === '/logins', 'Query leaked');
};
foreach (['{"items":{}}', '{"items":[null]}', '{"items":[{"id":"x"}]}'] as $index => $raw) {
    $tests['malformed members collection ' . $index] = static function () use ($raw): void {
        reply($raw);
        expectFailure(static fn() => api(false)->findOwnerMember('fake-org'), 'invalid_schema', 200);
    };
}
$tests['subscription without usable state is not active'] = static function (): void {
    reply('{"id":123}');
    expectFailure(static fn() => api(false)->getSubscription('fake-org', '123'), 'invalid_schema', 200);
};
foreach ([['id' => 123, 'status' => 'suspended'], ['id' => 123, 'suspended' => true],
    ['id' => 123, 'isSuspended' => false, 'suspendedBy' => 'fake-owner']] as $index => $state) {
    $tests['subscription ambiguous state ' . $index] = static function () use ($state): void {
        reply(json_encode($state));
        expectFailure(static fn() => api(false)->getSubscription('fake-org', '123'), 'invalid_schema', 200);
    };
}
$tests['invalid SSO text cannot redirect'] = static function (): void {
    reply('{"items":[{"id":"member","roles":["Owner"],"isActive":true}]}');
    reply('<html>' . ERROR_SENTINEL . '</html>');
    expectFailure(static fn() => api(false)->getOwnerSsoUrl('fake-org'), 'non_json', 200);
};

function consumerSetup(array $values): void {
    \WHMCS\Database\Capsule::$exists = true;
    \WHMCS\Database\Capsule::$values = $values;
    \WHMCS\Database\Capsule::$writes = [];
}
function syncedCustomerReplies(): void {
    reply('{"id":"fake-org","name":"Fake Client"}');
    reply('{"items":[{"id":"owner","roles":["Owner"],"isActive":true}]}');
}
function consumerParams(): array {
    return ['serverhostname' => 'example.invalid', 'serverusername' => 'fake-master',
        'serveraccesshash' => API_KEY, 'packageid' => 2, 'serviceid' => 3,
        'configoption1' => 4, 'domain' => 'example.invalid',
        'clientsdetails' => ['id' => 5, 'fullname' => 'Fake Client', 'email' => EMAIL, 'companyname' => '']];
}
$consumerFailures = [
    'timeout' => [false, 0, 28, 'transport_error'],
    '401' => [ERROR_SENTINEL, 401, 0, 'auth_error'],
    '403' => [ERROR_SENTINEL, 403, 0, 'auth_error'],
    '404' => [ERROR_SENTINEL, 404, 0, 'http_error'],
    '429' => [ERROR_SENTINEL, 429, 0, 'rate_limited'],
    '500' => [ERROR_SENTINEL, 500, 0, 'remote_error'],
    'invalid response' => ['<html>' . ERROR_SENTINEL, 200, 0, 'non_json'],
    'incomplete JSON' => ['{}', 200, 0, 'invalid_schema'],
];
foreach ($consumerFailures as $label => [$raw, $status, $errno, $category]) {
    foreach (['syncCustomerFromWhmcs', '_enhance_resolve_subscription', 'createCustomerOrg', 'enhance_CreateAccount', 'enhance_TestConnection'] as $consumer) {
        $tests[$consumer . ' stops after ' . $label] = static function () use ($consumer, $raw, $status, $errno, $category): void {
            if ($consumer === 'enhance_TestConnection' && $category === 'invalid_schema') $category = 'indeterminate';
            $before = $GLOBALS['httpAttempts'];
            $params = consumerParams();
            if ($consumer === 'syncCustomerFromWhmcs') {
                consumerSetup(['tblcustomfieldsvalues:value' => ['fake-org']]);
                $expectedCalls = 1;
            } elseif ($consumer === '_enhance_resolve_subscription') {
                consumerSetup(['tblcustomfieldsvalues:value' => ['fake-org', '123'], 'tblcustomfields:id' => [1]]);
                syncedCustomerReplies();
                $expectedCalls = 3;
            } elseif ($consumer === 'createCustomerOrg') {
                consumerSetup([]);
                reply('{"id":"fake-org"}', 201);
                $expectedCalls = 2;
            } elseif ($consumer === 'enhance_CreateAccount') {
                consumerSetup(['tblcustomfields:id' => [1, 2, 2], 'tblcustomfieldsvalues:value' => [null, 'fake-org']]);
                syncedCustomerReplies();
                reply('{"id":123}', 201);
                $expectedCalls = 4;
            } else {
                consumerSetup(['tblcustomfields:id' => [1]]);
                $expectedCalls = 1;
            }
            reply($raw, $status, $errno ? ERROR_SENTINEL : '', $errno);
            if ($consumer === 'syncCustomerFromWhmcs') {
                expectFailure(static fn() => api(false)->syncCustomerFromWhmcs(5, 'Fake Client', EMAIL), $category, $status);
            } elseif ($consumer === '_enhance_resolve_subscription') {
                expectFailure(static fn() => _enhance_resolve_subscription(api(false), $params), $category, $status);
            } elseif ($consumer === 'createCustomerOrg') {
                expectFailure(static fn() => api(false)->createCustomerOrg(5, 'Fake Client', EMAIL, ''), $category, $status);
            } elseif ($consumer === 'enhance_CreateAccount') {
                $result = enhance_CreateAccount($params);
                check($result === 'Enhance request failed (' . $category . ').', 'Provisioning did not report safe failure');
                assertSafe($result);
            } else {
                $result = enhance_TestConnection($params);
                check($result['success'] === false && $result['error'] === 'Enhance request failed (' . $category . ').', 'Connection false success');
                assertSafe($result);
            }
            check($GLOBALS['httpAttempts'] - $before === $expectedCalls, 'HTTP discovery/compensation occurred after failure');
            $writes = \WHMCS\Database\Capsule::$writes;
            if ($consumer === 'enhance_CreateAccount') {
                // The newly created subscription was persisted BEFORE website failure.
                check(count($writes) === 2 && $writes[0][1] === 'delete' && $writes[1][1] === 'insert'
                    && $writes[1][2]['value'] === '123', 'Subscription binding cleared/changed after failure');
            } else {
                check($writes === [], 'Database mutation followed failure');
            }
        };
    }
}
$tests['owner lookup failure does not create login'] = static function (): void {
    consumerSetup(['tblcustomfieldsvalues:value' => ['fake-org']]);
    reply('{"id":"fake-org","name":"Fake Client"}');
    reply('{}');
    $before = $GLOBALS['httpAttempts'];
    expectFailure(static fn() => api(false)->syncCustomerFromWhmcs(5, 'Fake Client', EMAIL), 'invalid_schema', 200);
    check($GLOBALS['httpAttempts'] - $before === 2 && \WHMCS\Database\Capsule::$writes === [], 'Login creation attempted');
};

foreach ([
    ['GET', '/orgs/fake', ['id' => 'fake', 'name' => 'Fake']],
    ['GET', '/orgs/fake/subscriptions/123', ['id' => 123, 'isSuspended' => false]],
    ['GET', '/orgs/fake/subscriptions/123', ['id' => 123, 'isSuspended' => true]],
    ['GET', '/orgs/fake/customers', ['items' => [['id' => 'fake', 'name' => 'Fake']]]],
    ['GET', '/orgs/master/customers/fake/subscriptions', ['items' => [['id' => 123]]]],
    ['GET', '/orgs/master/plans', ['items' => [['id' => 123, 'name' => 'Fake']]]],
    ['GET', '/orgs/fake/members', ['items' => [['id' => 'fake', 'roles' => ['Owner']]]]],
    ['GET', '/logins', ['items' => [['id' => 'fake', 'email' => EMAIL]]]],
    ['GET', '/orgs/fake/websites/site', ['id' => 'site']],
    ['GET', '/orgs/fake/websites', ['items' => [['id' => 'site']]]],
    ['GET', '/orgs/fake/websites/site/domains', ['items' => ['example.invalid', ['domain' => 'example.invalid']]]],
    ['GET', '/orgs/fake/emails', ['total' => 0]],
    ['POST', '/orgs/master/customers', ['id' => 'fake']],
    ['POST', '/orgs/master/customers/fake/subscriptions', ['id' => 123]],
    ['POST', '/orgs/fake/websites', ['id' => 'site']],
] as $index => [$method, $path, $response]) {
    $tests['provisional operation contract accepts consumed shape ' . $index] = static function () use ($method, $path, $response): void {
        $response['nested'] = payload();
        reply(json_encode($response));
        check(api(false)->send($method, $path) === $response + ['_httpCode' => 200], 'Consumed fields not preserved');
    };
}
$tests['unknown endpoint with valid JSON is indeterminate'] = static function (): void {
    reply(json_encode(['id' => 'fake'] + payload()));
    expectFailure(static fn() => api(false)->send('GET', '/unknown'), 'indeterminate', 200);
};
$tests['subscription creation failure cannot persist or create website'] = static function (): void {
    consumerSetup(['tblcustomfields:id' => [1, 2], 'tblcustomfieldsvalues:value' => [null, 'fake-org']]);
    syncedCustomerReplies();
    reply('{}', 201);
    $before = $GLOBALS['httpAttempts'];
    check(enhance_CreateAccount(consumerParams()) === 'Enhance request failed (invalid_schema).', 'Creation falsely confirmed');
    check($GLOBALS['httpAttempts'] - $before === 3 && \WHMCS\Database\Capsule::$writes === [], 'Next mutation occurred');
};
