<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit;

use IdempotencyLinter\Analysis\JobFinder;
use IdempotencyLinter\Tests\TestCase;
use PhpParser\Error as ParserError;

final class JobFinderTest extends TestCase
{
    public function test_finds_job_using_imported_interface(): void
    {
        $file = $this->fixture('SendInvoice.php', <<<'PHP'
        <?php
        namespace App\Jobs;

        use Illuminate\Contracts\Queue\ShouldQueue;

        class SendInvoice implements ShouldQueue
        {
            public function handle(): void {}
        }
        PHP);

        $jobs = (new JobFinder)->findInFile($file);

        $this->assertCount(1, $jobs);
        $this->assertSame('App\Jobs\SendInvoice', $jobs[0]->className);
        $this->assertSame($file, $jobs[0]->file);
        $this->assertSame(6, $jobs[0]->line);
        $this->assertNotNull($jobs[0]->entryMethod);
        $this->assertTrue($jobs[0]->implements('\Illuminate\Contracts\Queue\ShouldQueue'));
    }

    public function test_finds_job_using_fully_qualified_interface(): void
    {
        $file = $this->fixture('Job.php', <<<'PHP'
        <?php
        class Job implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void {}
        }
        PHP);

        $jobs = (new JobFinder)->findInFile($file);

        $this->assertCount(1, $jobs);
        $this->assertSame('Job', $jobs[0]->className);
    }

    public function test_ignores_classes_that_are_not_jobs(): void
    {
        $file = $this->fixture('Plain.php', <<<'PHP'
        <?php
        namespace App;

        class Plain { public function handle(): void {} }
        PHP);

        $this->assertSame([], (new JobFinder)->findInFile($file));
    }

    public function test_ignores_anonymous_classes(): void
    {
        $file = $this->fixture('Anon.php', <<<'PHP'
        <?php
        $job = new class implements \Illuminate\Contracts\Queue\ShouldQueue {
            public function handle(): void {}
        };
        PHP);

        $this->assertSame([], (new JobFinder)->findInFile($file));
    }

    public function test_entry_method_is_null_when_missing(): void
    {
        $file = $this->fixture('NoHandle.php', <<<'PHP'
        <?php
        class NoHandle implements \Illuminate\Contracts\Queue\ShouldQueue {}
        PHP);

        $jobs = (new JobFinder)->findInFile($file);

        $this->assertCount(1, $jobs);
        $this->assertNull($jobs[0]->entryMethod);
    }

    public function test_respects_custom_interface_and_entry_method(): void
    {
        $file = $this->fixture('Custom.php', <<<'PHP'
        <?php
        namespace App;

        use App\Contracts\Task;

        class Custom implements Task { public function run(): void {} }
        PHP);

        $jobs = (new JobFinder('App\Contracts\Task', 'run'))->findInFile($file);

        $this->assertCount(1, $jobs);
        $this->assertNotNull($jobs[0]->entryMethod);
    }

    public function test_does_not_follow_inheritance(): void
    {
        $file = $this->fixture('Child.php', <<<'PHP'
        <?php
        class Child extends BaseJob {}
        PHP);

        $this->assertSame([], (new JobFinder)->findInFile($file));
    }

    public function test_throws_on_syntax_error(): void
    {
        $file = $this->fixture('Broken.php', "<?php\nclass {");

        $this->expectException(ParserError::class);

        (new JobFinder)->findInFile($file);
    }

    public function test_php_files_lists_sorted_unique_php_files_only(): void
    {
        $b = $this->fixture('b.php', '<?php');
        $a = $this->fixture('a.php', '<?php');
        $this->fixture('notes.txt', 'x');

        $files = (new JobFinder)->phpFiles([$this->fixtureDir(), $a, '/caminho/inexistente']);

        $this->assertSame([$a, $b], $files);
    }
}
