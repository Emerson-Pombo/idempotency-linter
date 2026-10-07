<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis;

use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;

/**
 * Placeholder até o motor de sinks/guards existir: não reporta nada.
 * Será substituído no binding do ServiceProvider.
 */
final class NullAnalyzer implements JobAnalyzer
{
    public function analyze(JobClass $job): array
    {
        return [];
    }
}
