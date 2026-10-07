<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Coleta, em ordem de aparição, as chamadas do corpo de um método.
 */
final class CallCollector
{
    /** @return list<Call> */
    public function collect(ClassMethod $method): array
    {
        $nodes = (new NodeFinder())->find(
            $method->stmts ?? [],
            fn (Node $node) => $node instanceof StaticCall || $node instanceof FuncCall,
        );

        $calls = [];

        foreach ($nodes as $node) {
            $call = match (true) {
                $node instanceof StaticCall => $this->staticCall($node),
                $node instanceof FuncCall => $this->functionCall($node),
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
}
