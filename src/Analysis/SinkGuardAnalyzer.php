<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis;

use IdempotencyLinter\Analysis\Catalog\Catalog;
use IdempotencyLinter\Analysis\Catalog\MatchRule;
use IdempotencyLinter\Analysis\Catalog\Sink;
use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;
use IdempotencyLinter\Analysis\Matching\Call;
use IdempotencyLinter\Analysis\Matching\CallCollector;
use IdempotencyLinter\Analysis\Matching\CallKind;
use IdempotencyLinter\Analysis\Matching\RuleMatcher;
use IdempotencyLinter\Report\Finding;

/**
 * Reporta sinks do catálogo sem guard anterior no método de entrada do job.
 *
 * Limitação (v1): só analisa o corpo do método de entrada, sem seguir métodos
 * privados nem serviços injetados.
 */
final class SinkGuardAnalyzer implements JobAnalyzer
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly CallCollector $collector = new CallCollector(),
        private readonly RuleMatcher $matcher = new RuleMatcher(),
    ) {
    }

    /** @return list<Finding> */
    public function analyze(JobClass $job): array
    {
        if ($job->entryMethod === null) {
            return [];
        }

        $calls = $this->collector->collect($job->entryMethod);
        $guardCalls = array_values(array_filter($calls, $this->isGuard(...)));

        $findings = [];

        foreach ($calls as $call) {
            $sink = $this->sinkFor($call);

            if ($sink === null || $this->isProtected($call, $guardCalls)) {
                continue;
            }

            $findings[] = new Finding(
                file: $job->file,
                line: $call->line,
                jobClass: $job->className,
                sink: $sink->name,
                risk: $sink->risk,
                message: $sink->message,
            );
        }

        return $findings;
    }

    private function sinkFor(Call $call): ?Sink
    {
        foreach ($this->catalog->sinks as $sink) {
            if ($this->matchesAny($sink->rules, $call)) {
                return $sink;
            }
        }

        return null;
    }

    private function isGuard(Call $call): bool
    {
        foreach ($this->catalog->guards as $guard) {
            if (! $guard->partial && $this->matchesAny($guard->rules, $call)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Um guard só protege o que aparece depois dele no código-fonte. Arrays
     * literais (chave de idempotência) também protegem a chamada que os recebe
     * como argumento, que começa antes deles.
     *
     * @param list<Call> $guardCalls
     */
    private function isProtected(Call $sink, array $guardCalls): bool
    {
        foreach ($guardCalls as $guard) {
            $limit = $guard->kind === CallKind::ArrayLiteral ? $sink->endPos : $sink->startPos;

            if ($guard->startPos < $limit) {
                return true;
            }
        }

        return false;
    }

    /** @param list<MatchRule> $rules */
    private function matchesAny(array $rules, Call $call): bool
    {
        foreach ($rules as $rule) {
            if ($this->matcher->matches($rule, $call)) {
                return true;
            }
        }

        return false;
    }
}
