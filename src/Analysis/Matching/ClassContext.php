<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;

/**
 * A project class (or trait), already read by the parser, with its hierarchy
 * (traits and parent class) resolved on demand by the {@see ClassLocator}.
 */
final class ClassContext
{
    public function __construct(
        public readonly Class_|Trait_ $node,
        public readonly string $file,
        private readonly ClassLocator $locator,
    ) {}

    public function name(): string
    {
        return $this->node->namespacedName?->toString() ?? $this->node->name?->toString() ?? '';
    }

    public function parent(): ?self
    {
        if (! $this->node instanceof Class_ || $this->node->extends === null) {
            return null;
        }

        return $this->locator->locate($this->node->extends->toString(), $this->file);
    }

    /** @return list<self> project traits used directly by this class */
    public function traits(): array
    {
        $traits = [];

        foreach ($this->node->getTraitUses() as $use) {
            foreach ($use->traits as $name) {
                $trait = $this->locator->locate($name->toString(), $this->file);

                if ($trait !== null) {
                    $traits[] = $trait;
                }
            }
        }

        return $traits;
    }

    /**
     * Looks for the method on the class, then on its traits and finally up the parent chain.
     *
     * @param  array<string, true>  $seen
     */
    public function findMethod(string $name, array $seen = []): ?MethodRef
    {
        $key = strtolower($this->file.'|'.$this->name());

        if (isset($seen[$key])) {
            return null;
        }

        $seen[$key] = true;
        $method = $this->node->getMethod($name);

        if ($method !== null) {
            return new MethodRef($method, $this->file, $this);
        }

        foreach ($this->traits() as $trait) {
            $found = $trait->findMethod($name, $seen);

            if ($found !== null) {
                return $found;
            }
        }

        return $this->parent()?->findMethod($name, $seen);
    }

    /**
     * Declared property types, including those promoted in the constructor and
     * those coming from traits and parent classes. The class itself takes precedence.
     *
     * @param  array<string, true>  $seen
     * @return array<string, string>
     */
    public function properties(array $seen = []): array
    {
        $key = strtolower($this->file.'|'.$this->name());

        if (isset($seen[$key])) {
            return [];
        }

        $seen[$key] = true;
        $inherited = $this->parent()?->properties($seen) ?? [];

        foreach ($this->traits() as $trait) {
            $inherited = [...$inherited, ...$trait->properties($seen)];
        }

        $own = [];

        foreach ($this->node->getProperties() as $property) {
            $type = TypeMap::typeName($property->type);

            foreach ($property->props as $prop) {
                if ($type !== null) {
                    $own[$prop->name->toString()] = $type;
                }
            }
        }

        foreach ($this->node->getMethod('__construct')->params ?? [] as $param) {
            $type = TypeMap::typeName($param->type);

            if ($param->flags !== 0 && $type !== null && is_string($param->var->name ?? null)) {
                $own[$param->var->name] = $type;
            }
        }

        return [...$inherited, ...$own];
    }
}
