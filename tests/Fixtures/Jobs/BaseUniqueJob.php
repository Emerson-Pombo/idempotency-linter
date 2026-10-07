<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;

/** Base que já traz ShouldBeUnique (via BaseQueuedJob vem ShouldQueue). */
abstract class BaseUniqueJob extends BaseQueuedJob implements ShouldBeUnique
{
}
