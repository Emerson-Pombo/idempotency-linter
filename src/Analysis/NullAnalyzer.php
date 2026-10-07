<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis;

use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;

/**
 * Placeholder from before the sink/guard engine existed: it reports nothing.
 * It was replaced in the ServiceProvider binding.
 */
final class NullAnalyzer implements JobAnalyzer
{
    public function analyze(JobClass $job): array
    {
        return [];
    }
}
