<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use Throwable;

/**
 * Decide se uma classe encontrada no código corresponde a uma classe, interface
 * ou trait do catálogo.
 *
 * Nomes iguais casam direto. Para subclasses, interfaces e traits o matcher usa
 * o autoload do projeto analisado (`is_a`, `class_uses`), sem instanciar nada e
 * sem `include` manual. Classe que não carrega simplesmente não casa.
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

    /** @return list<string> traits da classe, incluindo as usadas por outras traits */
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
