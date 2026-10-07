<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Contracts;

use IdempotencyLinter\Analysis\JobClass;
use IdempotencyLinter\Report\Finding;

/**
 * Recebe um job e devolve os efeitos colaterais desprotegidos encontrados.
 */
interface JobAnalyzer
{
    /** @return list<Finding> */
    public function analyze(JobClass $job): array;
}
