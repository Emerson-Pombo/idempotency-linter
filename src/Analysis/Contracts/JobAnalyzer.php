<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Contracts;

use IdempotencyLinter\Analysis\JobClass;
use IdempotencyLinter\Report\Finding;

/**
 * Takes a job and returns the unprotected side effects found in it.
 */
interface JobAnalyzer
{
    /** @return list<Finding> */
    public function analyze(JobClass $job): array;
}
