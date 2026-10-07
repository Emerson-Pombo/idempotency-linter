<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Coleta, em ordem de aparição, as chamadas do corpo de um método.
 */
final class CallCollector
{
    public function __construct(private readonly ReceiverTypes $receivers = new ReceiverTypes())
    {
    }

    /** @return list<Call> */
    public function collect(ClassMethod $method, TypeMap $types): array
    {
        $nodes = (new NodeFinder())->find(
            $method->stmts ?? [],
            fn (Node $node) => $node instanceof StaticCall || $node instanceof FuncCall || $node instanceof MethodCall || $node instanceof Array_,
        );

        $calls = [];

        foreach ($nodes as $node) {
            $call = match (true) {
                $node instanceof StaticCall => $this->staticCall($node),
                $node instanceof FuncCall => $this->functionCall($node),
                $node instanceof MethodCall => $this->methodCall($node, $types),
                $node instanceof Array_ => $this->arrayLiteral($node),
            };

            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    private function staticCall(StaticCall $node): ?Call
    {
        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        return new Call(
            CallKind::StaticCall,
            $node->class->toString(),
            $node->name->toString(),
            [],
            $node->getStartFilePos(),
            $node->getEndFilePos(),
            $node->getStartLine(),
        );
    }

    private function functionCall(FuncCall $node): ?Call
    {
        if (! $node->name instanceof Node\Name) {
            return null;
        }

        return new Call(
            CallKind::Function,
            null,
            ltrim($node->name->toString(), '\\'),
            [],
            $node->getStartFilePos(),
            $node->getEndFilePos(),
            $node->getStartLine(),
        );
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

        return new Call(
            CallKind::Method,
            $type,
            $node->name->toString(),
            [],
            $node->getStartFilePos(),
            $node->getEndFilePos(),
            $node->getStartLine(),
        );
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

        return new Call(
            CallKind::ArrayLiteral,
            null,
            null,
            $keys,
            $node->getStartFilePos(),
            $node->getEndFilePos(),
            $node->getStartLine(),
        );
    }
}
