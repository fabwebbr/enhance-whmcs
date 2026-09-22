<?php
if (!defined('ENHANCE_TEST_ISOLATED')) { throw new RuntimeException('Use the isolated runner'); }
require __DIR__ . '/../includes/hooks/enhance.php';

foreach (['organisation', 'emails', 'customers', 'resetPassword', 'suspendOrg', 'reactivateOrg', 'deleteOrg'] as $operation) {
    foreach (['remote', 'transport', 'indeterminate'] as $failure) {
        $tests['admin callback catches ' . $operation . ' ' . $failure] = static function () use ($operation, $failure): void {
            consumerSetup([
                'tblservers:first' => [(object) ['hostname' => 'example.invalid', 'username' => 'fake-master', 'accesshash' => API_KEY]],
                'tblcustomfields:id' => [1],
                'tblcustomfieldsvalues:value' => [$operation === 'customers' ? null : 'fake-org'],
            ]);
            $action = in_array($operation, ['resetPassword', 'suspendOrg', 'reactivateOrg', 'deleteOrg'], true);
            $_POST = $action ? ['enhance_action' => $operation, 'token' => 'fake-csrf', 'orgId' => 'fake-org'] : [];
            $GLOBALS['activityLogs'] = [];
            $before = $GLOBALS['httpAttempts'];
            $expected = 1;
            if (in_array($operation, ['emails', 'resetPassword'], true)) {
                reply(json_encode(['id' => 'fake-org', 'name' => 'Fake', 'ownerEmail' => EMAIL]));
                $expected++;
            }
            if ($failure === 'transport') reply(false, 0, implode(' ', secrets()), 28);
            elseif ($failure === 'remote') reply(json_encode(payload()), 500);
            else reply(json_encode(payload()), $action ? 200 : 202);
            try {
                // Invoke the registered production callback, including constructor and HTTP.
                $result = $GLOBALS['hooks']['AdminClientProfileTabFields'](['userid' => 5]);
                check(is_array($result) && array_keys($result) === ['Enhance'], 'Hook did not return its error array');
                $expectedMessage = $action
                    ? 'The Enhance operation could not be confirmed. Check its state before trying again.'
                    : 'The Enhance query could not be confirmed. Profile data is temporarily unavailable.';
                check($result['Enhance'] === _ehAlert('danger', $expectedMessage), 'Wrong failure context or unsafe HTML');
                assertSafe($result);
                assertSafe($GLOBALS['activityLogs']);
                check($GLOBALS['httpAttempts'] - $before === $expected, 'Callback continued HTTP after failure');
                check(\WHMCS\Database\Capsule::$writes === [], 'Callback mutated database after failure');
                check(lastLog()['output'][3]['result'] === match ($failure) {
                    'remote' => 'remote_error', 'transport' => 'transport_error', default => 'indeterminate',
                }, 'Failure did not reach the intended transport classification');
            } finally { $_POST = []; }
        };
    }
}
$tests['admin POST preliminary organisation failure stops recovery'] = static function (): void {
    consumerSetup([
        'tblservers:first' => [(object) ['hostname' => 'example.invalid', 'username' => 'fake-master', 'accesshash' => API_KEY]],
        'tblcustomfields:id' => [1], 'tblcustomfieldsvalues:value' => ['fake-org'],
    ]);
    $_POST = ['enhance_action' => 'resetPassword', 'token' => 'fake-csrf'];
    reply(json_encode(payload()), 403);
    $before = $GLOBALS['httpAttempts'];
    try {
        $result = $GLOBALS['hooks']['AdminClientProfileTabFields'](['userid' => 5]);
        check(str_contains($result['Enhance'], 'operation could not be confirmed'), 'POST failure misclassified');
        check($GLOBALS['httpAttempts'] === $before + 1 && \WHMCS\Database\Capsule::$writes === [], 'Recovery continued');
        assertSafe($result);
    } finally { $_POST = []; }
};

foreach ([
    'priority' => [['url' => SSO, 'loginUrl' => 'https://example.invalid/other'], SSO],
    'empty fallback exact reproduction' => [['url' => '', 'loginUrl' => 'https://example.invalid/sso/test'], 'https://example.invalid/sso/test'],
    'missing fallback' => [['loginUrl' => SSO], SSO],
    'null fallback' => [['url' => null, 'loginUrl' => SSO], SSO],
    'empty both' => [['url' => '', 'loginUrl' => ''], null],
    'missing both' => [[], null],
    'invalid primary' => [['url' => ERROR_SENTINEL, 'loginUrl' => SSO], null],
    'false primary' => [['url' => false, 'loginUrl' => SSO], null],
    'array primary' => [['url' => [SSO], 'loginUrl' => SSO], null],
    'integer primary' => [['url' => 12, 'loginUrl' => SSO], null],
    'invalid fallback type' => [['url' => '', 'loginUrl' => [SSO]], null],
    'HTTP primary' => [['url' => 'http://example.invalid/sso/test', 'loginUrl' => SSO], null],
    'HTTP fallback' => [['url' => '', 'loginUrl' => 'http://example.invalid/sso/test'], null],
    'sentinel fallback' => [['url' => '', 'loginUrl' => SSO], SSO],
] as $label => [$response, $expected]) {
    $tests['SSO regression ' . $label] = static function () use ($response, $expected): void {
        reply('{"items":[{"id":"owner","roles":["Owner"],"isActive":true}]}');
        reply(json_encode((object) $response));
        if ($expected === null) expectFailure(static fn() => api(true)->getOwnerSsoUrl('fake-org'), 'invalid_schema', 200);
        else check(api(true)->getOwnerSsoUrl('fake-org') === $expected, 'SSO destination not preserved');
        assertSafe($GLOBALS['activityLogs']);
        // Module logs and file fingerprints are checked by the common runner.
    };
}
