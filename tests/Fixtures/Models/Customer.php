<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Models;

use Illuminate\Notifications\Notifiable;

/** Usa a trait Notifiable, usada para testar a resolução de traits. */
class Customer
{
    use Notifiable;
}
