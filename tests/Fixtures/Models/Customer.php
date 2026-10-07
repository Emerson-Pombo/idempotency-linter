<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Models;

use Illuminate\Notifications\Notifiable;

/** Uses the Notifiable trait, to test trait resolution. */
class Customer
{
    use Notifiable;
}
