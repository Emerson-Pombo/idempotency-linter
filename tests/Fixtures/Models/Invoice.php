<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** Subclass of Model, used to test class hierarchy resolution. */
class Invoice extends Model {}
