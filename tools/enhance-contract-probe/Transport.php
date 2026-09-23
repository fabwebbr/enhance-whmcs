<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class Response
{
    public function __construct(private string $raw, public int $http, public int $errno, public int $durationMs) {}
    public function body(): string { return $this->raw; }
    public function __debugInfo(): array { return ['http' => $this->http, 'errno' => $this->errno]; }
    public function __serialize(): array { return $this->__debugInfo(); }
}
interface Transport
{
    public function request(array $target, string $token, string $method, string $path, array $body): Response;
}
final class CurlTransport implements Transport
{
    public function request(array $target, string $token, string $method, string $path, array $body): Response
    {
        $ch = null;
        $raw = '';
        try {
            $ch = curl_init();
            if ($ch === false) return new Response('', 0, 2, 0);
            $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            $options = [CURLOPT_URL => 'https://' . $target['host'] . '/api' . $path,
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$target['host'] . ':443:' . $ip],
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*', 'Content-Type: application/json', 'Authorization: Bearer ' . $token],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                    if (strlen($raw) + strlen($chunk) > 1048576) return 0;
                    $raw .= $chunk;
                    return strlen($chunk);
                }];
            if ($body !== []) $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
            if (!curl_setopt_array($ch, $options)) return new Response('', 0, 2, 0);
            $ok = curl_exec($ch);
            $errno = curl_errno($ch);
            if ($ok === false && $errno === 0) $errno = 2;
            return new Response($raw, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $errno,
                (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000));
        } catch (\Throwable $e) {
            return new Response('', 0, 2, 0);
        } finally {
            if ($ch !== null && $ch !== false) curl_close($ch);
        }
    }
}
