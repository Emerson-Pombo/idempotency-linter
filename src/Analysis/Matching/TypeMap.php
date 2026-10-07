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
 * Tipos declarados conhecidos num ponto do código: propriedades da classe
 * (incluindo promovidas no construtor e herdadas de pai ou trait) e parâmetros
 * do método em execução. Sem inferência: o que não tem tipo declarado simples é
 * desconhecido.
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

    /** Nome completo da classe de um tipo simples (ou anulável); null para os demais. */
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
