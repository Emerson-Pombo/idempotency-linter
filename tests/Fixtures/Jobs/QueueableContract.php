<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Interface que estende ShouldQueue. */
interface QueueableContract extends ShouldQueue {}
