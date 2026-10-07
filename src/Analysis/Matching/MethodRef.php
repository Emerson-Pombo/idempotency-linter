<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use PhpParser\Node\Stmt\ClassMethod;

/**
 * A method found in the project's code, with the file it lives in and the class
 * (or trait) that declares it.
 */
final class MethodRef
{
    public function __construct(
        public readonly ClassMethod $method,
        public readonly string $file,
        public readonly ClassContext $declaring,
    ) {}

    /** Identifies the method, to cut recursion. */
    public function key(): string
    {
        return strtolower($this->declaring->name().'::'.$this->method->name->toString());
    }
}
