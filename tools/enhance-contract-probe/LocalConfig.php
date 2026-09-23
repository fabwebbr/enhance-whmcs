<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/Refusal.php';
final class LocalConfig
{
    public const MAX_BYTES = 65536;
    public static function load(string $path): array
    {
        // Lexical check precedes EVERY filesystem operation on user input. No URI,
        // percent escapes, backslashes, whitespace, controls or Unicode normalization.
        if ($path === '' || strlen($path) > 4096 || !preg_match('~\A[A-Za-z0-9_./-]+\z~D', $path)
            || str_contains($path, '//') || in_array('..', explode('/', $path), true)) throw new Refusal('local_config_path_required');
        set_error_handler(static function (): never { throw new Refusal('config_read_refused'); });
        $handle = null;
        try {
            $absolute = $path[0] === '/' ? $path : getcwd() . '/' . $path;
            $before = self::inspect($absolute);
            $real = realpath($absolute);
            if ($real === false) throw new Refusal('local_config_required');
            self::same($before, self::inspect($real));
            // PHP streams do not expose O_NOFOLLOW. Known links are rejected above;
            // no bytes are read unless the opened inode matches both path checks.
            $handle = fopen($real, 'rb');
            if ($handle === false || !flock($handle, LOCK_SH | LOCK_NB)) throw new Refusal('config_read_refused');
            self::same($before, fstat($handle));
            self::same($before, self::inspect($absolute));
            self::same($before, self::inspect($real));
            $json = stream_get_contents($handle, self::MAX_BYTES + 1);
            if ($json === false || strlen($json) > self::MAX_BYTES || strlen($json) !== $before['size']) throw new Refusal('config_size_invalid');
            self::same($before, fstat($handle));
            self::same($before, self::inspect($absolute));
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value)) throw new Refusal('invalid_config');
            self::validate($value);
            return $value;
        } finally {
            if (is_resource($handle)) fclose($handle);
            restore_error_handler();
        }
    }
    private static function inspect(string $absolute): array
    {
        $parts = array_values(array_filter(explode('/', $absolute), static fn($part) => $part !== '' && $part !== '.'));
        $current = '';
        foreach ($parts as $index => $part) {
            $current .= '/' . $part;
            clearstatcache(true, $current);
            $stat = lstat($current);
            if ($stat === false) throw new Refusal('local_config_required');
            if ($index < count($parts) - 1) {
                if (($stat['mode'] & 0170000) !== 0040000
                    || (($stat['mode'] & 0022) !== 0 && ($stat['mode'] & 01000) === 0)) throw new Refusal('unsafe_config_directory');
            } elseif (($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1 || $stat['size'] > self::MAX_BYTES) {
                throw new Refusal('regular_bounded_config_required');
            }
        }
        if (!isset($stat)) throw new Refusal('local_config_required');
        return $stat;
    }
    private static function same(array $before, array|false $after): void
    {
        if ($after === false || ($after['mode'] & 0170000) !== 0100000) throw new Refusal('config_changed');
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $key) {
            if ($before[$key] !== $after[$key]) throw new Refusal('config_changed');
        }
    }
    public static function validate(array $config): void
    {
        $keys = ['environment', 'host', 'pinned_ip', 'tls_verify', 'blocked_hosts', 'blocked_ips',
            'master_org', 'plan_a', 'plan_b', 'session_directory'];
        if (array_diff($keys, array_keys($config)) || array_diff(array_keys($config), $keys)) throw new Refusal('invalid_config_fields');
        foreach (['environment', 'host', 'pinned_ip', 'master_org', 'session_directory'] as $key) {
            if (!is_string($config[$key]) || $config[$key] === '' || preg_match('/[\x00-\x20\x7f]/', $config[$key])) throw new Refusal('invalid_config_type');
        }
        if ($config['tls_verify'] !== true || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $config['master_org'])) throw new Refusal('invalid_config');
        foreach (['plan_a', 'plan_b'] as $key) if (!is_int($config[$key]) || $config[$key] < 1) throw new Refusal('invalid_config_plan');
        foreach (['blocked_hosts', 'blocked_ips'] as $key) {
            if (!is_array($config[$key]) || !array_is_list($config[$key]) || $config[$key] === []) throw new Refusal('blocklist_required');
            foreach ($config[$key] as $entry) if (!is_string($entry) || $entry === '') throw new Refusal('invalid_blocklist');
        }
    }
}
