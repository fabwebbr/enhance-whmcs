<?php

/** Pure identity evidence evaluator. No discovery, persistence or operation authorization. */
final class EnhanceIdentity implements JsonSerializable
{
    private const ORIGINS = ['created_by_module', 'imported', 'legacy_custom_field', 'admin_linked', 'observed', 'unknown'];
    private const SCHEMA = [
        'binding' => ['installation' => 'opaque', 'server' => 'int', 'service' => 'int', 'client' => 'int', 'product' => 'int', 'vendor' => 'uuid', 'org' => 'uuid', 'subscription' => 'int', 'plan' => 'int', 'origin' => 'origin'],
        'local' => ['service' => 'int', 'client' => 'int', 'server' => 'int', 'product' => 'int'],
        'server' => ['id' => 'int', 'installation' => 'opaque'],
        'remote' => ['installation' => 'opaque', 'org' => 'uuid', 'queriedSubscription' => 'int', 'id' => 'int', 'subscriberId' => 'uuid', 'vendorId' => 'uuid', 'planId' => 'int'],
    ];
    private const CLAIM = ['installation' => 'opaque', 'service' => 'int', 'client' => 'int', 'org' => 'uuid', 'subscription' => 'int'];

    private function __construct(private readonly string $state, private readonly array $reasons, private readonly bool $structural) {}

    public static function evaluate(mixed $input): self
    {
        if (!is_array($input)) return new self('indeterminate', ['invalid_input'], false);
        $legacy = isset($input['binding']) && is_array($input['binding'])
            && ($input['binding']['origin'] ?? null) === 'legacy_custom_field';
        $codes = []; $missing = false; $invalid = false;
        foreach (self::SCHEMA as $section => $fields) {
            if (!array_key_exists($section, $input)) { $missing = true; continue; }
            if (!is_array($input[$section])) { $invalid = true; continue; }
            self::validate($input[$section], $fields, $missing, $invalid);
        }
        if (isset($input['remote']) && is_array($input['remote']) && array_key_exists('planOwner', $input['remote'])
            && !self::valid($input['remote']['planOwner'], 'uuid')) $invalid = true;
        if (!array_key_exists('claims', $input) || !array_key_exists('claimsComplete', $input)) $missing = true;
        if (array_key_exists('claimsComplete', $input)) {
            if (!is_bool($input['claimsComplete'])) $invalid = true;
            elseif (!$input['claimsComplete']) $missing = true;
        }
        if (array_key_exists('claims', $input)) {
            if (!is_array($input['claims']) || !array_is_list($input['claims'])) $invalid = true;
            else foreach ($input['claims'] as $claim) {
                if (!is_array($claim)) { $invalid = true; continue; }
                self::validate($claim, self::CLAIM, $missing, $invalid);
            }
        }
        if ($invalid) $codes[] = 'invalid_input';
        if ($missing) $codes[] = 'incomplete_evidence';
        // Compare every available, correctly typed field even when other evidence is missing.
        $pairs = [
            ['binding', 'service', 'local', 'service', 'service_mismatch'],
            ['binding', 'client', 'local', 'client', 'client_mismatch'],
            ['binding', 'server', 'local', 'server', 'server_mismatch'],
            ['binding', 'product', 'local', 'product', 'product_mismatch'],
            ['local', 'server', 'server', 'id', 'server_mismatch'],
            ['binding', 'installation', 'server', 'installation', 'installation_mismatch'],
            ['binding', 'installation', 'remote', 'installation', 'installation_mismatch'],
            ['binding', 'org', 'remote', 'org', 'org_mismatch'],
            ['binding', 'subscription', 'remote', 'queriedSubscription', 'subscription_mismatch'],
            ['binding', 'subscription', 'remote', 'id', 'subscription_mismatch'],
            ['binding', 'org', 'remote', 'subscriberId', 'subscriber_mismatch'],
            ['binding', 'vendor', 'remote', 'vendorId', 'vendor_mismatch'],
            ['binding', 'plan', 'remote', 'planId', 'plan_mismatch'],
        ];
        foreach ($pairs as [$a, $ak, $b, $bk, $reason]) {
            if (self::has($input, $a, $ak) && self::has($input, $b, $bk)
                && !self::same($input[$a][$ak], $input[$b][$bk], self::SCHEMA[$a][$ak])) $codes[] = $reason;
        }
        if (self::has($input, 'binding', 'vendor') && isset($input['remote']) && is_array($input['remote']) && isset($input['remote']['planOwner'])
            && self::valid($input['remote']['planOwner'], 'uuid')
            && !self::same($input['binding']['vendor'], $input['remote']['planOwner'], 'uuid')) $codes[] = 'plan_vendor_mismatch';
        if (isset($input['claims']) && is_array($input['claims']) && array_is_list($input['claims'])) {
            $seenServices = [];
            foreach ($input['claims'] as $claim) {
                $cm = false; $ci = false;
                if (!is_array($claim)) continue;
                self::validate($claim, self::CLAIM, $cm, $ci);
                if ($cm || $ci || !self::has($input, 'binding', 'installation')) continue;
                if (isset($seenServices[$claim['service']])) $codes[] = 'ambiguous_claims';
                $seenServices[$claim['service']] = true;
                $b = $input['binding'];
                if ($claim['installation'] !== $b['installation']) continue;
                if (self::has($input, 'binding', 'org') && self::has($input, 'binding', 'client')
                    && self::same($claim['org'], $b['org'], 'uuid') && $claim['client'] !== $b['client']) $codes[] = 'org_shared_across_clients';
                if (self::has($input, 'binding', 'service') && self::has($input, 'binding', 'subscription')) {
                    if ($claim['subscription'] === $b['subscription'] && $claim['service'] !== $b['service']) $codes[] = 'duplicate_subscription';
                    if ($claim['service'] === $b['service'] && ($claim['subscription'] !== $b['subscription']
                        || (self::has($input, 'binding', 'client') && $claim['client'] !== $b['client'])
                        || (self::has($input, 'binding', 'org') && !self::same($claim['org'], $b['org'], 'uuid')))) $codes[] = 'service_binding_conflict';
                }
            }
        }
        // A service cannot have a second current binding, including in another installation.
        if (self::has($input, 'binding', 'service') && self::has($input, 'binding', 'installation') && isset($input['claims']) && is_array($input['claims'])) {
            foreach ($input['claims'] as $claim) {
                if (is_array($claim) && isset($claim['service'], $claim['installation']) && $claim['service'] === $input['binding']['service']
                    && self::valid($claim['installation'], 'opaque') && $claim['installation'] !== $input['binding']['installation']) $codes[] = 'service_binding_conflict';
            }
        }
        $conflict = count(array_diff($codes, ['invalid_input', 'incomplete_evidence'])) > 0;
        $origin = self::has($input, 'binding', 'origin') ? $input['binding']['origin'] : 'unknown';
        if ($legacy) $codes[] = 'legacy_binding';
        elseif ($origin !== 'created_by_module') $codes[] = 'origin_not_confirmable';
        $state = $legacy ? 'legacy_pending' : ($conflict ? 'conflict' : ($invalid ? 'indeterminate' : ($missing || $origin !== 'created_by_module' ? 'pending' : 'confirmed')));
        if (!$codes) $codes[] = 'identity_chain_confirmed';
        $codes = array_values(array_unique($codes)); sort($codes, SORT_STRING);
        return new self($state, $codes, !$invalid && !$missing);
    }

    private static function validate(array $data, array $fields, bool &$missing, bool &$invalid): void
    {
        foreach ($fields as $key => $type) {
            if (!array_key_exists($key, $data)) $missing = true;
            elseif (!self::valid($data[$key], $type)) $invalid = true;
        }
    }
    private static function valid(mixed $v, string $type): bool
    {
        if ($type === 'int') return is_int($v) && $v > 0;
        if ($type === 'opaque') return is_string($v) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $v) === 1;
        if ($type === 'origin') return is_string($v) && in_array($v, self::ORIGINS, true);
        return is_string($v) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $v) === 1;
    }
    private static function has(array $input, string $section, string $key): bool
    {
        return isset($input[$section]) && is_array($input[$section]) && array_key_exists($key, $input[$section])
            && self::valid($input[$section][$key], self::SCHEMA[$section][$key]);
    }
    private static function same(mixed $a, mixed $b, string $type): bool { return $type === 'uuid' ? strtolower($a) === strtolower($b) : $a === $b; }
    public function status(): string { return $this->state; }
    public function reasonCodes(): array { return $this->reasons; }
    public function allowsStructuralRead(): bool { return $this->structural; }
    public function allowsOperationalRead(): bool { return $this->state === 'confirmed'; }
    public function allowsSso(): bool { return false; }
    public function allowsMutation(): bool { return false; }
    public function allowsReplacement(): bool { return false; }
    public function allowsRemoval(): bool { return false; }
    public function __set(string $name, mixed $value): void {}
    public function __unset(string $name): void {}
    public function jsonSerialize(): array { return ['identity' => $this->state, 'reasons' => $this->reasons, 'structural_read' => $this->structural, 'operational_read' => $this->allowsOperationalRead(), 'sso' => false, 'mutation' => false, 'replacement' => false, 'removal' => false]; }
    public function __serialize(): array { return $this->jsonSerialize(); }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __unserialize(array $data): void
    {
        if (isset($this->state)) return;
        $this->state = 'indeterminate'; $this->reasons = ['untrusted_serialization']; $this->structural = false;
    }
}
