<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

/**
 * Um método em execução durante a coleta: de onde ele vem, em que classe concreta
 * `$this` aponta, os tipos conhecidos ali e a pilha de métodos já em execução.
 */
final class Frame
{
    /** @param list<string> $stack chaves ({@see MethodRef::key()}) dos métodos em execução */
    public function __construct(
        public readonly MethodRef $method,
        public readonly ClassContext $context,
        public readonly TypeMap $types,
        public readonly array $stack,
    ) {}
}
