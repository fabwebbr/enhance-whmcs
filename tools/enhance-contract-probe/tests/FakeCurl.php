<?php
namespace EnhanceProbe;
if (!defined('PROBE_TEST_ISOLATED')) throw new \RuntimeException('isolated_runner_required');
// Native network functions are disabled and checked by run.php before this file loads.
foreach (['CURLOPT_URL', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_PROTOCOLS',
    'CURLOPT_REDIR_PROTOCOLS', 'CURLPROTO_HTTPS', 'CURLOPT_PROXY', 'CURLOPT_RESOLVE',
    'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT',
    'CURLOPT_HTTPHEADER', 'CURLOPT_WRITEFUNCTION', 'CURLOPT_POSTFIELDS', 'CURLINFO_PRIMARY_IP',
    'CURLINFO_HTTP_CODE', 'CURLINFO_TOTAL_TIME'] as $index => $name) {
    if (!defined($name)) define($name, 40000 + $index);
}
final class FakeCurl
{
    public static array $queue = [];
    public static array $requests = [];
    public static array $info = [];
    public static int $closed = 0;
    public static function reset(array $response): void
    {
        self::$queue = [$response]; self::$requests = []; self::$info = []; self::$closed = 0;
    }
}
function curl_init(): object { return (object) ['options' => [], 'response' => []]; }
function curl_setopt_array($handle, array $options): bool { $handle->options = $options; return true; }
function curl_exec($handle): bool
{
    FakeCurl::$requests[] = $handle->options;
    if (!FakeCurl::$queue) throw new \RuntimeException('unexpected_fake_curl');
    $handle->response = array_shift(FakeCurl::$queue);
    $raw = $handle->response['body'] ?? '';
    return ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $raw) === strlen($raw);
}
function curl_getinfo($handle, int $option): mixed
{
    FakeCurl::$info[] = $option;
    return match ($option) {
        CURLINFO_PRIMARY_IP => $handle->response['peer'] ?? '',
        CURLINFO_HTTP_CODE => $handle->response['http'] ?? 200,
        CURLINFO_TOTAL_TIME => 0.001,
        default => throw new \RuntimeException('unexpected_fake_info'),
    };
}
function curl_errno($handle): int { return 0; }
function curl_close($handle): void { FakeCurl::$closed++; }
