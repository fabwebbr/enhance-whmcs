<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/Refusal.php';
final class IpPolicy
{
    private const DENY_V4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
        '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
    // Conservative global-unicast envelope plus exclusions. No translation/tunnel
    // ranges: their effective IPv4 destination cannot be validated by this probe.
    private const DENY_V6 = ['2001::/23', '2001:db8::/32', '2002::/16', '3ffe::/16', '3fff::/20'];
    public static function canonical(string $input): string
    {
        if (filter_var($input, FILTER_VALIDATE_IP) === false) throw new Refusal('invalid_ip');
        $binary = inet_pton($input);
        if ($binary === false) throw new Refusal('invalid_ip');
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $binary = substr($binary, 12); // ::ffff:0:0/96 -> ordinary IPv4, BEFORE all policy.
        } elseif (strlen($binary) === 4 && inet_ntop($binary) !== $input) {
            throw new Refusal('noncanonical_ipv4');
        }
        return inet_ntop($binary);
    }
    public static function same(string $a, string $b): bool
    {
        return inet_pton(self::canonical($a)) === inet_pton(self::canonical($b));
    }
    public static function requireGlobal(string $input): string
    {
        $ip = self::canonical($input);
        $v4 = strlen(inet_pton($ip)) === 4;
        if (!$v4 && !self::inRange($ip, '2000::/3')) throw new Refusal('non_global_ip');
        foreach ($v4 ? self::DENY_V4 : self::DENY_V6 as $range) {
            if (self::inRange($ip, $range)) throw new Refusal('non_global_ip');
        }
        return $ip;
    }
    private static function inRange(string $ip, string $range): bool
    {
        [$network, $prefix] = explode('/', $range);
        $a = inet_pton($ip); $b = inet_pton($network); $bits = (int) $prefix;
        if (strlen($a) !== strlen($b)) return false;
        $bytes = intdiv($bits, 8); $tail = $bits % 8;
        return substr($a, 0, $bytes) === substr($b, 0, $bytes)
            && ($tail === 0 || (ord($a[$bytes]) & (255 << (8 - $tail))) === (ord($b[$bytes]) & (255 << (8 - $tail))));
    }
    public static function hostname(string $input): string
    {
        $host = strtolower($input);
        // Numeric/resolver shorthand must not fall back to a DNS hostname.
        if (preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/D', $host)) {
            $ip = self::canonical($host);
            if (strlen(inet_pton($ip)) !== 4) throw new Refusal('invalid_hostname');
            return $ip;
        }
        if (strlen($host) > 253 || !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/D', $host)
            || !preg_match('/\.[a-z][a-z0-9-]*\z/D', $host)) throw new Refusal('invalid_hostname');
        return $host;
    }
}
