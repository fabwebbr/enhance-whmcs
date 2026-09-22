<?php

/** Logging boundary: only locally selected metadata may become persisted output. */
final class EnhanceLog
{
    /** Replace every dynamic path segment; discard queries, hosts and unknown routes. */
    public static function endpoint(string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $routes = [
            '/licence', '/logins', '/login/password-recovery',
            '/orgs/{id}', '/orgs/{id}/customers',
            '/orgs/{id}/customers/{id}/subscriptions',
            '/orgs/{id}/subscriptions/{id}', '/orgs/{id}/plans',
            '/orgs/{id}/members', '/orgs/{id}/members/{id}/sso',
            '/orgs/{id}/websites', '/orgs/{id}/websites/{id}',
            '/orgs/{id}/websites/{id}/domains', '/orgs/{id}/emails',
        ];
        foreach ($routes as $route) {
            $pattern = str_replace('\\{id\\}', '[^/?#]+', preg_quote($route, '~'));
            if (preg_match('~^' . $pattern . '$~D', $path) === 1) {
                return $route;
            }
        }
        return '[unrecognized endpoint]';
    }

    /** No body, response, remote message or cURL error text is accepted here. */
    public static function metadata(string $method, string $path, int $httpCode, int $curlCode, float $duration, bool $debug): array
    {
        $method = in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $method : 'OTHER';
        $entry = [
            'method' => $method,
            'endpoint' => self::endpoint($path),
            'http_code' => $httpCode,
            'result' => $curlCode !== 0 ? 'transport_error' : ($httpCode >= 400 ? 'http_error' : 'http_response'),
        ];
        if ($curlCode !== 0 || $debug) {
            $entry['curl_code'] = $curlCode;
        }
        if ($debug) {
            $entry['duration_ms'] = is_finite($duration) ? (int) round(max(0, $duration) * 1000) : 0;
        }
        return $entry;
    }

    /**
     * WHMCS's sixth argument is a redaction control list, NEVER persisted metadata.
     * Include the API key and every string leaf of the request as defence in depth,
     * including nested values and opaque serialized strings. Do not log this list.
     */
    public static function replacements(string $apiKey, array $body): array
    {
        $values = $apiKey === '' ? [] : [$apiKey];
        array_walk_recursive($body, static function ($value) use (&$values): void {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        });
        return array_values(array_unique($values));
    }

    /** Snapshot columns are diagnostic sinks, not a second store of API payloads. */
    public static function snapshot(): string
    {
        return '{"payload_omitted":true}';
    }
}
