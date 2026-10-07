<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Support\Facades\Mail;

class Helper
{
    public static function notify(): void
    {
        Mail::send($mailable);
    }
}
