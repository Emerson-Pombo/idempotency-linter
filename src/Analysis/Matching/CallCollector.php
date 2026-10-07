<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Coleta, em ordem de execução, as chamadas do método de entrada.
 *
 * Chamadas a métodos da própria classe ($this->metodo(), self::metodo(),
 * static::metodo()) são seguidas: o corpo do método é inserido no ponto da
 * chamada, até MAX_DEPTH níveis e sem repetir um método que já está na pilha.
 */
final class CallCollector
{
    public const MAX_DEPTH = 5;

    public function __construct(private readonly ReceiverTypes $receivers = new ReceiverTypes) {}

    /** @return list<Call> */
    public function collect(ClassMethod $method, TypeMap $types, ?Class_ $class = null): array
    {
        $slots = [];
        $order = 0;

        $this->walkMethod($method, $types, $class, [strtolower($method->name->toString())], $slots, $order);

        return array_values(array_filter($slots));
    }

    /**
     * @param  list<string>  $stack  métodos em execução, em minúsculas
     * @param  array<int, ?Call>  $slots
     */
    private function walkMethod(ClassMethod $method, TypeMap $types, ?Class_ $class, array $stack, array &$slots, int &$order): void
    {
        foreach ($method->stmts ?? [] as $statement) {
            $this->walk($statement, $types, $class, $stack, $slots, $order);
        }
    }

    /**
     * @param  list<string>  $stack
     * @param  array<int, ?Call>  $slots
     */
    private function walk(Node $node, TypeMap $types, ?Class_ $class, array $stack, array &$slots, int &$order): void
    {
        $call = $this->callFor($node, $types);
        $slot = 0;
        $first = $order;

        if ($call !== null) {
            $slot = count($slots);
            $slots[] = null;
            $order++;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;

            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node) {
                    $this->walk($item, $types, $class, $stack, $slots, $order);
                }
            }
        }

        $followed = $this->followedMethod($node, $class, $stack);

        if ($followed !== null) {
            $this->walkMethod($followed, $types->forMethod($followed), $class, [...$stack, strtolower($followed->name->toString())], $slots, $order);
        }

        if ($call !== null) {
            $slots[$slot] = $call->at($first, $order - 1);
        }
    }

    private function callFor(Node $node, TypeMap $types): ?Call
    {
        return match (true) {
            $node instanceof StaticCall => $this->staticCall($node),
            $node instanceof FuncCall => $this->functionCall($node),
            $node instanceof MethodCall => $this->methodCall($node, $types),
            $node instanceof Array_ => $this->arrayLiteral($node),
            default => null,
        };
    }

    /**
     * Método da própria classe chamado por $this->m(), self::m() ou static::m().
     *
     * @param  list<string>  $stack
     */
    private function followedMethod(Node $node, ?Class_ $class, array $stack): ?ClassMethod
    {
        if ($class === null || count($stack) > self::MAX_DEPTH) {
            return null;
        }

        $name = match (true) {
            $node instanceof MethodCall && $node->var instanceof Variable && $node->var->name === 'this' => $node->name,
            $node instanceof StaticCall && $node->class instanceof Node\Name
                && in_array(strtolower($node->class->toString()), ['self', 'static'], true) => $node->name,
            default => null,
        };

        if (! $name instanceof Node\Identifier || in_array(strtolower($name->toString()), $stack, true)) {
            return null;
        }

        return $class->getMethod($name->toString());
    }

    private function staticCall(StaticCall $node): ?Call
    {
        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        return new Call(CallKind::StaticCall, $node->class->toString(), $node->name->toString(), [], $node->getStartLine());
    }

    private function functionCall(FuncCall $node): ?Call
    {
        if (! $node->name instanceof Node\Name) {
            return null;
        }

        return new Call(CallKind::Function, null, ltrim($node->name->toString(), '\\'), [], $node->getStartLine());
    }

    private function methodCall(MethodCall $node, TypeMap $types): ?Call
    {
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }

        $type = $this->receivers->typeOf($node->var, $types);

        if ($type === null) {
            return null;
        }

        return new Call(CallKind::Method, $type, $node->name->toString(), [], $node->getStartLine());
    }

    private function arrayLiteral(Array_ $node): ?Call
    {
        $keys = [];

        foreach ($node->items as $item) {
            if ($item->key instanceof Node\Scalar\String_) {
                $keys[] = $item->key->value;
            }
        }

        if ($keys === []) {
            return null;
        }

        return new Call(CallKind::ArrayLiteral, null, null, $keys, $node->getStartLine());
    }
}
