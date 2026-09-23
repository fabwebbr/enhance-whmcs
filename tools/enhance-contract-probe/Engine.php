<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/LocalConfig.php';
require_once __DIR__ . '/IpPolicy.php';
require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/Structure.php';
require_once __DIR__ . '/Session.php';
require_once dirname(__DIR__, 2) . '/modules/servers/enhance/EnhanceLog.php';
require_once dirname(__DIR__, 2) . '/modules/servers/enhance/EnhanceHttpResult.php';

final class Probe
{
    public function __construct(private Transport|\Closure $transport) {}
    public static function target(array $config, array $env): array
    {
        LocalConfig::validate($config);
        if (($env['ENHANCE_PROBE_ENV'] ?? '') !== 'homologation' || $config['environment'] !== 'homologation') throw new Refusal('homologation_required');
        $host = IpPolicy::hostname($config['host']);
        $ip = IpPolicy::requireGlobal($config['pinned_ip']);
        // Validate ALL entries before comparison; malformed entries are never skipped.
        $blockedHosts = array_map([IpPolicy::class, 'hostname'], $config['blocked_hosts']);
        $blockedIps = array_map([IpPolicy::class, 'canonical'], $config['blocked_ips']);
        foreach ($blockedHosts as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) throw new Refusal('blocked_target');
        }
        foreach ($blockedIps as $blocked) {
            if (IpPolicy::same($ip, $blocked)) throw new Refusal('blocked_target');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && !IpPolicy::same($host, $ip)) throw new Refusal('invalid_target');
        return ['host' => $host, 'ip' => $ip];
    }
    private static function binding(array $config, array $target): string
    {
        return hash('sha256', json_encode([$target, $config['master_org'] ?? null, $config['plan_a'] ?? null, $config['plan_b'] ?? null], JSON_THROW_ON_ERROR));
    }
    public function initialize(array $config, array $env): array
    {
        $target = self::target($config, $env);
        $id = bin2hex(random_bytes(16));
        $session = new Session($config['session_directory'], $id, $env['ENHANCE_PROBE_SESSION_KEY'] ?? '', self::binding($config, $target), true);
        return ['session' => $session->id(), 'state' => 'ready', 'requests' => 0];
    }
    public function run(string $operation, array $config, array $env, ?string $sessionId = null,
        bool $execute = false, bool $allowMutation = false, string $confirmation = ''): array
    {
        [$method, $template, $createdKind] = Catalog::get($operation);
        if (!$execute) return ['mode' => 'dry-run', 'method' => $method, 'route' => Catalog::route($operation), 'requests' => 0];
        $target = self::target($config, $env);
        $mutation = $method !== 'GET';
        if ($mutation && !$allowMutation) throw new Refusal('mutation_opt_in_required');
        $token = $env['ENHANCE_PROBE_API_KEY'] ?? '';
        if (!is_string($token) || $token === '' || preg_match('/[\r\n\x00]/', $token)) throw new Refusal('credential_required');
        $session = $sessionId === null ? null : new Session($config['session_directory'], $sessionId,
            $env['ENHANCE_PROBE_SESSION_KEY'] ?? '', self::binding($config, $target));
        if ($mutation) {
            if ($session === null || $confirmation !== 'CONFIRM ' . $session->id()) throw new Refusal('session_confirmation_required');
            $session->ready();
            if ($createdKind !== null && $session->has($createdKind)) throw new Refusal('resource_already_created');
        }
        $ids = ['master' => (string) ($config['master_org'] ?? '')];
        foreach (['org', 'subscription', 'website'] as $kind) {
            if (str_contains($template, '{' . $kind . '}')) $ids[$kind] = $session?->resource($kind) ?? throw new Refusal('session_resource_required');
        }
        foreach ($ids as $id) if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id)) throw new Refusal('invalid_resource_id');
        $path = $template;
        foreach ($ids as $kind => $id) $path = str_replace('{' . $kind . '}', rawurlencode($id), $path);
        $label = 'probe-' . ($session?->id() ?? 'read');
        $email = $label . '@example.invalid';
        $plan = $operation === 'change-plan' ? ($config['plan_b'] ?? null) : ($config['plan_a'] ?? null);
        if (in_array($operation, ['create-subscription', 'change-plan'], true) && (!is_int($plan) || $plan < 1)) throw new Refusal('supervised_test_plan_required');
        if (in_array($operation, ['owner', 'recover-password'], true)) $login = $session->resource('login');
        if ($operation === 'create-website') $subscription = $session->resource('subscription');
        $body = match ($operation) {
            'create-org' => ['name' => $label],
            'create-login' => ['email' => $email, 'password' => bin2hex(random_bytes(24)), 'name' => $label],
            'owner' => ['loginId' => $login, 'roles' => ['Owner']],
            'create-subscription', 'change-plan' => ['planId' => $plan],
            'create-website' => ['domain' => $label . '.invalid', 'subscriptionId' => $subscription],
            'rename-org' => ['name' => $label . '-renamed'],
            'suspend-org', 'suspend-subscription' => ['isSuspended' => true],
            'reactivate-org', 'reactivate-subscription' => ['isSuspended' => false],
            'recover-password' => ['email' => $email], default => [],
        };
        if ($mutation) $session->begin($operation);
        try {
            $transport = $this->transport instanceof \Closure ? ($this->transport)() : $this->transport;
            $response = $transport->request($target, $token, $method, $path, $body);
        }
        catch (\Throwable $e) { $response = new Response('', 0, 2, 0); }
        $report = Structure::report($operation, $response, bin2hex(random_bytes(16)));
        if ($mutation) {
            $category = $report['phase_1b_category'];
            $id = null;
            if ($createdKind !== null && $category === 'success') {
                $value = json_decode($response->body(), true);
                $id = (string) ($value['id'] ?? '');
                if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) || $id === $ids['master']) { $category = 'invalid_schema'; $id = null; }
            }
            $session->finish($category, $id !== null ? $createdKind : null, $id);
            $report['session_state'] = $category === 'success' ? 'ready' : 'indeterminate';
        }
        if (!$mutation && $session !== null && $report['phase_1b_category'] !== 'success') {
            $session->halt($operation, $report['phase_1b_category']);
        }
        if ($session !== null) $report['session_state'] = $session->state();
        return $report;
    }
}
