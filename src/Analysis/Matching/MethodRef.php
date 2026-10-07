<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use PhpParser\Node\Stmt\ClassMethod;

/**
 * Um método encontrado no código do projeto, com o arquivo onde ele está e a
 * classe (ou trait) que o declara.
 */
final class MethodRef
{
    public function __construct(
        public readonly ClassMethod $method,
        public readonly string $file,
        public readonly ClassContext $declaring,
    ) {}

    /** Identifica o método, para cortar recursão. */
    public function key(): string
    {
        return strtolower($this->declaring->name().'::'.$this->method->name->toString());
    }
}
