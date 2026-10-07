<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * A queue job class found in the code, with names already resolved.
 */
final class JobClass
{
    /**
     * @param  list<string>  $interfaces  FQCNs of the interfaces declared on the class and on ancestors in the same file
     */
    public function __construct(
        public readonly string $file,
        public readonly string $className,
        public readonly int $line,
        public readonly array $interfaces,
        public readonly Class_ $node,
        public readonly ?ClassMethod $entryMethod,
        public readonly ?string $parent = null,
        public readonly string $entryName = 'handle',
    ) {}

    public function implements(string $interface): bool
    {
        return in_array(ltrim($interface, '\\'), $this->interfaces, true);
    }
}
