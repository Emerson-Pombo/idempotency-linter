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
        $extra = 'public function __construct(private \\Illuminate\\Http\\Client\\PendingRequest $http) {}';
        $unprotected = $this->analyze($this->job("\$this->http->post('u', []);", $extra));
        $protected = $this->analyze($this->job("\$this->http->post('u', ['idempotency-key' => \$k]);", $extra));

        $this->assertCount(1, $unprotected);
        $this->assertSame([], $protected);
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

    public function test_flags_method_call_on_typed_promoted_property(): void
    {
        $extra = 'public function __construct(private readonly \\Stripe\\Service\\PaymentIntentService $stripe) {}';

        $findings = $this->analyze($this->job('$this->stripe->create([]);', $extra));

        $this->assertCount(1, $findings);
        $this->assertSame('payment', $findings[0]->sink);
        $this->assertSame(RiskLevel::High, $findings[0]->risk);
    }

    public function test_flags_method_call_on_typed_declared_property(): void
    {
        $extra = 'private \\Stripe\\Service\\ChargeService $charges;';

        $findings = $this->analyze($this->job('$this->charges->create([]);', $extra));

        $this->assertSame(['payment'], array_map(fn ($f) => $f->sink, $findings));
    }

    public function test_flags_method_call_on_typed_handle_parameter(): void
    {
        $code = str_replace(
            'public function handle(): void',
            'public function handle(\\Stripe\\Service\\RefundService $refunds): void',
            $this->job('$refunds->create([]);'),
        );

        $this->assertSame(['payment'], array_map(fn ($f) => $f->sink, $this->analyze($code)));
    }

    public function test_flags_nullable_typed_property(): void
    {
        $extra = 'public function __construct(private ?\\Stripe\\Service\\ChargeService $charges = null) {}';

        $this->assertCount(1, $this->analyze($this->job('$this->charges->create([]);', $extra)));
    }

    public function test_ignores_untyped_receivers_without_error(): void
    {
        $extra = 'private $stripe; public function __construct(private $other, private int $n) {}';
        $body = '$this->stripe->create([]); $this->other->create([]); $this->n->create([]); $local->create([]); (new Foo)->create([]);';

        $this->assertSame([], $this->analyze($this->job($body, $extra)));
    }

    public function test_ignores_other_methods_of_a_known_type(): void
    {
        $extra = 'public function __construct(private \\Stripe\\Service\\ChargeService $charges) {}';

        $this->assertSame([], $this->analyze($this->job('$this->charges->retrieve("ch_1");', $extra)));
    }

    public function test_guard_before_method_sink_protects_it(): void
    {
        $extra = 'public function __construct(private \\Stripe\\Service\\ChargeService $charges) {}';

        $this->assertSame([], $this->analyze($this->job("Cache::add('k', 1);\n\$this->charges->create([]);", $extra)));
    }

    public function test_flags_static_call_on_model_subclass(): void
    {
        $findings = $this->analyze($this->job('\\IdempotencyLinter\\Tests\\Fixtures\\Models\\Invoice::create([]);'));

        $this->assertSame(['database_insert'], array_map(fn ($f) => $f->sink, $findings));
        $this->assertSame(RiskLevel::Low, $findings[0]->risk);
    }

    public function test_upsert_on_model_subclass_counts_as_guard(): void
    {
        $invoice = '\\IdempotencyLinter\\Tests\\Fixtures\\Models\\Invoice';

        $this->assertSame([], $this->analyze($this->job("{$invoice}::firstOrCreate(['id' => 1]);\nMail::send(\$m);")));
    }

    public function test_flags_method_call_through_trait_of_declared_type(): void
    {
        $extra = 'public function __construct(private \\IdempotencyLinter\\Tests\\Fixtures\\Models\\VipCustomer $customer) {}';

        $findings = $this->analyze($this->job('$this->customer->notify($n);', $extra));

        $this->assertSame(['notification'], array_map(fn ($f) => $f->sink, $findings));
    }

    private function unique(string $body, string $extra = '', string $interface = 'ShouldBeUnique'): string
    {
        return str_replace(
            'implements ShouldQueue',
            'implements ShouldQueue, \\Illuminate\\Contracts\\Queue\\'.$interface,
            $this->job($body, $extra),
        );
    }

    public function test_should_be_unique_lowers_high_risk_to_medium(): void
    {
        $extra = 'public function __construct(private \\Stripe\\Service\\ChargeService $charges) {}';

        $findings = $this->analyze($this->unique('$this->charges->create([]);', $extra));

        $this->assertCount(1, $findings);
        $this->assertSame(RiskLevel::Medium, $findings[0]->risk);
        $this->assertStringContainsString('Chamada a gateway de pagamento', $findings[0]->message);
        $this->assertStringContainsString('Proteção parcial', $findings[0]->message);
    }

    public function test_should_be_unique_lowers_medium_risk_to_low(): void
    {
        $findings = $this->analyze($this->unique('Mail::send($m);'));

        $this->assertSame(RiskLevel::Low, $findings[0]->risk);
    }

    public function test_should_be_unique_removes_low_risk_findings(): void
    {
        $body = '\\IdempotencyLinter\\Tests\\Fixtures\\Models\\Invoice::create([]);';

        $this->assertSame([], $this->analyze($this->unique($body)));
    }

    public function test_interface_extending_should_be_unique_counts_as_partial(): void
    {
        $findings = $this->analyze($this->unique('Mail::send($m);', '', 'ShouldBeUniqueUntilProcessing'));

        $this->assertSame(RiskLevel::Low, $findings[0]->risk);
    }

    public function test_total_guard_still_removes_finding_for_unique_job(): void
    {
        $this->assertSame([], $this->analyze($this->unique("Cache::add('k', 1);\nMail::send(\$m);")));
    }

    public function test_job_without_should_be_unique_keeps_original_risk(): void
    {
        $findings = $this->analyze($this->job('Mail::send($m);'));

        $this->assertSame(RiskLevel::Medium, $findings[0]->risk);
        $this->assertStringNotContainsString('parcial', $findings[0]->message);
    }

    /** @return list<string> */
    private function sinks(string $body, string $extra = ''): array
    {
        return array_map(fn ($f) => $f->sink, $this->analyze($this->job($body, $extra)));
    }

    public function test_flags_send_chained_on_mail_facade(): void
    {
        $this->assertSame(['mail'], $this->sinks('Mail::to($user)->send($mailable);'));
    }

    public function test_follows_long_mail_chain(): void
    {
        $this->assertSame(['mail'], $this->sinks('Mail::to($user)->cc($a)->bcc($b)->locale("pt")->send($mailable);'));
    }

    public function test_chained_mail_sink_reports_line_of_the_chain_start(): void
    {
        $findings = $this->analyze($this->job("Mail::to(\$user)\n    ->send(\$mailable);"));

        $this->assertSame(15, $findings[0]->line);
    }

    public function test_guard_before_chained_sink_protects_it(): void
    {
        $this->assertSame([], $this->sinks("Cache::add('k', 1);\nMail::to(\$user)->send(\$mailable);"));
    }

    public function test_ignores_non_sink_methods_at_the_end_of_a_chain(): void
    {
        $this->assertSame([], $this->sinks('Mail::to($user)->queue($mailable); Mail::to($user)->later(1, $mailable);'));
    }

    public function test_flags_post_chained_on_http_facade(): void
    {
        $this->assertSame(['http'], $this->sinks("Http::withToken(\$t)->acceptJson()->timeout(5)->post('u', []);"));
    }

    public function test_http_get_chain_is_not_a_sink(): void
    {
        $this->assertSame([], $this->sinks("Http::withHeaders(['A' => 'b'])->get('u');"));
    }

    public function test_idempotency_key_in_chain_headers_protects_http_sink(): void
    {
        $this->assertSame([], $this->sinks("Http::withHeaders(['Idempotency-Key' => \$k])->post('u', []);"));
    }

    public function test_flags_anonymous_notification_chain(): void
    {
        $code = str_replace('use Illuminate\\Support\\Facades\\Mail;', 'use Illuminate\\Support\\Facades\\Mail; use Illuminate\\Support\\Facades\\Notification;', $this->job("Notification::route('mail', \$to)->notify(\$n);"));

        $this->assertSame(['notification'], array_map(fn ($f) => $f->sink, $this->analyze($code)));
    }

    public function test_follows_chain_that_starts_at_typed_property(): void
    {
        $extra = 'public function __construct(private \\Illuminate\\Http\\Client\\PendingRequest $http) {}';

        $this->assertSame(['http'], $this->sinks("\$this->http->withToken(\$t)->post('u', []);", $extra));
    }

    public function test_unknown_chain_steps_are_ignored(): void
    {
        $this->assertSame([], $this->sinks('$foo->to($u)->send($m); Mail::desconhecido($u)->send($m); Http::withToken($t)->desconhecido()->post("u", []);'));
    }

    public function test_should_be_unique_inherited_from_base_class_is_partial_protection(): void
    {
        $code = str_replace(
            'implements ShouldQueue',
            'extends \\IdempotencyLinter\\Tests\\Fixtures\\Jobs\\BaseUniqueJob',
            $this->job('Mail::send($m);'),
        );

        $findings = $this->analyze($code);

        $this->assertCount(1, $findings);
        $this->assertSame(RiskLevel::Low, $findings[0]->risk);
    }

    public function test_job_extending_base_without_unique_keeps_original_risk(): void
    {
        $code = str_replace(
            'implements ShouldQueue',
            'extends \\IdempotencyLinter\\Tests\\Fixtures\\Jobs\\BaseQueuedJob',
            $this->job('Mail::send($m);'),
        );

        $this->assertSame(RiskLevel::Medium, $this->analyze($code)[0]->risk);
    }

    /** @param list<string> $methods corpos de métodos privados, `m1`, `m2`... */
    private function withMethods(string $handleBody, string ...$methods): string
    {
        return $this->job($handleBody, implode("\n", $methods));
    }

    public function test_follows_private_method_called_from_entry_method(): void
    {
        $code = $this->withMethods('$this->notify();', 'private function notify(): void { Mail::send($m); }');

        $this->assertSame(['mail'], array_map(fn ($f) => $f->sink, $this->analyze($code)));
    }

    public function test_finding_points_to_the_line_of_the_sink_in_the_followed_method(): void
    {
        $code = $this->withMethods('$this->notify();', "private function notify(): void\n{\nMail::send(\$m);\n}");

        $handleLine = 16;
        $findings = $this->analyze($code);

        $this->assertCount(1, $findings);
        $this->assertNotSame($handleLine, $findings[0]->line);
        $this->assertSame('Mail::send($m);', trim(explode("\n", $code)[$findings[0]->line - 1]));
    }

    public function test_guard_before_call_site_protects_followed_sink(): void
    {
        $code = $this->withMethods("Cache::add('k', 1);\n\$this->notify();", 'private function notify(): void { Mail::send($m); }');

        $this->assertSame([], $this->analyze($code));
    }

    public function test_guard_inside_followed_method_protects_what_comes_after_it(): void
    {
        $code = $this->withMethods(
            "\$this->claim();\nMail::send(\$m);",
            "private function claim(): void { Cache::add('k', 1); }",
        );

        $this->assertSame([], $this->analyze($code));
    }

    public function test_guard_after_call_site_does_not_protect_followed_sink(): void
    {
        $code = $this->withMethods("\$this->notify();\nCache::add('k', 1);", 'private function notify(): void { Mail::send($m); }');

        $this->assertCount(1, $this->analyze($code));
    }

    public function test_guard_defined_later_in_file_but_called_after_does_not_protect(): void
    {
        $code = $this->withMethods(
            "\$this->notify();\n\$this->claim();",
            'private function claim(): void { Cache::add("k", 1); }',
            'private function notify(): void { Mail::send($m); }',
        );

        $this->assertCount(1, $this->analyze($code));
    }

    public function test_follows_nested_calls(): void
    {
        $code = $this->withMethods(
            '$this->a();',
            'private function a(): void { $this->b(); }',
            'private function b(): void { Mail::send($m); }',
        );

        $this->assertCount(1, $this->analyze($code));
    }

    /** Cadeia handle → m1 → … → m{$levels}, com o sink no último método. */
    private function chain(int $levels): string
    {
        $methods = [];

        for ($i = 1; $i <= $levels; $i++) {
            $body = $i < $levels ? '$this->m'.($i + 1).'();' : 'Mail::send($m);';
            $methods[] = "private function m{$i}(): void { {$body} }";
        }

        return $this->withMethods('$this->m1();', ...$methods);
    }

    public function test_follows_up_to_five_levels_of_calls(): void
    {
        $this->assertCount(1, $this->analyze($this->chain(5)));
    }

    public function test_stops_following_after_five_levels(): void
    {
        $this->assertSame([], $this->analyze($this->chain(6)));
    }

    public function test_recursion_does_not_loop(): void
    {
        $code = $this->withMethods(
            '$this->a();',
            'private function a(): void { $this->b(); Mail::send($m); }',
            'private function b(): void { $this->a(); }',
        );

        $this->assertCount(1, $this->analyze($code));
    }

    public function test_method_called_twice_reports_once(): void
    {
        $code = $this->withMethods('$this->notify(); $this->notify();', 'private function notify(): void { Mail::send($m); }');

        $this->assertCount(1, $this->analyze($code));
    }

    public function test_reports_when_any_call_site_is_unprotected(): void
    {
        $code = $this->withMethods(
            "\$this->notify();\nCache::add('k', 1);\n\$this->notify();",
            'private function notify(): void { Mail::send($m); }',
        );

        $this->assertCount(1, $this->analyze($code));
    }

    public function test_follows_self_and_static_calls(): void
    {
        $code = $this->withMethods(
            'self::a(); static::b();',
            'private static function a(): void { Mail::send($m); }',
            'private static function b(): void { Http::post("u", []); }',
        );

        $this->assertSame(['mail', 'http'], array_map(fn ($f) => $f->sink, $this->analyze($code)));
    }

    public function test_does_not_follow_calls_on_other_receivers(): void
    {
        $code = $this->withMethods(
            '$other->notify(); Other::notify(); $this->undefined();',
            'private function notify(): void { Mail::send($m); }',
        );

        $this->assertSame([], $this->analyze($code));
    }

    public function test_followed_method_uses_its_own_parameter_types(): void
    {
        $code = $this->withMethods(
            '$this->pay();',
            'private function pay(\\Stripe\\Service\\ChargeService $charges): void { $charges->create([]); }',
        );

        $this->assertSame(['payment'], array_map(fn ($f) => $f->sink, $this->analyze($code)));
    }

    public function test_entry_parameter_types_do_not_leak_into_followed_methods(): void
    {
        $code = str_replace(
            'public function handle(): void',
            'public function handle(\\Stripe\\Service\\ChargeService $charges): void',
            $this->withMethods('$this->pay();', 'private function pay(): void { $charges->create([]); }'),
        );

        $this->assertSame([], $this->analyze($code));
    }

    public function test_should_be_unique_still_reduces_risk_of_followed_sinks(): void
    {
        $code = $this->unique('$this->notify();', 'private function notify(): void { Mail::send($m); }');

        $this->assertSame(RiskLevel::Low, $this->analyze($code)[0]->risk);
    }
}
