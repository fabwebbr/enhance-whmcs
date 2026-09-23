<?php
if (!defined('ENHANCE_TEST_ISOLATED')) throw new RuntimeException('Use the isolated runner');

// Exact public regression and malformed variants; all setup gates are valid.
$uninterpretableStates = [
    'original active P1' => ['status' => 'active'],
    'id and plan only' => [],
    'suspendedBy only' => ['suspendedBy' => '11111111-2222-4333-8444-555555555555'],
    'boolean string' => ['isSuspended' => 'false'],
    'boolean null' => ['isSuspended' => null],
    'boolean array' => ['isSuspended' => []],
    'status array' => ['isSuspended' => false, 'status' => []],
    'suspendedBy array' => ['isSuspended' => true, 'suspendedBy' => []],
    'suspended integer' => ['isSuspended' => true, 'suspended' => 1],
    'mixed status' => ['isSuspended' => false, 'status' => 'active'],
    'mixed suspendedBy' => ['isSuspended' => true, 'suspendedBy' => '11111111-2222-4333-8444-555555555555'],
    'deleted' => ['status' => 'deleted'],
];
foreach ($uninterpretableStates as $label => $state) {
    foreach (['collection', 'individual'] as $source) {
        $tests['subscription gate public ' . $source . ' ' . $label] = static function () use ($source, $state): void {
            consumerSetup(['tblcustomfields:id' => [1, 1], 'tblcustomfieldsvalues:value' => ['fake-org', $source === 'individual' ? '123' : null]]);
            syncedCustomerReplies();
            $sub = ['id' => 123, 'planId' => 4] + $state;
            $sub['diagnostic'] = payload(); // Internal sentinels must never reach diagnostics.
            reply(json_encode($source === 'individual' ? $sub : ['items' => [$sub], 'total' => 1]));
            $before = $GLOBALS['httpAttempts']; $requestStart = count($GLOBALS['requests']);
            $r = enhance_ChangePackage(consumerParams());
            check($r === 'Enhance request failed (invalid_schema).', 'Wrong failure category or false success');
            assertSafe($r); assertSafe(_ehAlert('danger', $r)); assertSafe($GLOBALS['activityLogs']);
            check($GLOBALS['httpAttempts'] === $before + 3 && $GLOBALS['httpQueue'] === [], 'Unexpected fallback or retry');
            check(\WHMCS\Database\Capsule::$writes === [], 'Persisted or removed subscription link');
            foreach (array_slice($GLOBALS['requests'], $requestStart) as $req) check($req[CURLOPT_CUSTOMREQUEST] === 'GET', 'Mutation reached transport');
        };
    }
}
$tests['subscription gate exact exception on selected item'] = static function (): void {
    consumerSetup(['tblcustomfields:id' => [1], 'tblcustomfieldsvalues:value' => ['fake-org', null]]);
    syncedCustomerReplies();
    reply(json_encode(['items' => [['id' => 123, 'planId' => 4, 'status' => 'active', 'secret' => PASSWORD]]]));
    $before = $GLOBALS['httpAttempts'];
    $e = expectFailure(static fn() => _enhance_resolve_subscription(api(true), consumerParams()), 'invalid_schema', 200);
    check($e->format === 'json' && $e->curlCode === 0, 'Wrong refusal source');
    assertSafe(_ehAlert('danger', $e->getMessage()));
    check($GLOBALS['httpAttempts'] === $before + 3 && \WHMCS\Database\Capsule::$writes === [], 'Unexpected continuation');
};
$tests['subscription gate no fallback after matching invalid candidate'] = static function (): void {
    consumerSetup(['tblcustomfields:id' => [1, 1], 'tblcustomfieldsvalues:value' => ['fake-org', null]]);
    syncedCustomerReplies();
    reply('{"items":[{"id":122,"planId":9,"isSuspended":false},{"id":123,"planId":4,"status":"active"},{"id":124,"planId":4,"isSuspended":false}]}');
    $before = $GLOBALS['httpAttempts'];
    check(enhance_ChangePackage(consumerParams()) === 'Enhance request failed (invalid_schema).', 'Skipped invalid matching candidate');
    check($GLOBALS['httpAttempts'] === $before + 3 && \WHMCS\Database\Capsule::$writes === [], 'Fallback/persistence occurred');
};
foreach ([false, true] as $suspended) {
    foreach (['single', 'plan match'] as $selection) {
        $tests['subscription gate accepted legacy collection ' . (int) $suspended . ' ' . $selection] = static function () use ($suspended, $selection): void {
            consumerSetup(['tblcustomfields:id' => [1, 1, 1], 'tblcustomfieldsvalues:value' => ['fake-org', null]]);
            syncedCustomerReplies();
            $selected = ['id' => 123, 'planId' => 4, 'isSuspended' => $suspended];
            $items = $selection === 'single' ? [$selected] : [['id' => 122, 'planId' => 9, 'status' => 'active'], $selected];
            reply(json_encode(['items' => $items])); reply('', 204);
            $before = $GLOBALS['httpAttempts'];
            check(enhance_ChangePackage(consumerParams()) === 'success', 'Explicit legacy evidence rejected');
            check($GLOBALS['httpAttempts'] === $before + 4, 'Wrong accepted request count');
            check(count(\WHMCS\Database\Capsule::$writes) === 2, 'Unexpected persistence count');
            check(request()[CURLOPT_CUSTOMREQUEST] === 'PATCH', 'Expected continuation missing');
        };
    }
}
