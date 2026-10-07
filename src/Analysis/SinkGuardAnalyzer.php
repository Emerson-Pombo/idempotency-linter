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
use IdempotencyLinter\Analysis\Matching\ClassMatcher;
use IdempotencyLinter\Analysis\Matching\ReceiverTypes;
use IdempotencyLinter\Analysis\Matching\RuleMatcher;
use IdempotencyLinter\Analysis\Matching\TypeMap;
use IdempotencyLinter\Report\Finding;

/**
 * Reporta sinks do catálogo sem guard anterior no método de entrada do job.
 *
 * Limitação (v1): só analisa o corpo do método de entrada, sem seguir métodos
 * privados nem serviços injetados.
 */
final class SinkGuardAnalyzer implements JobAnalyzer
{
    private readonly RuleMatcher $matcher;

    private readonly CallCollector $collector;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly ClassMatcher $classes = new ClassMatcher,
        ?RuleMatcher $matcher = null,
        ?CallCollector $collector = null,
    ) {
        $this->matcher = $matcher ?? new RuleMatcher($classes);
        $this->collector = $collector ?? new CallCollector(new ReceiverTypes($catalog->chains, $classes));
    }

    /** @return list<Finding> */
    public function analyze(JobClass $job): array
    {
        if ($job->entryMethod === null || $this->guardOfJob($job, partial: false) !== null) {
            return [];
        }

        $calls = $this->collector->collect($job->entryMethod, TypeMap::forJob($job), $job->node);
        $guardCalls = $this->guardCalls($calls, partial: false);
        $partialCalls = $this->guardCalls($calls, partial: true);
        $jobIsPartiallyProtected = $this->guardOfJob($job, partial: true) !== null;

        $findings = [];
        $reported = [];

        foreach ($calls as $call) {
            $sink = $this->sinkFor($call);

            if ($sink === null || $this->isProtected($call, $guardCalls)) {
                continue;
            }

            // Um método seguido a partir de vários pontos gera um achado só.
            $key = $call->line.'|'.$sink->name;

            if (isset($reported[$key])) {
                continue;
            }

            $risk = $sink->risk;
            $message = $sink->message;
            if ($jobIsPartiallyProtected || $this->isProtected($call, $partialCalls)) {
                $risk = $risk->lower();

                if ($risk === null) {
                    continue;
                }

                $message .= ' Proteção parcial detectada, que não cobre retry nem reentrega: o risco foi reduzido.';
            }

            $reported[$key] = true;

            $findings[] = new Finding(
                file: $job->file,
                line: $call->line,
                jobClass: $job->className,
                sink: $sink->name,
                risk: $risk,
                message: $message,
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

    /**
     * @param  list<Call>  $calls
     * @return list<Call>
     */
    private function guardCalls(array $calls, bool $partial): array
    {
        return array_values(array_filter($calls, function (Call $call) use ($partial): bool {
            foreach ($this->catalog->guards as $guard) {
                if ($guard->partial === $partial && $this->matchesAny($guard->rules, $call)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** Guard do tipo "interface": vale para o job inteiro, não para uma chamada. */
    private function guardOfJob(JobClass $job, bool $partial): ?string
    {
        foreach ($this->catalog->guards as $guard) {
            if ($guard->partial !== $partial) {
                continue;
            }

            foreach ($guard->rules as $rule) {
                if ($rule->type === 'interface' && $rule->class !== null && $this->jobImplements($job, $rule->class)) {
                    return $guard->name;
                }
            }
        }

        return null;
    }

    private function jobImplements(JobClass $job, string $interface): bool
    {
        foreach ($job->interfaces as $implemented) {
            if ($this->classes->matches($implemented, $interface)) {
                return true;
            }
        }

        return $job->parent !== null && $this->classes->matches($job->parent, $interface);
    }

    /**
     * Um guard só protege o que vem depois dele na ordem de execução. Arrays
     * literais (chave de idempotência) também protegem a chamada que os recebe
     * como argumento, que começa antes deles.
     *
     * @param  list<Call>  $guardCalls
     */
    private function isProtected(Call $sink, array $guardCalls): bool
    {
        foreach ($guardCalls as $guard) {
            $limit = $guard->kind === CallKind::ArrayLiteral ? $sink->lastOrder : $sink->order - 1;

            if ($guard->order <= $limit) {
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
