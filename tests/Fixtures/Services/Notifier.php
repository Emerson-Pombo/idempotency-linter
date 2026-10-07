<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/** Serviço do projeto com um sink e uma variação protegida. */
class Notifier
{
    public function send(): void
    {
        Mail::send($mailable);
    }

    public function sendGuarded(): void
    {
        Cache::add('sent', true, 60);
        Mail::send($mailable);
    }

    public function claim(): void
    {
        Cache::add('sent', true, 60);
    }

    public function noop(): void {}
}
