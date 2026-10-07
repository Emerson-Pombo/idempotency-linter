<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Catalog;

use IdempotencyLinter\Report\RiskLevel;
use InvalidArgumentException;

/**
 * Catálogos de sinks e guards, lidos de config/idempotency-linter.php.
 */
final class Catalog
{
    /**
     * @param list<Sink> $sinks
     * @param list<Guard> $guards
     * @param list<Chain> $chains
     */
    public function __construct(
        public readonly array $sinks,
        public readonly array $guards,
        public readonly array $chains = [],
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $sinks = [];

        foreach ($config['sinks'] ?? [] as $name => $sink) {
            $risk = RiskLevel::tryFrom((string) ($sink['risk'] ?? ''))
                ?? throw new InvalidArgumentException("Risco inválido no sink '{$name}': ".($sink['risk'] ?? '(vazio)'));

            $sinks[] = new Sink(
                name: (string) $name,
                risk: $risk,
                message: (string) ($sink['message'] ?? ''),
                rules: array_map(MatchRule::fromArray(...), $sink['match'] ?? []),
            );
        }

        $guards = [];

        foreach ($config['guards'] ?? [] as $name => $guard) {
            $guards[] = new Guard(
                name: (string) $name,
                partial: (bool) ($guard['partial'] ?? false),
                rules: array_map(MatchRule::fromArray(...), $guard['match'] ?? []),
            );
        }

        $chains = array_map(Chain::fromArray(...), $config['chains'] ?? []);

        return new self($sinks, $guards, $chains);
    }
}
