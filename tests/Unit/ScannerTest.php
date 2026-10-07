<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit;

use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;
use IdempotencyLinter\Analysis\JobClass;
use IdempotencyLinter\Analysis\JobFinder;
use IdempotencyLinter\Analysis\NullAnalyzer;
use IdempotencyLinter\Report\Finding;
use IdempotencyLinter\Report\RiskLevel;
use IdempotencyLinter\Scanner;
use IdempotencyLinter\Tests\TestCase;

final class ScannerTest extends TestCase
{
    private const JOB = <<<'PHP'
    <?php
    class %s implements \Illuminate\Contracts\Queue\ShouldQueue
    {
        public function handle(): void {}
    }
    PHP;

    public function test_collects_jobs_from_all_files(): void
    {
        $this->fixture('A.php', sprintf(self::JOB, 'A'));
        $this->fixture('B.php', sprintf(self::JOB, 'B'));

        $result = (new Scanner(new JobFinder, new NullAnalyzer))->scan([$this->fixtureDir()]);

        $this->assertSame(2, $result->jobCount());
        $this->assertSame([], $result->findings());
    }

    public function test_records_syntax_errors_and_keeps_scanning(): void
    {
        $broken = $this->fixture('A_broken.php', "<?php\nclass {");
        $this->fixture('B.php', sprintf(self::JOB, 'B'));

        $result = (new Scanner(new JobFinder, new NullAnalyzer))->scan([$this->fixtureDir()]);

        $this->assertSame(1, $result->jobCount());
        $this->assertCount(1, $result->errors());
        $this->assertSame($broken, $result->errors()[0]['file']);
    }

    public function test_adds_findings_returned_by_analyzer(): void
    {
        $file = $this->fixture('A.php', sprintf(self::JOB, 'A'));

        $analyzer = new class implements JobAnalyzer
        {
            public function analyze(JobClass $job): array
            {
                return [new Finding($job->file, $job->line, $job->className, 'mail', RiskLevel::Medium, 'x')];
            }
        };

        $result = (new Scanner(new JobFinder, $analyzer))->scan([$file]);

        $this->assertCount(1, $result->findings());
        $this->assertSame(1, $result->riskyJobCount());
        $this->assertSame(0, $result->protectedJobCount());
    }
}
