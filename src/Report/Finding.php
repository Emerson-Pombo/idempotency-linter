<?php

declare(strict_types=1);

namespace IdempotencyLinter\Report;

/**
 * An unprotected side effect found inside a job.
 */
final class Finding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $jobClass,
        public readonly string $sink,
        public readonly RiskLevel $risk,
        public readonly string $message,
    ) {}
}
