<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Base de jobs em outro arquivo, resolvida pelo autoload. */
abstract class BaseQueuedJob implements ShouldQueue
{
}
