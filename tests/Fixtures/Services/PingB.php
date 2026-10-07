<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

class PingB
{
    public function __construct(private PingA $a) {}

    public function pong(): void
    {
        $this->a->ping();
    }
}
