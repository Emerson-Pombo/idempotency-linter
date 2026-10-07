<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Feature;

use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;
use IdempotencyLinter\Analysis\JobClass;
use IdempotencyLinter\Report\Finding;
use IdempotencyLinter\Report\RiskLevel;
use IdempotencyLinter\Tests\Fixtures\Services\Notifier;
use IdempotencyLinter\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

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
        $this->app->bind(JobAnalyzer::class, fn () => new class($risk) implements JobAnalyzer
        {
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
            ->expectsOutputToContain('1 job analisado, 0 com risco, 1 protegido')
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
            ->expectsOutputToContain('2 jobs analisados, 1 com risco, 1 protegido')
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

    public function test_scan_finds_job_that_inherits_the_job_interface(): void
    {
        $file = $this->fixture('Child.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Mail;

        class Child extends \IdempotencyLinter\Tests\Fixtures\Jobs\BaseQueuedJob
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
    }

    private const RISKY_JOB = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Mail;

    class Risky implements \Illuminate\Contracts\Queue\ShouldQueue
    {
        public function handle(): void
        {
            Mail::send($mailable);
        }
    }
    PHP;

    /** @return array{0: int, 1: array<string, mixed>, 2: string} código, JSON decodificado, saída bruta */
    private function scanJson(array $arguments): array
    {
        $code = Artisan::call('idempotency:scan', $arguments + ['--format' => 'json']);
        $output = Artisan::output();

        return [$code, json_decode($output, true, flags: JSON_THROW_ON_ERROR), $output];
    }

    public function test_json_format_reports_summary_and_findings(): void
    {
        $file = $this->fixture('Risky.php', self::RISKY_JOB);

        [$code, $report] = $this->scanJson(['paths' => [$file]]);

        $this->assertSame(1, $code);
        $this->assertSame(1, $report['version']);
        $this->assertSame(['jobs' => 1, 'risky_jobs' => 1, 'protected_jobs' => 0, 'findings' => 1, 'errors' => 0], $report['summary']);
        $this->assertSame([[
            'file' => $file,
            'line' => 8,
            'job' => 'Risky',
            'sink' => 'mail',
            'risk' => 'medium',
            'message' => 'Envio de e-mail sem verificação de idempotência.',
        ]], $report['findings']);
        $this->assertSame([], $report['errors']);
    }

    public function test_json_output_contains_only_the_json_document(): void
    {
        $file = $this->fixture('Risky.php', self::RISKY_JOB);

        [, , $output] = $this->scanJson(['paths' => [$file]]);

        $this->assertStringStartsWith('{', ltrim($output));
        $this->assertStringEndsWith('}', rtrim($output));
        $this->assertStringNotContainsString('MÉDIO RISCO', $output);
        $this->assertStringNotContainsString('analisado', $output);
    }

    public function test_json_format_respects_fail_on(): void
    {
        $file = $this->fixture('Risky.php', self::RISKY_JOB);

        [$high] = $this->scanJson(['paths' => [$file], '--fail-on' => 'high']);
        [$none] = $this->scanJson(['paths' => [$file], '--fail-on' => 'none']);

        $this->assertSame(0, $high);
        $this->assertSame(0, $none);
    }

    public function test_json_format_without_jobs_is_a_valid_empty_report(): void
    {
        [$code, $report, $output] = $this->scanJson(['paths' => [$this->fixture('x.php', '<?php')]]);

        $this->assertSame(0, $code);
        $this->assertSame(['jobs' => 0, 'risky_jobs' => 0, 'protected_jobs' => 0, 'findings' => 0, 'errors' => 0], $report['summary']);
        $this->assertStringNotContainsString('Nenhum job', $output);
    }

    public function test_json_format_reports_unparsable_files_and_missing_paths_as_errors(): void
    {
        $broken = $this->fixture('Broken.php', "<?php\nclass {");

        [$code, $report] = $this->scanJson(['paths' => [$broken, '/caminho/inexistente']]);

        $this->assertSame(0, $code);
        $this->assertSame(2, $report['summary']['errors']);
        $this->assertSame(['/caminho/inexistente', $broken], array_column($report['errors'], 'file'));
        $this->assertSame('Caminho não encontrado', $report['errors'][0]['error']);
    }

    public function test_json_format_keeps_findings_sorted_by_risk(): void
    {
        $this->fixture('A.php', str_replace('Risky', 'A', self::RISKY_JOB));
        $this->fixture('B.php', <<<'PHP'
        <?php
        class B implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void
            {
                \IdempotencyLinter\Tests\Fixtures\Models\Invoice::create([]);
            }
        }
        PHP);

        [, $report] = $this->scanJson(['paths' => [$this->fixtureDir()], '--fail-on' => 'none']);

        $this->assertSame(['medium', 'low'], array_column($report['findings'], 'risk'));
    }

    public function test_rejects_unknown_format(): void
    {
        $this->artisan('idempotency:scan', ['--format' => 'xml'])
            ->expectsOutputToContain('Valor inválido para --format')
            ->assertExitCode(2);
    }

    public function test_text_is_still_the_default_format(): void
    {
        $this->artisan('idempotency:scan', ['paths' => [$this->fixture('Risky.php', self::RISKY_JOB)]])
            ->expectsOutputToContain('MÉDIO RISCO')
            ->assertExitCode(1);
    }

    public function test_scan_reports_sink_in_injected_service_with_its_file_and_the_job(): void
    {
        $file = $this->fixture('Cobrar.php', <<<'PHP'
        <?php
        namespace App\Jobs;

        class Cobrar implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function __construct(private \IdempotencyLinter\Tests\Fixtures\Services\Notifier $notifier) {}

            public function handle(): void
            {
                $this->notifier->send();
            }
        }
        PHP);
        $service = (new \ReflectionClass(Notifier::class))->getFileName();

        $this->artisan('idempotency:scan', ['paths' => [$file]])
            ->expectsOutputToContain($service.':15')
            ->expectsOutputToContain('Job: App\Jobs\Cobrar')
            ->assertExitCode(1);

        [, $report] = $this->scanJson(['paths' => [$file]]);

        $this->assertSame($service, $report['findings'][0]['file']);
        $this->assertSame('App\Jobs\Cobrar', $report['findings'][0]['job']);
    }
}
