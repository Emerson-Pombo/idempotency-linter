<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Support\Facades\Mail;

trait SendsMail
{
    private Notifier $traitNotifier;

    protected function sendMailFromTrait(): void
    {
        Mail::send($mailable);
    }
}
