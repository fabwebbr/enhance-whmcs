<?php
// Global fakes are declared only after the isolation guard passes.
namespace WHMCS\Database {
    final class Capsule
    {
        public static array $writes = [];
        public static bool $exists = false;
        public static function table($name) { return new Query($name); }
        public static function schema() { return new class {
            public function hasTable($name) { return true; }
        }; }
    }
    final class Query
    {
        public function __construct(private string $table) {}
        public function where(...$args) { return $this; }
        public function exists() { return Capsule::$exists; }
        public function insert($data) { Capsule::$writes[] = [$this->table, 'insert', $data]; }
        public function update($data) { Capsule::$writes[] = [$this->table, 'update', $data]; }
    }
}

namespace {
    if (!defined('ENHANCE_TEST_ISOLATED') || function_exists('curl_init')) {
        throw new \RuntimeException('Use sh tests/php-isolated.sh tests/run.php');
    }
    foreach (['CURLOPT_URL', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_CUSTOMREQUEST',
        'CURLOPT_HTTPHEADER', 'CURLOPT_TIMEOUT', 'CURLOPT_SSL_VERIFYHOST',
        'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_POSTFIELDS', 'CURLINFO_HTTP_CODE',
        'CURLINFO_TOTAL_TIME'] as $index => $name) {
        if (!defined($name)) { define($name, $index + 1); }
    }
    $GLOBALS['httpQueue'] = [];
    $GLOBALS['requests'] = [];
    $GLOBALS['moduleLogs'] = [];
    $GLOBALS['activityLogs'] = [];

    // Conditional declaration avoids collisions during standalone PHP lint.
    // This is NOT a fallback: the guard above throws if isolation is absent.
    if (defined('ENHANCE_TEST_ISOLATED')) {
    function curl_init() { return (object) ['options' => [], 'reply' => null]; }
    function curl_setopt_array($ch, $options) { $ch->options = $options; return true; }
    function curl_setopt($ch, $option, $value) { $ch->options[$option] = $value; return true; }
    function curl_exec($ch) {
        if (!$GLOBALS['httpQueue']) { throw new \RuntimeException('Unexpected HTTP call; no fake response queued'); }
        $ch->reply = array_shift($GLOBALS['httpQueue']);
        $GLOBALS['requests'][] = $ch->options;
        return $ch->reply['raw'];
    }
    function curl_error($ch) { return $ch->reply['error']; }
    function curl_errno($ch) { return $ch->reply['errno']; }
    function curl_getinfo($ch, $option) { return $option === CURLINFO_HTTP_CODE ? $ch->reply['status'] : 0.125; }
    function curl_close($ch) {}

    function logModuleCall($module, $action, $request, $response, $processed, $replace) {
        // The first five arguments are persistence candidates. The sixth is
        // WHMCS redaction configuration, kept separately and NEVER printed.
        $GLOBALS['moduleLogs'][] = [
            'output' => [$module, $action, $request, $response, $processed],
            'replace' => $replace,
        ];
    }
    function logActivity($message) { $GLOBALS['activityLogs'][] = $message; }
    function localAPI(...$args) { throw new \RuntimeException('Unexpected WHMCS API call'); }
    }
}
