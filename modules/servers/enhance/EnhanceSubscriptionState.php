<?php

/** Pure, non-integrated interpretation of the required Subscription projection, orchd 12.25.12. */
final class EnhanceSubscriptionState implements JsonSerializable
{
    private const PHP_VERSIONS = ['php52', 'php53', 'php54', 'php55', 'php56', 'php70', 'php71', 'php72', 'php73', 'php74', 'php80', 'php81', 'php82', 'php83', 'php84', 'php85'];
    private const RESOURCES = ['customers', 'diskspace', 'domainAliases', 'forwarders', 'ftpUsers', 'mailboxes', 'mysqlDbs', 'pageViews', 'stagingWebsites', 'transfer', 'websites', 'addonDomains', 'subdomains', 'dnsRecords', 'postgresqlDbs'];

    private function __construct(
        private readonly string $life,
        private readonly string $evidence,
        private readonly bool $valid
    ) {}

    /** Accept associative arrays from JSON decoding; never retain input or cast its values. */
    public static function fromDecoded(mixed $data): self
    {
        if (!is_array($data) || !self::validRequired($data)) {
            return new self('indeterminate', 'indeterminate', false);
        }
        if (array_key_exists('suspendedBy', $data) && !self::uuid($data['suspendedBy'])) {
            return new self('indeterminate', 'indeterminate', false);
        }
        return new self($data['status'], array_key_exists('suspendedBy', $data) ? 'present' : 'absent', true);
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function validRequired(array $data): bool
    {
        foreach (['id', 'planId'] as $key) if (!isset($data[$key]) || !is_int($data[$key])) return false;
        foreach (['planName', 'friendlyName'] as $key) if (!isset($data[$key]) || !is_string($data[$key])) return false;
        foreach (['subscriberId', 'vendorId'] as $key) if (!self::uuid($data[$key] ?? null)) return false;
        if (!in_array($data['status'] ?? null, ['active', 'deleted'], true)) return false;
        if (!in_array($data['planType'] ?? null, ['shared', 'dedicated'], true)) return false;
        if (!in_array($data['defaultPhpVersion'] ?? null, self::PHP_VERSIONS, true)) return false;
        foreach (['redisAllowed', 'persistentAppsAllowed'] as $key) if (!isset($data[$key]) || !is_bool($data[$key])) return false;
        foreach (['resources', 'allowances', 'selections', 'allowedPhpVersions'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) return false;
        }
        foreach ($data['allowedPhpVersions'] as $version) if (!in_array($version, self::PHP_VERSIONS, true)) return false;
        foreach ($data['resources'] as $item) {
            if (!is_array($item) || !in_array($item['name'] ?? null, self::RESOURCES, true)
                || !isset($item['usage']) || !is_int($item['usage'])
                || (array_key_exists('total', $item) && !is_int($item['total']))) return false;
        }
        foreach ($data['allowances'] as $item) {
            if (!is_array($item) || !isset($item['name']) || !is_string($item['name'])) return false;
        }
        foreach ($data['selections'] as $item) {
            if (!is_array($item) || !isset($item['name'], $item['value'])
                || !is_string($item['name']) || !is_string($item['value'])) return false;
        }
        return true;
    }

    /** PHP 8.1 otherwise permits dynamic properties outside the readonly fields. */
    public function __set(string $name, mixed $value): void {}
    public function __unset(string $name): void {}

    public function lifecycle(): string { return $this->life; }
    public function suspensionEvidence(): string { return $this->evidence; }
    public function isStructurallyValid(): bool { return $this->valid; }
    /** Read eligibility is structural only, never proof of service ownership. */
    public function allowsRead(): bool { return $this->valid; }
    public function allowsMutation(): bool { return false; }

    public function jsonSerialize(): array
    {
        return ['lifecycle' => $this->life, 'suspension_evidence' => $this->evidence,
            'structurally_valid' => $this->valid, 'allows_read' => $this->allowsRead(), 'allows_mutation' => false];
    }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
    /** Diagnostic serialization is not a persistence/reconstitution protocol. */
    public function __unserialize(array $data): void
    {
        if (isset($this->life)) return;
        $this->life = 'indeterminate';
        $this->evidence = 'indeterminate';
        $this->valid = false;
    }
}
