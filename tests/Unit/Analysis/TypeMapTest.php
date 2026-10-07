<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit\Analysis;

use IdempotencyLinter\Analysis\JobFinder;
use IdempotencyLinter\Analysis\Matching\TypeMap;
use IdempotencyLinter\Tests\TestCase;

final class TypeMapTest extends TestCase
{
    public function test_maps_declared_properties_promoted_properties_and_entry_parameters(): void
    {
        $file = $this->fixture('Job.php', <<<'PHP'
        <?php
        namespace App\Jobs;

        use App\Services\Gateway;
        use App\Services\Mailer;

        class Job implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            private Gateway $declared;
            private $untyped;
            private int|string $union;

            public function __construct(private ?Mailer $promoted, public int $count, $plain) {}

            public function handle(Gateway $injected, string $text, $loose): void {}
        }
        PHP);

        $job = (new JobFinder())->findInFile($file)[0];
        $types = TypeMap::forJob($job);

        $this->assertSame('App\Services\Gateway', $types->property('declared'));
        $this->assertSame('App\Services\Mailer', $types->property('promoted'));
        $this->assertSame('App\Services\Gateway', $types->variable('injected'));

        foreach (['untyped', 'union', 'count', 'plain', 'missing'] as $name) {
            $this->assertNull($types->property($name), $name);
        }

        foreach (['text', 'loose', 'missing'] as $name) {
            $this->assertNull($types->variable($name), $name);
        }
    }
}
