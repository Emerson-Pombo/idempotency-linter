<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Catalog;

use IdempotencyLinter\Report\RiskLevel;

/** A call with a side effect that is sensitive to duplication. */
final class Sink
{
    /** @param list<MatchRule> $rules */
    public function __construct(
        public readonly string $name,
        public readonly RiskLevel $risk,
        public readonly string $message,
        public readonly array $rules,
    ) {}
}
