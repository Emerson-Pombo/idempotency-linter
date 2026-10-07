<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use IdempotencyLinter\Analysis\Catalog\MatchRule;

/**
 * Casa uma regra do catálogo com uma chamada coletada.
 */
final class RuleMatcher
{
    public function __construct(private readonly ClassMatcher $classes = new ClassMatcher())
    {
    }

    public function matches(MatchRule $rule, Call $call): bool
    {
        return match ($rule->type) {
            'static_call' => $call->kind === CallKind::StaticCall
                && $this->classMatches($call, $rule)
                && $this->nameIn($call, $rule->methods),
            'function' => $call->kind === CallKind::Function && $this->nameIn($call, $rule->methods),
            default => false,
        };
    }

    private function classMatches(Call $call, MatchRule $rule): bool
    {
        return $call->class !== null
            && $rule->class !== null
            && $this->classes->matches($call->class, $rule->class);
    }

    /** @param list<string> $names */
    private function nameIn(Call $call, array $names): bool
    {
        return $call->name !== null
            && in_array(strtolower($call->name), array_map('strtolower', $names), true);
    }
}
