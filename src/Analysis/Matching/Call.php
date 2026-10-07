<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

/**
 * Uma chamada (ou array literal) encontrada no corpo do método de entrada.
 */
final class Call
{
    /**
     * @param ?string $class  classe da chamada estática ou tipo do receptor
     * @param list<string> $keys  chaves string literais (só para arrays)
     */
    public function __construct(
        public readonly CallKind $kind,
        public readonly ?string $class,
        public readonly ?string $name,
        public readonly array $keys,
        public readonly int $startPos,
        public readonly int $endPos,
        public readonly int $line,
    ) {
    }
}
