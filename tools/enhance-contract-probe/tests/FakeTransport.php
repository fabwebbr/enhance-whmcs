<?php
namespace EnhanceProbe\Tests;
if (!defined('PROBE_TEST_ISOLATED')) throw new \RuntimeException('isolated_runner_required');
final class FakeTransport implements \EnhanceProbe\Transport
{
    public array $queue = [];
    public array $requests = [];
    public function request(array $target, string $token, string $method, string $path, array $body): \EnhanceProbe\Response
    {
        $this->requests[] = compact('target', 'token', 'method', 'path', 'body');
        if (!$this->queue) throw new \RuntimeException('Unexpected fake call');
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) throw $next;
        return $next;
    }
}
