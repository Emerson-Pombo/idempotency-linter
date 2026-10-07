<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit\Analysis;

use IdempotencyLinter\Tests\TestCase;

/**
 * Fixa o que a v1 NÃO faz. Se uma destas limitações for removida, o teste
 * correspondente deve falhar de propósito e ser atualizado junto com o README.
 */
final class LimitationsTest extends TestCase
{
    private function job(string $members): string
    {
        return <<<PHP
        <?php
        use Illuminate\Support\Facades\Cache;
        use Illuminate\Support\Facades\Mail;

        class Limited implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            {$members}
        }
        PHP;
    }

    public function test_services_resolved_from_the_container_are_not_followed(): void
    {
        $code = $this->job('public function handle(): void { app(\\IdempotencyLinter\\Tests\\Fixtures\\Services\\Notifier::class)->send(); }');

        $this->assertSame([], $this->analyze($code));
    }

    public function test_services_held_in_local_variables_are_not_followed(): void
    {
        $code = $this->job('public function handle(): void { $notifier = new \\IdempotencyLinter\\Tests\\Fixtures\\Services\\Notifier; $notifier->send(); }');

        $this->assertSame([], $this->analyze($code));
    }

    public function test_sink_inside_closure_of_entry_method_is_detected(): void
    {
        $code = $this->job('public function handle(): void { array_map(fn () => Mail::send($m), [1]); }');

        $this->assertCount(1, $this->analyze($code));
    }

    public function test_chained_calls_with_unknown_receiver_are_not_detected(): void
    {
        $code = $this->job('public function handle(): void { $this->mailer->to($user)->send($mailable); app(\Foo::class)->send($mailable); }');

        $this->assertSame([], $this->analyze($code));
    }

    public function test_guard_in_unrelated_branch_protects_by_position_only(): void
    {
        $code = $this->job('
            public function handle(): void
            {
                if ($other) {
                    Cache::add("k", 1);
                }

                Mail::send($m);
            }
        ');

        $this->assertSame([], $this->analyze($code));
    }

    public function test_local_variable_receivers_are_not_resolved(): void
    {
        $code = $this->job('public function handle(): void { $stripe = app(\Stripe\Service\ChargeService::class); $stripe->create([]); }');

        $this->assertSame([], $this->analyze($code));
    }
}
