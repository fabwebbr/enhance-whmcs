<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Turn warnings into a fixed exception so filesystem/cURL diagnostics cannot leak.
set_error_handler(static function (): never { throw new RuntimeException('probe_local_error'); });
require __DIR__ . '/Cli.php';
$env = [];
foreach (['ENHANCE_PROBE_ENV', 'ENHANCE_PROBE_API_KEY', 'ENHANCE_PROBE_SESSION_KEY'] as $name) {
    $env[$name] = getenv($name) ?: '';
}
[$exit, $report] = \EnhanceProbe\Cli::run(array_slice($argv, 1), $env, static fn() => new \EnhanceProbe\CurlTransport(), static function (): string {
    fwrite(STDERR, "Type CONFIRM followed by a space and the local session identifier: ");
    return trim((string) fgets(STDIN, 128));
});
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($exit);
