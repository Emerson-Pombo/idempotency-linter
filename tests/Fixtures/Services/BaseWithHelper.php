<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/** Base with a protected helper, used by child jobs through $this->mailFromParent(). */
abstract class BaseWithHelper implements ShouldQueue
{
    protected function mailFromParent(): void
    {
        Mail::send($mailable);
    }
}
