<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

/**
 * Uma chamada (ou array literal) encontrada no fluxo de execução do job.
 *
 * "order" é a posição da chamada na ordem de execução (visita em pré-ordem, com
 * o corpo dos métodos seguidos inserido no ponto da chamada). "lastOrder" é a
 * ordem do último nó dentro dela, o que permite saber se outra chamada está nos
 * seus argumentos.
 */
final class Call
{
    /**
     * @param  ?string  $class  classe da chamada estática ou tipo do receptor
     * @param  list<string>  $keys  chaves string literais (só para arrays)
     */
    public function __construct(
        public readonly CallKind $kind,
        public readonly ?string $class,
        public readonly ?string $name,
        public readonly array $keys,
        public readonly int $line,
        public readonly int $order = 0,
        public readonly int $lastOrder = 0,
    ) {}

    public function at(int $order, int $lastOrder): self
    {
        return new self($this->kind, $this->class, $this->name, $this->keys, $this->line, $order, $lastOrder);
    }
}
