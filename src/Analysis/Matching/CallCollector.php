<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;

/**
 * Collects, in execution order, the calls of the entry method.
 *
 * Calls to project methods are followed: the method body is inserted at the call
 * site, up to MAX_DEPTH levels and without repeating a method that is already on
 * the stack. It follows `$this->m()`, `self::m()`, `static::m()`, `parent::m()`,
 * `$service->m()` (declared concrete type) and `ProjectClass::m()`. Calls the
 * caller considers "leaves" (catalog sinks and guards) are not expanded.
 */
final class CallCollector
{
    public const MAX_DEPTH = 5;

    public function __construct(
        private readonly ReceiverTypes $receivers = new ReceiverTypes,
        private readonly ClassLocator $locator = new ClassLocator,
    ) {}

    /**
     * @param  ?Closure(Call): bool  $isLeaf
     * @return list<Call>
     */
    public function collect(MethodRef $entry, ClassContext $context, ?Closure $isLeaf = null): array
    {
        $slots = [];
        $order = 0;
        $frame = new Frame($entry, $context, TypeMap::forContext($context, $entry->method), [$entry->key()]);

        $this->walkMethod($frame, $slots, $order, $isLeaf);

        return array_values(array_filter($slots));
    }

    /**
     * @param  array<int, ?Call>  $slots
     * @param  ?Closure(Call): bool  $isLeaf
     */
    private function walkMethod(Frame $frame, array &$slots, int &$order, ?Closure $isLeaf): void
    {
        foreach ($frame->method->method->stmts ?? [] as $statement) {
            $this->walk($statement, $frame, $slots, $order, $isLeaf);
        }
    }

    /**
     * @param  array<int, ?Call>  $slots
     * @param  ?Closure(Call): bool  $isLeaf
     */
    private function walk(Node $node, Frame $frame, array &$slots, int &$order, ?Closure $isLeaf): void
    {
        $call = $this->callFor($node, $frame);
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
                    $this->walk($item, $frame, $slots, $order, $isLeaf);
                }
            }
        }

        $next = $this->followed($node, $frame, $call, $isLeaf);

        if ($next !== null) {
            $this->walkMethod($next, $slots, $order, $isLeaf);
        }

        if ($call !== null) {
            $slots[$slot] = $call->at($first, $order - 1);
        }
    }

    private function callFor(Node $node, Frame $frame): ?Call
    {
        $file = $frame->method->file;

        return match (true) {
            $node instanceof StaticCall => $this->staticCall($node, $file),
            $node instanceof FuncCall => $this->functionCall($node, $file),
            $node instanceof MethodCall => $this->methodCall($node, $frame),
            $node instanceof Array_ => $this->arrayLiteral($node, $file),
            default => null,
        };
    }

    /** The project method this call runs, already with its own context. */
    private function followed(Node $node, Frame $frame, ?Call $call, ?Closure $isLeaf): ?Frame
    {
        if (count($frame->stack) > self::MAX_DEPTH || ($call !== null && $isLeaf !== null && $isLeaf($call))) {
            return null;
        }

        $target = match (true) {
            $node instanceof MethodCall && $node->name instanceof Node\Identifier => $this->methodCallTarget($node, $frame),
            $node instanceof StaticCall && $node->name instanceof Node\Identifier && $node->class instanceof Node\Name => $this->staticCallTarget($node, $frame),
            default => null,
        };

        if ($target === null || in_array($target[0]->key(), $frame->stack, true)) {
            return null;
        }

        [$method, $context] = $target;

        return new Frame($method, $context, TypeMap::forContext($context, $method->method), [...$frame->stack, $method->key()]);
    }

    /** @return ?array{0: MethodRef, 1: ClassContext} */
    private function methodCallTarget(MethodCall $node, Frame $frame): ?array
    {
        if ($node->var instanceof Variable && $node->var->name === 'this') {
            return $this->lookup($frame->context, $node->name->toString());
        }

        $type = $this->receivers->typeOf($node->var, $frame->types);
        $context = $type !== null ? $this->locator->locate($type, $frame->method->file) : null;

        return $context !== null && $context->node instanceof Class_ ? $this->lookup($context, $node->name->toString()) : null;
    }

    /** @return ?array{0: MethodRef, 1: ClassContext} */
    private function staticCallTarget(StaticCall $node, Frame $frame): ?array
    {
        $class = $node->class->toString();
        $name = $node->name->toString();

        return match (strtolower($class)) {
            'self', 'static' => $this->lookup($frame->context, $name),
            'parent' => ($parent = $frame->method->declaring->parent()) === null
                ? null
                : $this->lookupFrom($parent, $frame->context, $name),
            default => $this->lookupClass($class, $name, $frame),
        };
    }

    /** @return ?array{0: MethodRef, 1: ClassContext} */
    private function lookupClass(string $class, string $name, Frame $frame): ?array
    {
        $context = $this->locator->locate($class, $frame->method->file);

        return $context !== null && $context->node instanceof Class_ ? $this->lookup($context, $name) : null;
    }

    /** @return ?array{0: MethodRef, 1: ClassContext} */
    private function lookup(ClassContext $context, string $name): ?array
    {
        return $this->lookupFrom($context, $context, $name);
    }

    /**
     * Procura a partir de $from, mas executa no contexto $context (o `$this`).
     *
     * @return ?array{0: MethodRef, 1: ClassContext}
     */
    private function lookupFrom(ClassContext $from, ClassContext $context, string $name): ?array
    {
        $method = $from->findMethod($name);

        return $method === null ? null : [$method, $context];
    }

    private function staticCall(StaticCall $node, string $file): ?Call
    {
        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        return new Call(CallKind::StaticCall, $node->class->toString(), $node->name->toString(), [], $file, $node->getStartLine());
    }

    private function functionCall(FuncCall $node, string $file): ?Call
    {
        if (! $node->name instanceof Node\Name) {
            return null;
        }

        return new Call(CallKind::Function, null, ltrim($node->name->toString(), '\\'), [], $file, $node->getStartLine());
    }

    private function methodCall(MethodCall $node, Frame $frame): ?Call
    {
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }

        $type = $this->receivers->typeOf($node->var, $frame->types);

        if ($type === null) {
            return null;
        }

        return new Call(CallKind::Method, $type, $node->name->toString(), [], $frame->method->file, $node->getStartLine());
    }

    private function arrayLiteral(Array_ $node, string $file): ?Call
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

        return new Call(CallKind::ArrayLiteral, null, null, $keys, $file, $node->getStartLine());
    }
}
