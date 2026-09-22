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
     * The licence success schema is unknown; do not invent a positive assertion.
     */
    public static function contract(string $method, string $path): array
    {
        $route = EnhanceLog::endpoint($path);
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
        } elseif (($method === 'POST' && $route === '/orgs/{id}/members')
            || ($method === 'PATCH' && in_array($route, ['/orgs/{id}', '/orgs/{id}/subscriptions/{id}'], true))
            || ($method === 'DELETE' && in_array($route, ['/orgs/{id}', '/orgs/{id}/subscriptions/{id}', '/orgs/{id}/websites/{id}'], true))) {
            // No success body/204 contract is demonstrated for these acknowledgements.
            $shape = 'unknown';
        } elseif ($method === 'PUT' && $route === '/login/password-recovery') {
            $shape = 'unknown';
        }
        return ['shape' => $shape, 'not_found' => false,
            'empty_204' => $method === 'PUT' && $route === '/login/password-recovery'];
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
        if ($shape === 'id') return self::isId($data['id'] ?? null);
        if ($shape === 'org') return self::isId($data['id'] ?? null) && isset($data['name']) && is_string($data['name']);
        if ($shape === 'subscription') {
            // Both provisioning and daily sync consume these fields. Alternative
            // status-only shapes are insufficient for their shared interpretation.
            if (!self::isId($data['id'] ?? null)) return false;
            if (array_key_exists('isSuspended', $data)) {
                if (!is_bool($data['isSuspended'])) return false;
                if ($data['isSuspended'] === false && (!empty($data['suspendedBy'])
                    || !empty($data['suspended']) || ($data['status'] ?? null) === 'suspended')) return false;
                return true;
            }
            return !empty($data['suspendedBy']) && is_string($data['suspendedBy'])
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
