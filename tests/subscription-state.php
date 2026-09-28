<?php
if (!defined('ENHANCE_TEST_ISOLATED')) throw new RuntimeException('Use the isolated runner');
require __DIR__ . '/../modules/servers/enhance/EnhanceSubscriptionState.php';

function officialStateFixture(): array
{
    return ['id' => 123, 'planId' => 4, 'planName' => PASSWORD,
        'subscriberId' => '11111111-2222-4333-8444-555555555555',
        'vendorId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'status' => 'active',
        'resources' => [['name' => 'diskspace', 'usage' => 1, 'total' => 10]],
        'allowances' => [['name' => API_KEY]], 'selections' => [['name' => AUTH, 'value' => SSO]],
        'planType' => 'shared', 'allowedPhpVersions' => ['php81'], 'defaultPhpVersion' => 'php81',
        'redisAllowed' => false, 'friendlyName' => EMAIL, 'persistentAppsAllowed' => true];
}
function inspectState(mixed $input, bool $valid, string $life = 'indeterminate', string $evidence = 'indeterminate'): EnhanceSubscriptionState
{
    $before = serialize($input);
    $attempts = $GLOBALS['httpAttempts']; $writes = \WHMCS\Database\Capsule::$writes;
    $logs = $GLOBALS['moduleLogs']; $activity = $GLOBALS['activityLogs'];
    ob_start(); $s = EnhanceSubscriptionState::fromDecoded($input); $output = ob_get_clean();
    check($output === '' && serialize($input) === $before, 'Interpreter modified input or emitted output');
    check($s->isStructurallyValid() === $valid && $s->allowsRead() === $valid, 'Wrong structural read decision');
    check($s->lifecycle() === $life && $s->suspensionEvidence() === $evidence, 'Wrong conservative state');
    check($s->allowsMutation() === false, 'Mutation authorized');
    check($attempts === $GLOBALS['httpAttempts'] && $writes === \WHMCS\Database\Capsule::$writes
        && $logs === $GLOBALS['moduleLogs'] && $activity === $GLOBALS['activityLogs'], 'Interpreter had side effects');
    ob_start(); var_dump($s); $debug = ob_get_clean();
    $diagnostics = [json_encode($s), serialize($s), $s->__debugInfo(), $debug, var_export($s, true)];
    assertSafe($diagnostics);
    foreach (['11111111-2222-4333-8444-555555555555', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'] as $uuid) {
        check(!str_contains(json_encode($diagnostics), $uuid), 'UUID in diagnostics');
    }
    return $s;
}
foreach (['active', 'deleted'] as $life) foreach ([false, true] as $present) {
    $tests['state model matrix ' . $life . ' ' . (int)$present] = static function () use ($life, $present): void {
        $p = officialStateFixture(); $p['status'] = $life;
        if ($present) $p['suspendedBy'] = $p['vendorId'];
        $s = inspectState($p, true, $life, $present ? 'present' : 'absent');
        check(!method_exists($s, 'isSuspended') && !method_exists($s, 'shouldUnsuspend')
            && !method_exists($s, 'isActiveForWhmcs') && !method_exists($s, 'shouldClearBinding'), 'Unapproved decision API');
    };
}
foreach (array_keys(officialStateFixture()) as $field) {
    $tests['state model missing required ' . $field] = static function () use ($field): void {
        $p = officialStateFixture(); unset($p[$field]); inspectState($p, false);
    };
    $tests['state model null required ' . $field] = static function () use ($field): void {
        $p = officialStateFixture(); $p[$field] = null; inspectState($p, false);
    };
}
foreach (['suspendedBy', 'subscriberId', 'vendorId'] as $field) {
    foreach ([null, '', 'not-a-uuid', PASSWORD, false, true, 1, 1.5, [], ['id' => 'x'], (object)['id' => 'x']] as $i => $value) {
        $tests['state model invalid UUID ' . $field . ' ' . $i] = static function () use ($field, $value): void {
            $p = officialStateFixture(); $p[$field] = $value; inspectState($p, false);
        };
    }
}
$invalidFields = [
    'status' => ['suspended', 'unknown', false, [], (object)[], 1],
    'id' => ['123', 1.5, false, [], (object)[]], 'planId' => ['4', 1.5, false, []],
    'planName' => [1, false, []], 'friendlyName' => [1, false, []],
    'planType' => ['other', false], 'redisAllowed' => [0, 'false'], 'persistentAppsAllowed' => [1, 'true'],
    'defaultPhpVersion' => ['8.1', false], 'allowedPhpVersions' => [['php999'], ['key' => 'php81'], 'php81', [(object)[]]],
    'resources' => ['x', (object)[], ['key' => []], [[]], [['name' => 'other', 'usage' => 1]], [['name' => 'diskspace', 'usage' => '1']], [['name' => 'diskspace', 'usage' => 1, 'total' => null]]],
    'allowances' => ['x', [[]], [['name' => false]], [(object)['name' => 'x']]],
    'selections' => ['x', [[]], [['name' => 'x']], [['name' => 'x', 'value' => 1]]],
];
foreach ($invalidFields as $field => $values) foreach ($values as $i => $value) {
    $tests['state model wrong type ' . $field . ' ' . $i] = static function () use ($field, $value): void {
        $p = officialStateFixture(); $p[$field] = $value; inspectState($p, false);
    };
}
foreach ([null, false, true, 1, 1.5, PASSWORD, [], [officialStateFixture()], (object)officialStateFixture()] as $i => $input) {
    $tests['state model invalid root ' . $i] = static fn() => inspectState($input, false);
}
$tests['state model additional fields have no authority'] = static function (): void {
    $p = officialStateFixture();
    $p += ['isSuspended' => false, 'allowsMutation' => true, 'secret' => payload(), 'suspended' => true, 'domainstatus' => 'Active'];
    inspectState($p, true, 'active', 'absent');
};
$tests['state model immutable and serialization fail closed'] = static function (): void {
    $p = officialStateFixture(); $s = inspectState($p, true, 'active', 'absent');
    $p['status'] = 'deleted'; check($s->lifecycle() === 'active', 'Retained mutable input');
    foreach ((new ReflectionClass($s))->getProperties() as $property) {
        if (!$property->isStatic()) check($property->isPrivate() && $property->isReadOnly(), 'Mutable internal property');
    }
    $s->__unserialize(['lifecycle' => 'deleted']);
    check($s->lifecycle() === 'active', 'Reinitialized immutable state');
    $restored = unserialize(serialize($s));
    check(!$restored->allowsRead() && !$restored->allowsMutation() && $restored->lifecycle() === 'indeterminate', 'Diagnostic data became authority');
};
$tests['state model documented integer and empty list boundaries'] = static function (): void {
    $p = officialStateFixture(); $p['id'] = 0; $p['planId'] = -1;
    foreach (['resources', 'allowances', 'selections', 'allowedPhpVersions'] as $field) $p[$field] = [];
    inspectState($p, true, 'active', 'absent'); // Schema has no minimum or minItems; not identity authorization.
};

$tests['state model rejects dynamic mutation'] = static function (): void {
    $s = inspectState(officialStateFixture(), true, 'active', 'absent');
    $s->payload = payload();
    unset($s->payload);
    check(!property_exists($s, 'payload') && !isset($s->payload), 'Dynamic property retained');
    assertSafe([serialize($s), var_export($s, true), $s->__debugInfo()]);
    check(!$s->allowsMutation() && $s->lifecycle() === 'active', 'Mutation changed classification');
};

function stateSilent(callable $action): void
{
    set_error_handler(static function (): never { throw new RuntimeException('Unexpected state diagnostic'); });
    ob_start();
    try { $action(); check(ob_get_contents() === '', 'Unexpected state output'); }
    finally { ob_end_clean(); restore_error_handler(); }
}
function stateNoRetention(EnhanceSubscriptionState $s, string $sentinel): void
{
    $values = [];
    foreach ((new ReflectionClass($s))->getProperties() as $p) {
        if (!$p->isStatic()) $values[] = $p->getValue($s);
    }
    ob_start(); var_dump($s); $dump = ob_get_clean();
    foreach ([serialize($s), json_encode($s), var_export($s, true), $dump,
        serialize($values), serialize($s->__debugInfo())] as $diagnostic) {
        check(!str_contains($diagnostic, $sentinel), 'State retained sensitive input');
    }
    check(!$s->allowsMutation(), 'Mutation authority forged');
}
foreach (['scalar', 'array', 'object', 'private', 'dynamic', 'unset'] as $kind) {
    $tests['state silence assignment ' . $kind] = static function () use ($kind): void {
        stateSilent(static function () use ($kind): void {
            $s = EnhanceSubscriptionState::fromDecoded(officialStateFixture());
            $before = $s->jsonSerialize(); $secret = 'SYNTHETIC_ASSIGN_' . $kind;
            $name = $kind === 'private' ? 'life' : $secret;
            $object = new class($secret) {
                public function __construct(public string $secret) {}
                public function __toString(): string { throw new RuntimeException('Unexpected conversion'); }
            };
            $weak = WeakReference::create($object);
            $value = $kind === 'object' ? $object : ($kind === 'array' ? ['token' => $secret] : $secret);
            if ($kind === 'unset') { unset($s->$name); unset($s->life); }
            else $s->$name = $value;
            unset($object, $value); gc_collect_cycles();
            check($weak->get() === null, 'Object retained by assignment');
            check($s->jsonSerialize() === $before, 'Assignment changed state');
            if ($kind !== 'private') check(!property_exists($s, $name), 'Dynamic property created');
            check(!isset($s->$name), 'External property became visible');
            stateNoRetention($s, $secret);
        });
    };
}
foreach (['direct', 'without constructor', 'native'] as $mode) {
    foreach (['active', 'deleted', 'present'] as $forged) {
        $tests['state silence unserialize ' . $mode . ' ' . $forged] = static function () use ($mode, $forged): void {
            stateSilent(static function () use ($mode, $forged): void {
                $secret = 'SYNTHETIC_RESTORE_' . $mode . '_' . $forged;
                $object = (object)['secret' => $secret]; $weak = WeakReference::create($object);
                $data = ['life' => $forged, 'evidence' => 'present', 'valid' => true,
                    'allowsRead' => true, 'allowsMutation' => true, 'string' => $secret,
                    'nested' => ['token' => $secret], 'object' => $object];
                if ($mode === 'direct') $s = EnhanceSubscriptionState::fromDecoded(officialStateFixture());
                elseif ($mode === 'without constructor') $s = (new ReflectionClass(EnhanceSubscriptionState::class))->newInstanceWithoutConstructor();
                if ($mode === 'native') {
                    // Build a native serialized object without invoking a helper object's callbacks.
                    $array = serialize($data); $class = EnhanceSubscriptionState::class;
                    $wire = 'O:' . strlen($class) . ':"' . $class . '":' . substr($array, 2);
                    $s = unserialize($wire, ['allowed_classes' => [EnhanceSubscriptionState::class, stdClass::class]]);
                    unset($wire, $array);
                } else $s->__unserialize($data);
                unset($object, $data); gc_collect_cycles();
                check($weak->get() === null, 'Restore retained input object');
                check($s->lifecycle() === ($mode === 'direct' ? 'active' : 'indeterminate'), 'Forged lifecycle');
                check($s->suspensionEvidence() === ($mode === 'direct' ? 'absent' : 'indeterminate'), 'Forged evidence');
                check($s->isStructurallyValid() === ($mode === 'direct') && $s->allowsRead() === ($mode === 'direct'), 'Forged read authority');
                stateNoRetention($s, $secret);
                $copy = clone $s; $copy->life = 'deleted';
                check($copy->jsonSerialize() === $s->jsonSerialize(), 'Clone mutation');
                $diagnostic = $s->jsonSerialize(); $diagnostic['allows_mutation'] = true;
                check(!$s->allowsMutation(), 'Returned array mutated state');
            });
        };
    }
}
