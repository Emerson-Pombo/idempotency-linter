<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use IdempotencyLinter\Analysis\Catalog\Chain;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;

/**
 * Finds the type of a method call's receiver, without general type inference.
 *
 * It only knows `$this->property` and `$parameter` with a declared type, and
 * chains declared in the catalog ("chains") starting from a static class or from
 * a receiver of a known type. Anything else is unknown (null).
 */
final class ReceiverTypes
{
    /** @param list<Chain> $chains */
    public function __construct(
        private readonly array $chains = [],
        private readonly ClassMatcher $classes = new ClassMatcher,
    ) {}

    public function typeOf(Expr $receiver, TypeMap $types): ?string
    {
        if ($receiver instanceof PropertyFetch
            && $receiver->var instanceof Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Node\Identifier) {
            return $types->property($receiver->name->toString());
        }

        if ($receiver instanceof Variable && is_string($receiver->name)) {
            return $types->variable($receiver->name);
        }

        if ($receiver instanceof StaticCall && $receiver->class instanceof Node\Name && $receiver->name instanceof Node\Identifier) {
            return $this->returnedBy($receiver->class->toString(), $receiver->name->toString());
        }

        if ($receiver instanceof MethodCall && $receiver->name instanceof Node\Identifier) {
            $inner = $this->typeOf($receiver->var, $types);

            return $inner === null ? null : $this->returnedBy($inner, $receiver->name->toString());
        }

        return null;
    }

    private function returnedBy(string $class, string $method): ?string
    {
        foreach ($this->chains as $chain) {
            if ($this->classes->matches($class, $chain->class)
                && in_array(strtolower($method), array_map('strtolower', $chain->methods), true)) {
                return $chain->returns;
            }
        }

        return null;
    }
}
