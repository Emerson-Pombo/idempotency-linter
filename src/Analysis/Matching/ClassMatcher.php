<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use Throwable;

/**
 * Decides whether a class found in the code matches a catalog class, interface
 * or trait.
 *
 * Nomes iguais casam direto. Para subclasses, interfaces e traits o matcher usa
 * the analyzed project's autoloader (`is_a`, `class_uses`), without instantiating
 * anything and without manual `include`. A class that fails to load simply does
 * not match.
 */
final class ClassMatcher
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function matches(string $actual, string $expected): bool
    {
        $actual = ltrim($actual, '\\');
        $expected = ltrim($expected, '\\');

        if (strcasecmp($actual, $expected) === 0) {
            return true;
        }

        return $this->cache[strtolower($actual.'|'.$expected)] ??= $this->inherits($actual, $expected);
    }

    private function inherits(string $actual, string $expected): bool
    {
        try {
            if (! class_exists($actual) && ! interface_exists($actual)) {
                return false;
            }

            return is_a($actual, $expected, true) || $this->usesTrait($actual, $expected);
        } catch (Throwable) {
            return false;
        }
    }

    private function usesTrait(string $class, string $trait): bool
    {
        $classes = [$class, ...array_values(class_parents($class) ?: [])];

        foreach ($classes as $candidate) {
            foreach ($this->traitsOf($candidate) as $used) {
                if (strcasecmp($used, $trait) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> traits of the class, including those used by other traits */
    private function traitsOf(string $class): array
    {
        $traits = [];

        foreach (array_values(class_uses($class) ?: []) as $trait) {
            $traits[] = $trait;
            array_push($traits, ...$this->traitsOf($trait));
        }

        return $traits;
    }
}
