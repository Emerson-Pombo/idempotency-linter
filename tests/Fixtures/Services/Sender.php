<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

interface Sender
{
    public function send(): void;
}
