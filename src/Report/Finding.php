<?php

declare(strict_types=1);

namespace IdempotencyLinter\Report;

/**
 * Um efeito colateral desprotegido encontrado dentro de um job.
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
    ) {
    }
}
