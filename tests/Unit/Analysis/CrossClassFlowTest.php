<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit\Analysis;

use IdempotencyLinter\Report\Finding;
use IdempotencyLinter\Tests\Fixtures\Services\BaseWithHandle;
use IdempotencyLinter\Tests\Fixtures\Services\BaseWithHelper;
use IdempotencyLinter\Tests\Fixtures\Services\Gateway;
use IdempotencyLinter\Tests\Fixtures\Services\Helper;
use IdempotencyLinter\Tests\Fixtures\Services\Notifier;
use IdempotencyLinter\Tests\Fixtures\Services\PingA;
use IdempotencyLinter\Tests\Fixtures\Services\SendsMail;
use IdempotencyLinter\Tests\TestCase;
use ReflectionClass;

final class CrossClassFlowTest extends TestCase
{
    private const NS = '\\IdempotencyLinter\\Tests\\Fixtures\\Services\\';

    /** Monta um job; $header vem antes de "class" e $head completa a declaração. */
    private function job(string $members, string $head = 'implements \\Illuminate\\Contracts\\Queue\\ShouldQueue', string $uses = ''): string
    {
        return <<<PHP
        <?php
        namespace App\Jobs;

        use Illuminate\Support\Facades\Cache;
        use Illuminate\Support\Facades\Mail;

        class Sample {$head}
        {
            {$uses}
            {$members}
        }
        PHP;
    }

    private function fileOf(string $class): string
    {
        return (string) (new ReflectionClass($class))->getFileName();
    }

    /** @return list<string> */
    private function files(array $findings): array
    {
        return array_map(fn (Finding $finding) => $finding->file, $findings);
    }

    private function withNotifier(string $body): string
    {
        return $this->job('public function __construct(private '.self::NS."Notifier \$notifier) {}\n public function handle(): void { {$body} }");
    }

    public function test_follows_method_of_promoted_injected_service(): void
    {
        $findings = $this->analyze($this->withNotifier('$this->notifier->send();'));

        $this->assertCount(1, $findings);
        $this->assertSame('mail', $findings[0]->sink);
        $this->assertSame($this->fileOf(Notifier::class), $findings[0]->file);
        $this->assertSame('App\Jobs\Sample', $findings[0]->jobClass);
        $this->assertSame('Mail::send($mailable);', trim(file($findings[0]->file)[$findings[0]->line - 1]));
    }

    public function test_follows_declared_property_service(): void
    {
        $code = $this->job('private '.self::NS."Notifier \$notifier;\n public function handle(): void { \$this->notifier->send(); }");

        $this->assertSame([$this->fileOf(Notifier::class)], $this->files($this->analyze($code)));
    }

    public function test_follows_service_injected_in_the_entry_method(): void
    {
        $code = $this->job('public function handle('.self::NS.'Notifier $notifier): void { $notifier->send(); }');

        $this->assertSame([$this->fileOf(Notifier::class)], $this->files($this->analyze($code)));
    }

    public function test_follows_service_that_calls_another_service(): void
    {
        $code = $this->job('public function __construct(private '.self::NS."Relay \$relay) {}\n public function handle(): void { \$this->relay->forward(); }");

        $this->assertSame([$this->fileOf(Notifier::class)], $this->files($this->analyze($code)));
    }

    public function test_guard_before_the_service_call_protects_its_sinks(): void
    {
        $this->assertSame([], $this->analyze($this->withNotifier("Cache::add('k', 1);\n \$this->notifier->send();")));
    }

    public function test_guard_inside_the_service_protects_its_own_sink(): void
    {
        $this->assertSame([], $this->analyze($this->withNotifier('$this->notifier->sendGuarded();')));
    }

    public function test_guard_in_service_method_protects_what_comes_after_it(): void
    {
        $this->assertSame([], $this->analyze($this->withNotifier("\$this->notifier->claim();\n Mail::send(\$m);")));
    }

    public function test_guard_after_the_service_call_does_not_protect_it(): void
    {
        $this->assertCount(1, $this->analyze($this->withNotifier("\$this->notifier->send();\n Cache::add('k', 1);")));
    }

    public function test_service_methods_without_sinks_report_nothing(): void
    {
        $this->assertSame([], $this->analyze($this->withNotifier('$this->notifier->noop();')));
    }

    public function test_cycles_between_services_do_not_loop(): void
    {
        $code = $this->job('public function __construct(private '.self::NS."PingA \$a) {}\n public function handle(): void { \$this->a->ping(); }");

        $this->assertSame([$this->fileOf(PingA::class)], $this->files($this->analyze($code)));
    }

    public function test_interface_typed_dependency_is_not_followed(): void
    {
        $code = $this->job('public function __construct(private '.self::NS."Sender \$sender) {}\n public function handle(): void { \$this->sender->send(); }");

        $this->assertSame([], $this->analyze($code));
    }

    public function test_vendor_and_unknown_classes_are_not_followed(): void
    {
        $code = $this->job('public function __construct(private \\Illuminate\\Mail\\Mailer $mailer, private \\App\\Nao\\Existe $x) {}
            public function handle(): void { $this->mailer->send("v"); $this->x->run(); }');

        $this->assertSame([], $this->analyze($code));
    }

    public function test_follows_static_call_to_project_class(): void
    {
        $code = $this->job('public function handle(): void { '.self::NS.'Helper::notify(); }');

        $this->assertSame([$this->fileOf(Helper::class)], $this->files($this->analyze($code)));
    }

    public function test_project_class_registered_as_sink_is_not_expanded(): void
    {
        $config = [
            'sinks' => ['gateway' => [
                'risk' => 'high',
                'message' => 'Cobrança.',
                'match' => [['type' => 'method_call', 'class' => Gateway::class, 'methods' => ['charge']]],
            ]],
            'guards' => [],
            'chains' => [],
        ];
        $code = $this->job('public function __construct(private '.self::NS."Gateway \$gateway) {}\n public function handle(): void { \$this->gateway->charge(); }");

        $findings = $this->analyze($code, $config);

        $this->assertSame(['gateway'], array_map(fn (Finding $finding) => $finding->sink, $findings));
        $this->assertNotSame($this->fileOf(Gateway::class), $findings[0]->file);
    }

    public function test_follows_method_inherited_from_parent_class(): void
    {
        $code = $this->job('public function handle(): void { $this->mailFromParent(); }', 'extends '.self::NS.'BaseWithHelper');

        $this->assertSame([$this->fileOf(BaseWithHelper::class)], $this->files($this->analyze($code)));
    }

    public function test_follows_parent_call(): void
    {
        $code = $this->job('public function handle(): void { parent::handle(); }', 'extends '.self::NS.'BaseWithHandle');

        $this->assertSame([$this->fileOf(BaseWithHandle::class)], $this->files($this->analyze($code)));
    }

    public function test_analyzes_entry_method_inherited_from_parent(): void
    {
        $code = $this->job('', 'extends '.self::NS.'BaseWithHandle');

        $this->assertSame([$this->fileOf(BaseWithHandle::class)], $this->files($this->analyze($code)));
    }

    public function test_resolves_typed_property_inherited_from_parent(): void
    {
        $code = $this->job('public function handle(): void { $this->notifier->send(); }', 'extends '.self::NS.'BaseWithNotifier');

        $this->assertSame([$this->fileOf(Notifier::class)], $this->files($this->analyze($code)));
    }

    public function test_follows_trait_method(): void
    {
        $code = $this->job('public function handle(): void { $this->sendMailFromTrait(); }', uses: 'use '.self::NS.'SendsMail;');

        $this->assertSame([$this->fileOf(SendsMail::class)], $this->files($this->analyze($code)));
    }

    public function test_resolves_typed_property_declared_in_trait(): void
    {
        $code = $this->job('public function handle(): void { $this->traitNotifier->send(); }', uses: 'use '.self::NS.'SendsMail;');

        $this->assertSame([$this->fileOf(Notifier::class)], $this->files($this->analyze($code)));
    }

    public function test_methods_of_vendor_traits_are_ignored(): void
    {
        $code = $this->job('public function handle(): void { $this->dispatch(); }', uses: 'use \\Illuminate\\Foundation\\Bus\\Dispatchable;');

        $this->assertSame([], $this->analyze($code));
    }
}
