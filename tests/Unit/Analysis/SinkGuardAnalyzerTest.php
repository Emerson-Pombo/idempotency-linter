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
        use Illuminate\Database\Eloquent\Model;

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
        $this->assertSame(15, $findings[0]->line);
        $this->assertSame('Envio de e-mail sem verificação de idempotência.', $findings[0]->message);
    }

    public function test_reports_one_finding_per_call(): void
    {
        $findings = $this->analyze($this->job("Mail::send(\$a);\nHttp::post('https://x.test', []);"));

        $this->assertSame(['mail', 'http'], array_map(fn ($f) => $f->sink, $findings));
        $this->assertSame([15, 16], array_map(fn ($f) => $f->line, $findings));
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

    public function test_guard_before_sink_protects_it(): void
    {
        $this->assertSame([], $this->analyze($this->job(<<<'BODY'
        if (! Cache::add('sent:1', true, 60)) {
                    return;
                }
                Mail::send($mailable);
        BODY)));
    }

    public function test_cache_lock_before_sink_protects_it(): void
    {
        $this->assertSame([], $this->analyze($this->job("Cache::lock('k', 10)->get();\nMail::send(\$m);")));
    }

    public function test_guard_after_sink_does_not_protect_it(): void
    {
        $findings = $this->analyze($this->job("Mail::send(\$m);\nCache::add('sent:1', true, 60);"));

        $this->assertCount(1, $findings);
        $this->assertSame('mail', $findings[0]->sink);
    }

    public function test_guard_protects_every_later_sink(): void
    {
        $this->assertSame([], $this->analyze($this->job("Cache::add('k', 1);\nMail::send(\$a);\nHttp::post('u', []);")));
    }

    public function test_guard_only_protects_sinks_after_it(): void
    {
        $findings = $this->analyze($this->job("Mail::send(\$a);\nCache::add('k', 1);\nHttp::post('u', []);"));

        $this->assertSame(['mail'], array_map(fn ($f) => $f->sink, $findings));
    }

    public function test_static_upsert_counts_as_guard(): void
    {
        $this->assertSame([], $this->analyze($this->job("Model::firstOrCreate(['id' => 1]);\nMail::send(\$m);")));
        $this->assertSame([], $this->analyze($this->job("Model::updateOrCreate(['id' => 1]);\nMail::send(\$m);")));
    }

    public function test_idempotency_key_in_sink_arguments_protects_it(): void
    {
        $body = "\\Stripe\\Charge::create(['amount' => 1], ['idempotency_key' => \$key]);";

        $this->assertSame([], $this->analyze($this->job($body)));
    }

    public function test_idempotency_key_array_before_sink_protects_it(): void
    {
        $body = "\$opts = ['Idempotency-Key' => \$key];\n\\Stripe\\Charge::create(['amount' => 1], \$opts);";

        $this->assertSame([], $this->analyze($this->job($body)));
    }

    public function test_header_name_is_case_insensitive(): void
    {
        $body = "Http::withHeaders(['idempotency-key' => \$key])->post('u', []);";

        $this->assertSame([], $this->analyze($this->job($body)));
    }

    public function test_array_with_other_keys_does_not_protect(): void
    {
        $findings = $this->analyze($this->job("\\Stripe\\Charge::create(['amount' => 1], ['expand' => ['x']]);"));

        $this->assertSame(['payment'], array_map(fn ($f) => $f->sink, $findings));
    }

    public function test_idempotency_key_array_after_sink_does_not_protect(): void
    {
        $findings = $this->analyze($this->job("\\Stripe\\Charge::create(['amount' => 1]);\n\$opts = ['idempotency_key' => 1];"));

        $this->assertCount(1, $findings);
    }
}
