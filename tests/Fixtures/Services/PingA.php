<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Support\Facades\Mail;

/** Half of a circular dependency pair, used to test recursion protection. */
class PingA
{
    public function __construct(private PingB $b) {}

    public function ping(): void
    {
        $this->b->pong();
        Mail::send($mailable);
    }
}
