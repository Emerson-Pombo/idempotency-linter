<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

/**
 * Decide se uma classe encontrada no código corresponde a uma classe do catálogo.
 */
final class ClassMatcher
{
    public function matches(string $actual, string $expected): bool
    {
        return strcasecmp(ltrim($actual, '\\'), ltrim($expected, '\\')) === 0;
    }
}
