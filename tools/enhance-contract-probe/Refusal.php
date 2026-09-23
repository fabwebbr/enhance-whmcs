<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Local reason codes only. Never serialize argument-bearing native traces. */
final class Refusal extends \RuntimeException
{
    public function __serialize(): array { return ['reason' => $this->getMessage()]; }
    public function __debugInfo(): array { return $this->__serialize(); }
    public function __toString(): string { return $this->getMessage(); }
}
