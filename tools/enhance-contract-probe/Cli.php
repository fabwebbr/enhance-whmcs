<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/Engine.php';
final class Cli
{
    /** CLI boundary: neither exception messages nor traces are emitted. */
    public static function run(array $args, array $env, Transport|\Closure $transport, callable $confirmation): array
    {
        try {
            $options = [];
            for ($i = 0; $i < count($args); $i++) {
                $option = $args[$i];
                if (isset($options[$option])) throw new Refusal('duplicate_option');
                if (in_array($option, ['--execute', '--allow-mutation', '--init-session'], true)) $options[$option] = true;
                elseif (in_array($option, ['--operation', '--session', '--config'], true)) {
                    $options[$option] = $args[++$i] ?? throw new Refusal('missing_option');
                    if (str_starts_with($options[$option], '--')) throw new Refusal('invalid_option');
                } else throw new Refusal('unsupported_option');
            }
            if (isset($options['--init-session']) && (isset($options['--operation']) || isset($options['--session']))) throw new Refusal('invalid_options');
            if (isset($options['--session']) && !preg_match('/\A[a-f0-9]{32}\z/D', $options['--session'])) throw new Refusal('invalid_session');
            $probe = new Probe($transport);
            $operation = $options['--operation'] ?? 'licence';
            Catalog::get($operation);
            if (!isset($options['--execute'])) return [0, $probe->run($operation, [], [])];
            $config = LocalConfig::load($options['--config'] ?? '');
            if (isset($options['--init-session'])) {
                return [0, $probe->initialize($config, $env)];
            }
            Probe::target($config, $env); // Validate environment/destination before confirmation.
            [$method] = Catalog::get($operation);
            $answer = '';
            if ($method !== 'GET' && isset($options['--allow-mutation'], $options['--session'])) {
                // Do not echo user-supplied session IDs in a prompt.
                $answer = $confirmation();
            }
            $report = $probe->run($operation, $config, $env, $options['--session'] ?? null,
                true, isset($options['--allow-mutation']), $answer);
            return [($report['phase_1b_category'] ?? '') === 'success'
                && ($report['session_state'] ?? 'ready') === 'ready' ? 0 : 3, $report];
        } catch (\Throwable $e) {
            return [2, ['error' => 'probe_refused', 'instruction' => 'Check homologation settings, opt-ins and the private session manifest. No automatic retry.']];
        }
    }
}
