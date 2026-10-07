<?php

declare(strict_types=1);

namespace IdempotencyLinter\Report;

use IdempotencyLinter\Analysis\JobClass;

final class ScanResult
{
    /** @var list<JobClass> */
    private array $jobs = [];

    /** @var list<Finding> */
    private array $findings = [];

    /** @var list<array{file: string, error: string}> */
    private array $errors = [];

    public function addJob(JobClass $job): void
    {
        $this->jobs[] = $job;
    }

    public function addFinding(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function addError(string $file, string $error): void
    {
        $this->errors[] = ['file' => $file, 'error' => $error];
    }

    /** @return list<JobClass> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    /**
     * Findings ordenados por risco (maior primeiro), depois arquivo e linha.
     *
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = $this->findings;

        usort($findings, fn (Finding $a, Finding $b) => [$b->risk->weight(), $a->file, $a->line]
            <=> [$a->risk->weight(), $b->file, $b->line]);

        return $findings;
    }

    /** @return list<array{file: string, error: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function jobCount(): int
    {
        return count($this->jobs);
    }

    public function riskyJobCount(): int
    {
        return count(array_unique(array_map(fn (Finding $f) => $f->jobClass, $this->findings)));
    }

    public function protectedJobCount(): int
    {
        return $this->jobCount() - $this->riskyJobCount();
    }

    public function hasFindingsAtOrAbove(RiskLevel $threshold): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->risk->weight() >= $threshold->weight()) {
                return true;
            }
        }

        return false;
    }
}
