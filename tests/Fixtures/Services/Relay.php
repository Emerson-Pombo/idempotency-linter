<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

/** Serviço que depende de outro serviço. */
class Relay
{
    public function __construct(private Notifier $notifier) {}

    public function forward(): void
    {
        $this->notifier->send();
    }
}
