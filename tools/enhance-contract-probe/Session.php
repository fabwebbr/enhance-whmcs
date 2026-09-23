<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/Refusal.php';

/** A locked, authenticated local journal. No import/ID override or reset command. */
final class Session
{
    private $handle;
    private array $data;
    public function __construct(string $directory, string $id, private string $key, string $target, bool $create = false)
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || strlen($key) < 32) throw new Refusal('invalid_session');
        $root = realpath(dirname(__DIR__, 2));
        $real = realpath($directory);
        if ($real === false || $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            || is_link($directory) || (fileperms($real) & 0077) !== 0) throw new Refusal('private_directory_required');
        $file = $real . '/session-' . $id . '.json';
        if (is_link($file) || (!$create && (!is_file($file) || (fileperms($file) & 0077) !== 0))) throw new Refusal('invalid_manifest');
        $this->handle = fopen($file, $create ? 'x+b' : 'r+b');
        if ($this->handle === false || !flock($this->handle, LOCK_EX | LOCK_NB)) throw new Refusal('session_locked');
        if ($create) {
            chmod($file, 0600);
            $this->data = ['version' => 1, 'session' => $id, 'target' => $target, 'state' => 'ready', 'resources' => [], 'events' => []];
            $this->save();
        } else {
            $envelope = json_decode(stream_get_contents($this->handle), true, 64, JSON_THROW_ON_ERROR);
            $data = $envelope['data'] ?? [];
            if (!is_array($data) || !is_string($envelope['signature'] ?? null)
                || !hash_equals(hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $key), $envelope['signature'])
                || ($data['target'] ?? null) !== $target || ($data['session'] ?? null) !== $id) throw new Refusal('invalid_manifest');
            $this->data = $data;
        }
    }
    public function __destruct()
    {
        if (is_resource($this->handle)) { flock($this->handle, LOCK_UN); fclose($this->handle); }
    }
    public function __debugInfo(): array { return ['manifest' => '<private>']; }
    public function __serialize(): array { throw new Refusal('session_not_serializable'); }
    public function id(): string { return $this->data['session']; }
    public function resource(string $kind): string
    {
        $resource = $this->data['resources'][$kind] ?? null;
        if (!is_array($resource) || ($resource['created_by_session'] ?? null) !== $this->id()) throw new Refusal('session_resource_required');
        return $resource['id'];
    }
    public function has(string $kind): bool { return isset($this->data['resources'][$kind]); }
    public function state(): string { return $this->data['state']; }
    public function halt(string $operation, string $category): void
    {
        $this->data['state'] = 'indeterminate';
        $this->data['events'][] = ['operation' => $operation, 'state' => $category];
        $this->save();
    }
    public function ready(): void
    {
        if ($this->data['state'] !== 'ready') throw new Refusal('session_requires_supervised_reconciliation');
    }
    public function begin(string $operation): void
    {
        $this->ready();
        $this->data['state'] = 'pending';
        $this->data['events'][] = ['operation' => $operation, 'state' => 'pending'];
        $this->save(); // Persist BEFORE any possible remote side effect.
    }
    public function finish(string $category, ?string $kind = null, ?string $id = null): void
    {
        $this->data['state'] = $category === 'success' ? 'ready' : 'indeterminate';
        $this->data['events'][array_key_last($this->data['events'])]['state'] = $category;
        if ($kind !== null && $id !== null) $this->data['resources'][$kind] = ['id' => $id, 'created_by_session' => $this->id()];
        $this->save(); // Record the created resource BEFORE allowing the next mutation.
    }
    private function save(): void
    {
        $json = json_encode(['data' => $this->data, 'signature' => hash_hmac('sha256', json_encode($this->data, JSON_THROW_ON_ERROR), $this->key)], JSON_THROW_ON_ERROR);
        rewind($this->handle);
        if (!ftruncate($this->handle, 0) || fwrite($this->handle, $json) !== strlen($json) || !fflush($this->handle) || !fsync($this->handle)) {
            throw new Refusal('journal_write_failed');
        }
    }
}
