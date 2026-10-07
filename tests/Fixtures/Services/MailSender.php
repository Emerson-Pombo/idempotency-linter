<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Support\Facades\Mail;

class MailSender implements Sender
{
    public function send(): void
    {
        Mail::send($mailable);
    }
}
