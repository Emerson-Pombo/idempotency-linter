<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Job base in another file, resolved through the autoloader. */
abstract class BaseQueuedJob implements ShouldQueue {}
