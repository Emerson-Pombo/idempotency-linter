<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Services;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Base with a typed property inherited by its children. */
abstract class BaseWithNotifier implements ShouldQueue
{
    public function __construct(protected Notifier $notifier) {}
}
