<?php

/** In-memory response boundary. Payloads never participate in diagnostics. */
final class EnhanceHttpResult implements JsonSerializable
{
    private function __construct(
        public readonly string $category,
        public readonly string $format,
        public readonly int $httpCode,
        public readonly int $curlCode,
        private array $payload = []
    ) {}

    public static function failure(string $category, int $httpCode = 0, int $curlCode = 0): self
    {
        return new self($category, 'unavailable', $httpCode, $curlCode);
    }

    /**
     * Provisional minimum contracts inferred ONLY from existing consumers/fixtures.
     * No production operation declares 404 absence yet: API confirmation is missing.
     * Selected contracts below are documented by orchd 12.25.12, not homologated.
     */
    public static function contract(string $method, string $path): array
    {
        $route = EnhanceLog::endpoint($path);
        // Identity is method + exact sanitized route, never a method-wide ACK rule.
        $documented = [
            'GET /licence' => ['getLicenceInfo', 'licence', 200, false],
            'POST /orgs/{id}/members' => ['createMember', 'uuid', 201, false],
            'PATCH /orgs/{id}' => ['updateOrg', 'ack', 204, true],
            'PATCH /orgs/{id}/subscriptions/{id}' => ['updateSubscription', 'ack', 204, true],
            'DELETE /orgs/{id}/websites/{id}' => ['deleteWebsite', 'ack', 204, true],
            'DELETE /orgs/{id}/subscriptions/{id}' => ['deleteSubscription', 'ack', 204, true],
            'DELETE /orgs/{id}' => ['deleteOrg', 'ack', 204, true],
            'PUT /login/password-recovery' => ['startPasswordRecovery', 'ack', 200, true],
        ];
        if (isset($documented[$method . ' ' . $route])) {
            [$operation, $shape, $status, $empty] = $documented[$method . ' ' . $route];
            return ['operation' => $operation, 'shape' => $shape, 'status' => $status,
                'empty' => $empty, 'not_found' => false, 'evidence' => 'documented'];
        }
        $shape = 'unknown';
        if ($method === 'GET') {
            $shapes = [
                '/orgs/{id}' => 'org',
                '/orgs/{id}/subscriptions/{id}' => 'subscription',
                '/orgs/{id}/websites/{id}' => 'id',
                '/orgs/{id}/customers' => 'customers',
                '/orgs/{id}/customers/{id}/subscriptions' => 'subscriptions',
                '/orgs/{id}/plans' => 'plans',
                '/orgs/{id}/members' => 'members',
                '/logins' => 'logins',
                '/orgs/{id}/websites' => 'websites',
                '/orgs/{id}/websites/{id}/domains' => 'domains',
                '/orgs/{id}/emails' => 'emails',
                '/orgs/{id}/members/{id}/sso' => 'sso',
            ];
            $shape = $shapes[$route] ?? 'unknown';
        } elseif ($method === 'POST' && in_array($route, [
            '/orgs/{id}/customers', '/orgs/{id}/customers/{id}/subscriptions',
            '/logins', '/orgs/{id}/websites',
        ], true)) {
            $shape = 'id';
        }
        return ['shape' => $shape, 'not_found' => false,
            'empty_204' => false];
    }

    /** $contract is internal policy, never derived from response/request parameters. */
    public static function classify($raw, int $httpCode, int $curlCode, array $contract): self
    {
        if ($curlCode !== 0 || $raw === false) {
            return self::failure('transport_error', $httpCode, $curlCode);
        }
        if ($httpCode < 100 || $httpCode > 599) {
            return self::failure('invalid_http_status', $httpCode);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $category = match (true) {
                in_array($httpCode, [401, 403], true) => 'auth_error',
                $httpCode === 429 => 'rate_limited',
                in_array($httpCode, [400, 409, 422], true) => 'validation_error',
                $httpCode >= 500 => 'remote_error',
                $httpCode === 404 && ($contract['not_found'] ?? false) === true => 'not_found',
                default => 'http_error',
            };
            // An HTTP error body is not needed by existing functional consumers.
            return self::failure($category, $httpCode);
        }
        if (isset($contract['operation'], $contract['status'])) {
            if ($httpCode !== $contract['status']) {
                return self::failure($httpCode === 204 ? 'unexpected_empty_response' : 'indeterminate', $httpCode);
            }
            if ($contract['empty'] === true) {
                // Whitespace is still an unexpected body; do not trim ACKs.
                return $raw === ''
                    ? new self('success', 'empty', $httpCode, 0, ['_raw' => ''])
                    : self::failure('invalid_schema', $httpCode);
            }
        }
        if (!in_array($httpCode, [200, 201, 204], true)) {
            return self::failure('indeterminate', $httpCode);
        }
        $text = trim((string) $raw);
        if ($httpCode === 204) {
            return $text === '' && ($contract['empty_204'] ?? false) === true
                ? new self('success', 'empty', $httpCode, 0, ['_raw' => ''])
                : self::failure('unexpected_empty_response', $httpCode);
        }
        if ($text === '') {
            return new self('empty_response', 'empty', $httpCode, 0);
        }
        $shape = $contract['shape'] ?? 'unknown';
        // The existing SSO consumer/fixtures explicitly support plain URL responses.
        if ($shape === 'sso' && self::isUrl($text)) {
            return new self('success', 'text_url', $httpCode, 0, ['_raw' => $text]);
        }
        $value = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $looksJson = preg_match('/^(?:[\{\["\-0-9]|true\b|false\b|null\b)/', $text) === 1;
            return new self($looksJson ? 'invalid_json' : 'non_json', $looksJson ? 'invalid_json' : 'non_json', $httpCode, 0);
        }
        if ($shape === 'sso' && is_string($value) && self::isUrl($value)) {
            return new self('success', 'json', $httpCode, 0, ['_raw' => $value]);
        }
        if (!$value instanceof stdClass) {
            return new self('invalid_schema', 'json', $httpCode, 0);
        }
        $data = json_decode($text, true);
        if (!empty($data['code'])) {
            return new self('api_error', 'json', $httpCode, 0);
        }
        if ($shape === 'unknown') {
            return new self('indeterminate', 'json', $httpCode, 0);
        }
        if ((isset($value->items) && !is_array($value->items)) || !self::valid($shape, $data)) {
            return new self('invalid_schema', 'json', $httpCode, 0);
        }
        // Do not let a remote field impersonate the local raw-SSO adapter.
        unset($data['_raw']);
        return new self('success', 'json', $httpCode, 0, $data);
    }

    private static function isUuid($value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isId($value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && trim($value) !== '' && $value !== '0');
    }

    private static function isUrl($value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }

    private static function valid(string $shape, array $data): bool
    {
        if ($shape === 'uuid') return self::isUuid($data['id'] ?? null);
        if ($shape === 'licence') {
            return isset($data['status']) && is_string($data['status'])
                && in_array($data['status'], ['active', 'cancelled', 'suspended', 'trial', 'unpaid', 'unknown'], true)
                && (!array_key_exists('key', $data) || self::isUuid($data['key']));
        }
        if ($shape === 'id') return self::isId($data['id'] ?? null);
        if ($shape === 'org') return self::isId($data['id'] ?? null) && isset($data['name']) && is_string($data['name']);
        if ($shape === 'subscription') {
            // Only the explicit pre-1C-B boolean fixtures are understood by all
            // consumers. Do not infer state from the documented status/suspendedBy
            // fields or reconcile mixed representations while that work is pending.
            return self::isId($data['id'] ?? null)
                && array_key_exists('isSuspended', $data) && is_bool($data['isSuspended'])
                && !array_key_exists('status', $data)
                && !array_key_exists('suspendedBy', $data)
                && !array_key_exists('suspended', $data);
        }
        if ($shape === 'emails') return isset($data['total']) && is_int($data['total']) && $data['total'] >= 0;
        if ($shape === 'sso') {
            $url = $data['url'] ?? null;
            if ($url === null || $url === '') $url = $data['loginUrl'] ?? null;
            return self::isUrl($url);
        }
        if (!isset($data['items']) || !is_array($data['items']) || !array_is_list($data['items'])) return false;
        foreach ($data['items'] as $item) {
            if ($shape === 'domains' && is_string($item)) continue;
            if (!is_array($item)) return false;
            if ($shape === 'domains') continue; // Domain discovery already accepts variable object shapes.
            if (!self::isId($item['id'] ?? null)) return false;
            if (in_array($shape, ['customers', 'plans'], true) && (!isset($item['name']) || !is_string($item['name']))) return false;
            if ($shape === 'logins' && (!isset($item['email']) || !is_string($item['email']))) return false;
            if ($shape === 'members') {
                if (!isset($item['roles']) || !is_array($item['roles']) || !array_is_list($item['roles'])) return false;
                foreach ($item['roles'] as $role) if (!is_string($role)) return false;
                if (isset($item['isActive']) && !is_bool($item['isActive'])) return false;
            }
        }
        return true;
    }

    /** Apply the individual-resource gate to a selected collection item before writes. */
    public static function requireSubscriptionState(array $subscription): void
    {
        if (!self::valid('subscription', $subscription)) {
            throw new EnhanceTransportException('invalid_schema', 'json', 200, 0);
        }
    }

    /** Public compatibility adapter: failures NEVER return code-shaped pseudo-data. */
    public function data(): array
    {
        if ($this->category !== 'success') {
            throw new EnhanceTransportException($this->category, $this->format, $this->httpCode, $this->curlCode);
        }
        return array_merge($this->payload, ['_httpCode' => $this->httpCode]);
    }

    public function jsonSerialize(): array
    {
        return ['category' => $this->category, 'format' => $this->format,
            'http_code' => $this->httpCode, 'curl_code' => $this->curlCode];
    }

    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
}

/** Contains only local classification and numeric codes; never attach a payload/previous exception. */
final class EnhanceTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly string $format,
        public readonly int $httpCode,
        public readonly int $curlCode
    ) {
        parent::__construct('Enhance request failed (' . $category . ').');
    }

    /** Avoid serializing native traces (which may contain caller arguments). */
    public function __serialize(): array
    {
        return ['category' => $this->category, 'format' => $this->format,
            'http_code' => $this->httpCode, 'curl_code' => $this->curlCode,
            'message' => $this->getMessage()];
    }
    public function __debugInfo(): array { return $this->__serialize(); }
    public function __toString(): string { return $this->getMessage(); }
}
