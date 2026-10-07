<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Feature;

use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;
use IdempotencyLinter\Analysis\JobClass;
use IdempotencyLinter\Report\Finding;
use IdempotencyLinter\Report\RiskLevel;
use IdempotencyLinter\Tests\TestCase;

final class ScanCommandTest extends TestCase
{
    private function job(): string
    {
        return $this->fixture('Job.php', <<<'PHP'
        <?php
        class Job implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void {}
        }
        PHP);
    }

    private function bindFindingWith(RiskLevel $risk): void
    {
        $this->app->bind(JobAnalyzer::class, fn () => new class($risk) implements JobAnalyzer {
            public function __construct(private readonly RiskLevel $risk) {}

            public function analyze(JobClass $job): array
            {
                return [new Finding($job->file, $job->line, $job->className, 'mail', $this->risk, 'achado')];
            }
        });
    }

    public function test_reports_when_no_jobs_found(): void
    {
        $this->artisan('idempotency:scan', ['paths' => [$this->fixture('x.php', '<?php')]])
            ->expectsOutputToContain('Nenhum job')
            ->assertExitCode(0);
    }

    public function test_succeeds_when_jobs_have_no_findings(): void
    {
        $this->artisan('idempotency:scan', ['paths' => [$this->job()]])
            ->expectsOutputToContain('1 job analisados, 0 com risco, 1 protegidos')
            ->assertExitCode(0);
    }

    public function test_fails_when_finding_reaches_threshold(): void
    {
        $this->bindFindingWith(RiskLevel::Medium);

        $this->artisan('idempotency:scan', ['paths' => [$this->job()], '--fail-on' => 'medium'])
            ->expectsOutputToContain('MÉDIO RISCO')
            ->assertExitCode(1);
    }

    public function test_does_not_fail_when_finding_is_below_threshold(): void
    {
        $this->bindFindingWith(RiskLevel::Low);

        $this->artisan('idempotency:scan', ['paths' => [$this->job()], '--fail-on' => 'high'])
            ->assertExitCode(0);
    }

    public function test_fail_on_none_never_fails(): void
    {
        $this->bindFindingWith(RiskLevel::High);

        $this->artisan('idempotency:scan', ['paths' => [$this->job()], '--fail-on' => 'none'])
            ->assertExitCode(0);
    }

    public function test_rejects_invalid_fail_on(): void
    {
        $this->artisan('idempotency:scan', ['--fail-on' => 'banana'])
            ->expectsOutputToContain('Valor inválido')
            ->assertExitCode(2);
    }

    public function test_warns_about_missing_path(): void
    {
        $this->artisan('idempotency:scan', ['paths' => ['/caminho/inexistente']])
            ->expectsOutputToContain('Caminho não encontrado')
            ->assertExitCode(0);
    }

    public function test_real_analyzer_flags_unprotected_sink(): void
    {
        $file = $this->fixture('Mailer.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Mail;

        class Mailer implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void
            {
                Mail::send($mailable);
            }
        }
        PHP);

        $this->artisan('idempotency:scan', ['paths' => [$file], '--fail-on' => 'medium'])
            ->expectsOutputToContain('MÉDIO RISCO')
            ->assertExitCode(1);

        $this->artisan('idempotency:scan', ['paths' => [$file], '--fail-on' => 'high'])
            ->assertExitCode(0);
    }

    public function test_real_analyzer_accepts_protected_job_and_summarizes_directory(): void
    {
        $this->fixture('Safe.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Cache;
        use Illuminate\Support\Facades\Mail;

        class Safe implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void
            {
                if (! Cache::add('sent', true, 60)) {
                    return;
                }

                Mail::send($mailable);
            }
        }
        PHP);
        $this->fixture('Risky.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Mail;

        class Risky implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void
            {
                Mail::send($mailable);
            }
        }
        PHP);

        $this->artisan('idempotency:scan', ['paths' => [$this->fixtureDir()], '--fail-on' => 'none'])
            ->expectsOutputToContain('2 jobs analisados, 1 com risco, 1 protegidos')
            ->assertExitCode(0);
    }

    public function test_real_analyzer_passes_when_job_is_protected(): void
    {
        $file = $this->fixture('Safe.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Cache;
        use Illuminate\Support\Facades\Mail;

        class Safe implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void
            {
                Cache::lock('k', 10)->get();
                Mail::send($mailable);
            }
        }
        PHP);

        $this->artisan('idempotency:scan', ['paths' => [$file], '--fail-on' => 'low'])
            ->assertExitCode(0);
    }
}
