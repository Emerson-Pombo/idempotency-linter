<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

/**
 * A method being executed during collection: where it comes from, which concrete
 * class `$this` points to, the types known there and the stack of methods
 * already running.
 */
final class Frame
{
    /** @param list<string> $stack keys ({@see MethodRef::key()}) of the running methods */
    public function __construct(
        public readonly MethodRef $method,
        public readonly ClassContext $context,
        public readonly TypeMap $types,
        public readonly array $stack,
    ) {}
}
