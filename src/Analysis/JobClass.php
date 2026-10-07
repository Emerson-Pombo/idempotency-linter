<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Uma classe de job de fila encontrada no código, já com nomes resolvidos.
 */
final class JobClass
{
    /**
     * @param  list<string>  $interfaces  FQCNs das interfaces declaradas na classe e em ancestrais do mesmo arquivo
     */
    public function __construct(
        public readonly string $file,
        public readonly string $className,
        public readonly int $line,
        public readonly array $interfaces,
        public readonly Class_ $node,
        public readonly ?ClassMethod $entryMethod,
        public readonly ?string $parent = null,
    ) {}

    public function implements(string $interface): bool
    {
        return in_array(ltrim($interface, '\\'), $this->interfaces, true);
    }
}
