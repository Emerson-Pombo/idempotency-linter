<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Declared types known at a point in the code: the class's properties
 * (including those promoted in the constructor and inherited from a parent or
 * trait) and the parameters of the running method. No inference: anything
 * without a simple declared type is unknown.
 */
final class TypeMap
{
    /**
     * @param  array<string, string>  $properties
     * @param  array<string, string>  $variables
     */
    private function __construct(
        private readonly array $properties,
        private readonly array $variables,
    ) {}

    public static function forContext(ClassContext $context, ?ClassMethod $method): self
    {
        return new self($context->properties(), $method !== null ? self::parameterTypes($method) : []);
    }

    public function property(string $name): ?string
    {
        return $this->properties[$name] ?? null;
    }

    public function variable(string $name): ?string
    {
        return $this->variables[$name] ?? null;
    }

    /** Full class name of a simple (or nullable) type; null for the others. */
    public static function typeName(Identifier|Name|ComplexType|null $type): ?string
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        return $type instanceof Name ? ltrim($type->toString(), '\\') : null;
    }

    /** @return array<string, string> */
    private static function parameterTypes(ClassMethod $method): array
    {
        $variables = [];

        foreach ($method->params as $param) {
            $type = self::typeName($param->type);

            if ($type !== null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $variables[$param->var->name] = $type;
            }
        }

        return $variables;
    }
}
