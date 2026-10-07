<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

/** Service that depends on another service. */
class Relay
{
    public function __construct(private Notifier $notifier) {}

    public function forward(): void
    {
        $this->notifier->send();
    }
}
