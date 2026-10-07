<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Catalog;

/** Proteção contra reexecução. Quando "partial", apenas reduz o risco. */
final class Guard
{
    /** @param list<MatchRule> $rules */
    public function __construct(
        public readonly string $name,
        public readonly bool $partial,
        public readonly array $rules,
    ) {
    }
}
