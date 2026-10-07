<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;

/** Base that already brings ShouldBeUnique (ShouldQueue comes via BaseQueuedJob). */
abstract class BaseUniqueJob extends BaseQueuedJob implements ShouldBeUnique {}
