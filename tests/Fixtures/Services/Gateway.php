<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Support\Facades\Mail;

/** Classe do projeto que os testes cadastram como sink (folha). */
class Gateway
{
    public function charge(): void
    {
        Mail::send($mailable);
    }
}
