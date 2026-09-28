<?php
if (!defined('ENHANCE_TEST_ISOLATED')) throw new RuntimeException('Use isolated runner');
require __DIR__ . '/../modules/servers/enhance/EnhanceIdentity.php';
function identityFixture(): array
{
    $org = '11111111-2222-4333-8444-555555555555'; $vendor = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    return ['binding' => ['installation' => 'synthetic-installation', 'server' => 2, 'service' => 3, 'client' => 4, 'product' => 5,
        'vendor' => $vendor, 'org' => $org, 'subscription' => 6, 'plan' => 7, 'origin' => 'created_by_module'],
        'local' => ['service' => 3, 'client' => 4, 'server' => 2, 'product' => 5],
        'server' => ['id' => 2, 'installation' => 'synthetic-installation'],
        'remote' => ['installation' => 'synthetic-installation', 'org' => $org, 'queriedSubscription' => 6, 'id' => 6,
            'subscriberId' => $org, 'vendorId' => $vendor, 'planId' => 7, 'planOwner' => $vendor],
        'claims' => [], 'claimsComplete' => true];
}
function identityClaim(): array { return array_intersect_key(identityFixture()['binding'], array_flip(['installation', 'service', 'client', 'org', 'subscription'])); }
function identityAssert(mixed $input, string $expected, array $codes): EnhanceIdentity
{
    $before = serialize($input); $effects = [$GLOBALS['httpAttempts'], $GLOBALS['moduleLogs'], $GLOBALS['activityLogs'], \WHMCS\Database\Capsule::$writes];
    $r = EnhanceIdentity::evaluate($input);
    check($r->status() === $expected, 'Wrong identity classification'); sort($codes, SORT_STRING);
    check($r->reasonCodes() === $codes, 'Wrong identity reason');
    check(!$r->allowsMutation() && !$r->allowsSso() && !$r->allowsRemoval() && !$r->allowsReplacement(), 'Identity granted forbidden permission');
    check($r->allowsOperationalRead() === ($expected === 'confirmed'), 'Wrong operational read');
    check($before === serialize($input), 'Input mutated');
    check($effects === [$GLOBALS['httpAttempts'], $GLOBALS['moduleLogs'], $GLOBALS['activityLogs'], \WHMCS\Database\Capsule::$writes], 'Identity side effects');
    assertSafe([serialize($r), json_encode($r), var_export($r, true), $r->__debugInfo()]);
    foreach (['11111111-2222-4333-8444-555555555555', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'synthetic-installation'] as $value) {
        check(!str_contains(serialize($r), $value), 'Identity leaked identifier');
    }
    return $r;
}
$tests['identity complete new chain'] = static function (): void { $r=identityAssert(identityFixture(), 'confirmed', ['identity_chain_confirmed']); check($r->allowsStructuralRead(), 'Structural read missing'); };
foreach (['legacy_custom_field', 'imported', 'admin_linked', 'observed', 'unknown'] as $origin) {
    $tests['identity origin ' . $origin] = static function () use ($origin): void {
        $p=identityFixture(); $p['binding']['origin']=$origin;
        identityAssert($p, $origin==='legacy_custom_field'?'legacy_pending':'pending', [$origin==='legacy_custom_field'?'legacy_binding':'origin_not_confirmable']);
    };
}
foreach (identityFixture() as $section => $fields) {
    if (!is_array($fields) || $section==='claims') continue;
    foreach ($fields as $field => $value) {
        if ($field==='planOwner') continue;
        $tests['identity missing '.$section.' '.$field] = static function () use ($section,$field): void {
            $p=identityFixture(); unset($p[$section][$field]);
            identityAssert($p,'pending',$field==='origin'?['incomplete_evidence','origin_not_confirmable']:['incomplete_evidence']);
        };
        foreach ([null, false, [], (object)[], 1.5] as $i=>$bad) {
            $tests['identity invalid '.$section.' '.$field.' '.$i] = static function () use ($section,$field,$bad): void {
                $p=identityFixture(); $p[$section][$field]=$bad;
                identityAssert($p,'indeterminate',$field==='origin'?['invalid_input','origin_not_confirmable']:['invalid_input']);
            };
        }
    }
}
$changes=[['local','client',9,'client_mismatch'],['local','product',9,'product_mismatch'],['local','service',9,'service_mismatch'],
    ['server','installation','other-installation','installation_mismatch'],['remote','installation','other-installation','installation_mismatch'],
    ['remote','org','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','org_mismatch'],['remote','id',9,'subscription_mismatch'],
    ['remote','queriedSubscription',9,'subscription_mismatch'],['remote','subscriberId','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','subscriber_mismatch'],
    ['remote','vendorId','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','vendor_mismatch'],['remote','planOwner','bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','plan_vendor_mismatch'],['remote','planId',9,'plan_mismatch']];
foreach ($changes as [$section,$field,$value,$code]) {
    $tests['identity divergence '.$section.' '.$field]=static function () use ($section,$field,$value,$code): void {
        $p=identityFixture(); $p[$section][$field]=$value; identityAssert($p,'conflict',[$code]);
    };
}
foreach (['same client', 'other client', 'duplicate subscription', 'other installation', 'other service binding', 'duplicate claims'] as $case) {
    $tests['identity claims '.$case]=static function () use ($case): void {
        $p=identityFixture(); $c=identityClaim(); $c['service']=8; $c['subscription']=9; $codes=[];
        if ($case==='other client') { $c['client']=9; $codes=['org_shared_across_clients']; }
        if ($case==='duplicate subscription') { $c['subscription']=6; $codes=['duplicate_subscription']; }
        if ($case==='other installation') { $c['installation']='other-installation'; $c['subscription']=6; $c['client']=9; }
        if ($case==='other service binding') { $c['service']=3; $codes=['service_binding_conflict']; }
        $p['claims']=[$c];
        if ($case==='duplicate claims') { $p['claims'][]=$c; $codes=['ambiguous_claims']; }
        identityAssert($p,$codes?'conflict':'confirmed',$codes?:['identity_chain_confirmed']);
    };
}
$tests['identity two server configurations one installation']=static function (): void {
    $p=identityFixture(); $p['binding']['server']=10; $p['local']['server']=10; $p['server']['id']=10;
    $c=identityClaim(); $c['service']=11; $c['subscription']=12; $p['claims']=[$c]; identityAssert($p,'confirmed',['identity_chain_confirmed']);
};
$tests['identity server changed']=static function (): void { $p=identityFixture(); $p['local']['server']=10; $p['server']['id']=10; $p['server']['installation']='other'; identityAssert($p,'conflict',['server_mismatch','installation_mismatch']); };
foreach (['post id only','names only','single item','first matching plan','inventory incomplete'] as $case) {
    $tests['identity insufficient '.$case]=static function () use ($case): void {
        $p=identityFixture();
        if ($case==='inventory incomplete') $p['claimsComplete']=false;
        else $p['remote']=['id'=>6, 'planId'=>7, 'name'=>PASSWORD, 'email'=>EMAIL, 'domain'=>SSO, 'items'=>[['id'=>6,'planId'=>7]]];
        identityAssert($p,'pending',['incomplete_evidence']);
    };
}
$tests['identity optional plan owner missing']=static function (): void { $p=identityFixture(); unset($p['remote']['planOwner']); identityAssert($p,'confirmed',['identity_chain_confirmed']); };
$tests['identity legacy conflicts never promote']=static function (): void { $p=identityFixture(); $p['binding']['origin']='legacy_custom_field'; $p['local']['client']=10; identityAssert($p,'legacy_pending',['legacy_binding','client_mismatch']); };
$tests['identity deterministic conflicts']=static function (): void {
    $p=identityFixture(); $p['local']['client']=9; $p['remote']['id']=9; $p['remote']['planId']=9;
    $a=identityAssert($p,'conflict',['client_mismatch','subscription_mismatch','plan_mismatch']);
    $p=array_reverse($p,true); $b=EnhanceIdentity::evaluate($p); check($a->jsonSerialize()===$b->jsonSerialize(),'Order changed decision');
};
foreach ([null, false, 1, PASSWORD, (object)[]] as $i=>$p) $tests['identity invalid root '.$i]=static fn()=>identityAssert($p,'indeterminate',['invalid_input']);
$tests['identity magic confidentiality']=static function (): void {
    stateSilent(static function (): void {
        $p=identityFixture(); $p['extras']=payload(); $r=identityAssert($p,'confirmed',['identity_chain_confirmed']);
        $before=$r->jsonSerialize(); $o=(object)['secret'=>'IDENTITY_SYNTHETIC_SECRET']; $weak=WeakReference::create($o);
        $r->payload=$o; unset($r->payload); $r->__unserialize(['state'=>'conflict','secret'=>$o]);
        unset($o); gc_collect_cycles(); check($weak->get()===null,'Retained magic input');
        check($r->jsonSerialize()===$before && !property_exists($r,'payload'),'Magic mutation');
        foreach ((new ReflectionClass($r))->getProperties() as $property) if (!$property->isStatic()) {
            check($property->isPrivate() && $property->isReadOnly(),'Mutable identity field');
            check(!str_contains(serialize($property->getValue($r)),'IDENTITY_SYNTHETIC_SECRET'),'Retained secret');
        }
        $data=['state'=>'confirmed','structural'=>true,'reasons'=>payload()]; $wire=serialize($data); $class=EnhanceIdentity::class;
        $restored=unserialize('O:'.strlen($class).':"'.$class.'":'.substr($wire,2),['allowed_classes'=>[$class]]);
        check($restored->status()==='indeterminate' && !$restored->allowsOperationalRead() && !$restored->allowsStructuralRead(),'Forged serialized state');
        assertSafe($restored->__debugInfo()); check(!$restored->allowsMutation() && !$restored->allowsSso(),'Forged authority');
        $blank=(new ReflectionClass($r))->newInstanceWithoutConstructor(); $blank->__unserialize($data);
        check($blank->jsonSerialize()===$restored->jsonSerialize(),'Unsafe blank initialization');
        $reasons=$r->reasonCodes(); $reasons[]=PASSWORD; $clone=clone $r; $clone->state='conflict';
        check($r->jsonSerialize()===$before && $clone->jsonSerialize()===$before,'Mutable copy');
    });
};
foreach (['binding','local','server','remote','claims','claimsComplete'] as $field) {
    $tests['identity absent section '.$field]=static function () use ($field): void {
        $p=identityFixture(); unset($p[$field]);
        identityAssert($p,'pending',$field==='binding'?['incomplete_evidence','origin_not_confirmable']:['incomplete_evidence']);
    };
}
foreach ([null, false, 'x', (object)[]] as $i=>$bad) {
    $tests['identity invalid claims '.$i]=static function () use ($bad): void { $p=identityFixture(); $p['claims']=$bad; identityAssert($p,'indeterminate',['invalid_input']); };
    $tests['identity invalid remote section '.$i]=static function () use ($bad): void { $p=identityFixture(); $p['remote']=$bad; identityAssert($p,'indeterminate',['invalid_input']); };
}
$tests['identity incomplete claim blocks confirmation']=static function (): void { $p=identityFixture(); $p['claims']=[['installation'=>'other']]; identityAssert($p,'pending',['incomplete_evidence']); };
$tests['identity malformed claim blocks confirmation']=static function (): void { $p=identityFixture(); $c=identityClaim(); $c['client']=EMAIL; $p['claims']=[$c]; identityAssert($p,'indeterminate',['invalid_input']); };
$tests['identity service claimed in another installation']=static function (): void { $p=identityFixture(); $c=identityClaim(); $c['installation']='other'; $p['claims']=[$c]; identityAssert($p,'conflict',['service_binding_conflict']); };
$tests['identity optional plan owner invalid']=static function (): void { $p=identityFixture(); $p['remote']['planOwner']=null; identityAssert($p,'indeterminate',['invalid_input']); };
$tests['identity UUID case normalization']=static function (): void { $p=identityFixture(); $p['remote']['vendorId']=strtoupper($p['binding']['vendor']); identityAssert($p,'confirmed',['identity_chain_confirmed']); };
$tests['identity repeated same service claim is ambiguous']=static function (): void { $p=identityFixture(); $p['claims']=[identityClaim(),identityClaim()]; identityAssert($p,'conflict',['ambiguous_claims']); };
$tests['identity server id is not installation identity']=static function (): void { $p=identityFixture(); unset($p['binding']['installation']); $p['hostname']='example.invalid'; $p['token']=API_KEY; identityAssert($p,'pending',['incomplete_evidence']); };
$tests['identity malformed inventory completeness']=static function (): void { $p=identityFixture(); $p['claimsComplete']='true'; identityAssert($p,'indeterminate',['invalid_input']); };
