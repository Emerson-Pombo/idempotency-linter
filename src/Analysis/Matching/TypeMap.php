<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use IdempotencyLinter\Analysis\JobClass;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Tipos declarados do job: propriedades (incluindo promovidas no construtor)
 * e parâmetros do método de entrada. Sem inferência: o que não tem tipo
 * declarado simples é desconhecido.
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

    public static function forJob(JobClass $job): self
    {
        $properties = [];

        foreach ($job->node->getProperties() as $property) {
            $type = self::className($property->type);

            if ($type === null) {
                continue;
            }

            foreach ($property->props as $prop) {
                $properties[$prop->name->toString()] = $type;
            }
        }

        foreach ($job->node->getMethod('__construct')->params ?? [] as $param) {
            $type = self::className($param->type);

            if ($param->flags !== 0 && $type !== null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $properties[$param->var->name] = $type;
            }
        }

        $variables = $job->entryMethod !== null ? self::parameterTypes($job->entryMethod) : [];

        return new self($properties, $variables);
    }

    /** Mesmas propriedades, mas com as variáveis (parâmetros) de outro método. */
    public function forMethod(ClassMethod $method): self
    {
        return new self($this->properties, self::parameterTypes($method));
    }

    public function property(string $name): ?string
    {
        return $this->properties[$name] ?? null;
    }

    public function variable(string $name): ?string
    {
        return $this->variables[$name] ?? null;
    }

    /** @return array<string, string> */
    private static function parameterTypes(ClassMethod $method): array
    {
        $variables = [];

        foreach ($method->params as $param) {
            $type = self::className($param->type);

            if ($type !== null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $variables[$param->var->name] = $type;
            }
        }

        return $variables;
    }

    private static function className(Identifier|Name|ComplexType|null $type): ?string
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        return $type instanceof Name ? ltrim($type->toString(), '\\') : null;
    }
}
