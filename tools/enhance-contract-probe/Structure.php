<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class Structure
{
    // Unknown keys may themselves be emails, IDs, tokens or messages. Never echo them.
    private const KEYS = ['id', 'name', 'items', 'total', 'isSuspended', 'suspendedBy',
        'suspended', 'status', 'planId', 'subscriptionId', 'roles', 'isActive', 'code',
        'message', 'detail', 'valid', 'type', 'createdAt', 'updatedAt', 'limits',
        'ownerEmail', 'email', 'domain', 'primaryDomain', 'url', 'loginUrl', 'subscriptionsCount', 'websitesCount', 'plan', 'package', 'planName', 'packageId'];
    public static function shape($value, int $depth = 0): array|string
    {
        if ($depth >= 12) return '<depth-limit>';
        if (is_object($value)) {
            $properties = [];
            $unknown = 0;
            $unknownShapes = [];
            foreach (get_object_vars($value) as $key => $child) {
                if (in_array((string) $key, self::KEYS, true)) $properties[$key] = self::shape($child, $depth + 1);
                else {
                    $unknown++;
                    if ($unknown <= 100) $unknownShapes[] = self::shape($child, $depth + 1);
                }
            }
            ksort($properties);
            return ['type' => 'object', 'properties' => (object) $properties, 'omitted_keys' => $unknown, 'unknown_value_shapes' => $unknownShapes];
        }
        if (is_array($value)) {
            $shapes = [];
            foreach (array_slice($value, 0, 100) as $child) {
                $shape = self::shape($child, $depth + 1);
                $shapes[json_encode($shape, JSON_THROW_ON_ERROR)] = $shape;
            }
            return ['type' => 'list', 'count' => count($value), 'item_shapes' => array_values($shapes), 'sampled' => count($value) > 100];
        }
        return match (gettype($value)) {
            'NULL' => '<null>', 'boolean' => '<boolean>', 'integer' => '<integer>',
            'double' => '<number>', default => '<string>',
        };
    }
    public static function report(string $operation, Response $response, string $correlation): array
    {
        [$method, $path] = Catalog::get($operation);
        $raw = $response->body();
        $value = json_decode($raw, false, 64);
        $valid = json_last_error() === JSON_ERROR_NONE;
        $classification = \EnhanceHttpResult::classify($response->errno ? false : $raw,
            $response->http, $response->errno, \EnhanceHttpResult::contract($method, Catalog::route($operation)));
        $present = [];
        foreach (['id', 'name', 'items', 'total', 'isSuspended', 'suspendedBy', 'suspended', 'status', 'roles', 'isActive', 'planId', 'subscriptionId', 'ownerEmail', 'email', 'domain', 'primaryDomain', 'url', 'loginUrl', 'subscriptionsCount', 'websitesCount', 'plan', 'package', 'planName', 'packageId'] as $field) {
            $present[$field] = $valid && is_object($value) && property_exists($value, $field);
        }
        return ['version' => 1, 'correlation' => $correlation, 'method' => $method,
            'route' => Catalog::route($operation), 'http' => $response->http, 'errno' => $response->errno,
            'duration_ms' => $response->durationMs, 'body_present' => $raw !== '',
            'format' => $raw === '' ? 'empty' : ($valid ? 'json' : 'non_json'),
            'json_valid' => $valid, 'root' => !$valid ? 'unavailable' : match (true) {
                is_object($value) => 'object', is_array($value) => 'list', is_null($value) => 'null',
                is_string($value) => 'string', is_bool($value) => 'boolean', default => 'number',
            }, 'structure' => $valid ? self::shape($value) : '<omitted>',
            'consumed_fields_present' => $present, 'phase_1b_category' => $classification->category];
    }
}
