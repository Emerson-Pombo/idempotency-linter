<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

/**
 * A call (or array literal) found in the job's execution flow.
 *
 * "order" is the call's position in execution order (pre-order visit, with the
 * body of followed methods inserted at the call site). "lastOrder" is the order
 * of the last node inside it, which tells whether another call is among its
 * arguments.
 */
final class Call
{
    /**
     * @param  ?string  $class  class of the static call, or the receiver's type
     * @param  list<string>  $keys  literal string keys (arrays only)
     */
    public function __construct(
        public readonly CallKind $kind,
        public readonly ?string $class,
        public readonly ?string $name,
        public readonly array $keys,
        public readonly string $file,
        public readonly int $line,
        public readonly int $order = 0,
        public readonly int $lastOrder = 0,
    ) {}

    public function at(int $order, int $lastOrder): self
    {
        return new self($this->kind, $this->class, $this->name, $this->keys, $this->file, $this->line, $order, $lastOrder);
    }
}
