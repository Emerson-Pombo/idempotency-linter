<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis;

use IdempotencyLinter\Analysis\Catalog\Catalog;
use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;
use IdempotencyLinter\Analysis\Matching\CallCollector;
use IdempotencyLinter\Analysis\Matching\RuleMatcher;
use IdempotencyLinter\Report\Finding;

/**
 * Reporta sinks do catálogo no método de entrada do job.
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

        $findings = [];

        foreach ($this->collector->collect($job->entryMethod) as $call) {
            foreach ($this->catalog->sinks as $sink) {
                foreach ($sink->rules as $rule) {
                    if ($this->matcher->matches($rule, $call)) {
                        $findings[] = new Finding(
                            file: $job->file,
                            line: $call->line,
                            jobClass: $job->className,
                            sink: $sink->name,
                            risk: $sink->risk,
                            message: $sink->message,
                        );

                        continue 2;
                    }
                }
            }
        }

        return $findings;
    }
}
