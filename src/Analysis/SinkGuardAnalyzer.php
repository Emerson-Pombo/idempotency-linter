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
use IdempotencyLinter\Analysis\Matching\ClassLocator;
use IdempotencyLinter\Analysis\Matching\ClassMatcher;
use IdempotencyLinter\Analysis\Matching\MethodRef;
use IdempotencyLinter\Analysis\Matching\ReceiverTypes;
use IdempotencyLinter\Analysis\Matching\RuleMatcher;
use IdempotencyLinter\Report\Finding;

/**
 * Reports catalog sinks that have no earlier guard in the job's entry method,
 * following the project's own code (own methods, injected services, parent
 * classes and traits) from there.
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
        private readonly ClassLocator $locator = new ClassLocator,
    ) {
        $this->matcher = $matcher ?? new RuleMatcher($classes);
        $this->collector = $collector ?? new CallCollector(new ReceiverTypes($catalog->chains, $classes), $locator);
    }

    /** @return list<Finding> */
    public function analyze(JobClass $job): array
    {
        $context = $this->locator->contextFor($job);
        $entry = $job->entryMethod !== null
            ? new MethodRef($job->entryMethod, $job->file, $context)
            : $context->findMethod($job->entryName);

        if ($entry === null || $this->guardOfJob($job, partial: false) !== null) {
            return [];
        }

        $calls = $this->collector->collect($entry, $context, $this->isLeaf(...));
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

            // A method followed from several call sites produces a single finding.
            $key = $call->file.'|'.$call->line.'|'.$sink->name;

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

                $message .= ' Partial protection detected, which does not cover retries or redelivery: the risk was lowered.';
            }

            $reported[$key] = true;

            $findings[] = new Finding(
                file: $call->file,
                line: $call->line,
                jobClass: $job->className,
                sink: $sink->name,
                risk: $risk,
                message: $message,
            );
        }

        return $findings;
    }

    /** Catalog sinks and guards are treated as leaves: their inner code is not followed. */
    private function isLeaf(Call $call): bool
    {
        if ($this->sinkFor($call) !== null) {
            return true;
        }

        foreach ($this->catalog->guards as $guard) {
            if ($this->matchesAny($guard->rules, $call)) {
                return true;
            }
        }

        return false;
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

    /** "interface" guard: applies to the whole job, not to a single call. */
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
     * A guard only protects what comes after it in execution order. Literal
     * arrays (idempotency key) also protect the call that receives them as an
     * argument, which starts before them.
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
