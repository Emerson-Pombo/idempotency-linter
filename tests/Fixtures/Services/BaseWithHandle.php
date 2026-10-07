<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/** Base que traz o próprio handle(). */
abstract class BaseWithHandle implements ShouldQueue
{
    public function handle(): void
    {
        Mail::send($mailable);
    }
}
