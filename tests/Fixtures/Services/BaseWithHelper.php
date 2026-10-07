<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/** Base com helper protegido, usado por jobs filhos via $this->mailFromParent(). */
abstract class BaseWithHelper implements ShouldQueue
{
    protected function mailFromParent(): void
    {
        Mail::send($mailable);
    }
}
