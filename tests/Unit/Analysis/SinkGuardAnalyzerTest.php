<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit\Analysis;

use IdempotencyLinter\Report\RiskLevel;
use IdempotencyLinter\Tests\TestCase;

final class SinkGuardAnalyzerTest extends TestCase
{
    /** Monta um job com o corpo informado no handle(). */
    private function job(string $body, string $extra = ''): string
    {
        return <<<PHP
        <?php
        namespace App\Jobs;

        use Illuminate\Contracts\Queue\ShouldQueue;
        use Illuminate\Support\Facades\Cache;
        use Illuminate\Support\Facades\Http;
        use Illuminate\Support\Facades\Mail;

        class Sample implements ShouldQueue
        {
            {$extra}
            public function handle(): void
            {
                {$body}
            }
        }
        PHP;
    }

    public function test_flags_static_sink_without_guard(): void
    {
        $findings = $this->analyze($this->job('Mail::send($mailable);'));

        $this->assertCount(1, $findings);
        $this->assertSame('mail', $findings[0]->sink);
        $this->assertSame(RiskLevel::Medium, $findings[0]->risk);
        $this->assertSame('App\Jobs\Sample', $findings[0]->jobClass);
        $this->assertSame(14, $findings[0]->line);
        $this->assertSame('Envio de e-mail sem verificação de idempotência.', $findings[0]->message);
    }

    public function test_reports_one_finding_per_call(): void
    {
        $findings = $this->analyze($this->job("Mail::send(\$a);\nHttp::post('https://x.test', []);"));

        $this->assertSame(['mail', 'http'], array_map(fn ($f) => $f->sink, $findings));
        $this->assertSame([14, 15], array_map(fn ($f) => $f->line, $findings));
    }

    public function test_returns_nothing_without_sinks(): void
    {
        $this->assertSame([], $this->analyze($this->job('$x = strlen("a");')));
    }

    public function test_returns_nothing_without_entry_method(): void
    {
        $code = <<<'PHP'
        <?php
        class NoHandle implements \Illuminate\Contracts\Queue\ShouldQueue {}
        PHP;

        $this->assertSame([], $this->analyze($code));
    }

    public function test_ignores_unrelated_static_calls(): void
    {
        $this->assertSame([], $this->analyze($this->job('Cache::get("k"); Mail::fake();')));
    }
}
