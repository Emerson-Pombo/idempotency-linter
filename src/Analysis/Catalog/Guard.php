<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Catalog;

/** Protection against re-execution. When "partial", it only lowers the risk. */
final class Guard
{
    /** @param list<MatchRule> $rules */
    public function __construct(
        public readonly string $name,
        public readonly bool $partial,
        public readonly array $rules,
    ) {}
}
