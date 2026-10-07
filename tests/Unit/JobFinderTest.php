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

        $jobs = (new JobFinder())->findInFile($file);

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

        $jobs = (new JobFinder())->findInFile($file);

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

        $this->assertSame([], (new JobFinder())->findInFile($file));
    }

    public function test_ignores_anonymous_classes(): void
    {
        $file = $this->fixture('Anon.php', <<<'PHP'
        <?php
        $job = new class implements \Illuminate\Contracts\Queue\ShouldQueue {
            public function handle(): void {}
        };
        PHP);

        $this->assertSame([], (new JobFinder())->findInFile($file));
    }

    public function test_entry_method_is_null_when_missing(): void
    {
        $file = $this->fixture('NoHandle.php', <<<'PHP'
        <?php
        class NoHandle implements \Illuminate\Contracts\Queue\ShouldQueue {}
        PHP);

        $jobs = (new JobFinder())->findInFile($file);

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

    public function test_ignores_class_whose_parent_cannot_be_resolved(): void
    {
        $file = $this->fixture('Child.php', <<<'PHP'
        <?php
        class Child extends BaseJobDesconhecida {}
        PHP);

        $this->assertSame([], (new JobFinder())->findInFile($file));
    }

    public function test_finds_job_that_extends_parent_from_same_file(): void
    {
        $file = $this->fixture('Chain.php', <<<'PHP'
        <?php
        namespace App\Jobs;

        abstract class Base implements \Illuminate\Contracts\Queue\ShouldQueue {}
        abstract class Middle extends Base {}
        class Leaf extends Middle { public function handle(): void {} }
        class Other {}
        PHP);

        $names = array_map(fn ($job) => $job->className, (new JobFinder())->findInFile($file));

        $this->assertSame(['App\Jobs\Base', 'App\Jobs\Middle', 'App\Jobs\Leaf'], $names);
    }

    public function test_finds_job_that_extends_parent_from_another_file(): void
    {
        $file = $this->fixture('Child.php', <<<'PHP'
        <?php
        use IdempotencyLinter\Tests\Fixtures\Jobs\BaseQueuedJob;

        class Child extends BaseQueuedJob { public function handle(): void {} }
        PHP);

        $jobs = (new JobFinder())->findInFile($file);

        $this->assertCount(1, $jobs);
        $this->assertSame('Child', $jobs[0]->className);
        $this->assertNotNull($jobs[0]->entryMethod);
        $this->assertSame([], $jobs[0]->interfaces);
    }

    public function test_finds_job_that_implements_interface_extending_job_interface(): void
    {
        $file = $this->fixture('Contracted.php', <<<'PHP'
        <?php
        class Contracted implements \IdempotencyLinter\Tests\Fixtures\Jobs\QueueableContract {}
        PHP);

        $this->assertCount(1, (new JobFinder())->findInFile($file));
    }

    public function test_inheritance_cycles_do_not_loop_or_match(): void
    {
        $file = $this->fixture('Cycle.php', <<<'PHP'
        <?php
        class A extends B {}
        class B extends A {}
        PHP);

        $this->assertSame([], (new JobFinder())->findInFile($file));
    }

    public function test_unrelated_parent_from_another_file_is_not_a_job(): void
    {
        $file = $this->fixture('NotJob.php', <<<'PHP'
        <?php
        class NotJob extends \ArrayObject {}
        PHP);

        $this->assertSame([], (new JobFinder())->findInFile($file));
    }

    public function test_throws_on_syntax_error(): void
    {
        $file = $this->fixture('Broken.php', "<?php\nclass {");

        $this->expectException(ParserError::class);

        (new JobFinder())->findInFile($file);
    }

    public function test_php_files_lists_sorted_unique_php_files_only(): void
    {
        $b = $this->fixture('b.php', '<?php');
        $a = $this->fixture('a.php', '<?php');
        $this->fixture('notes.txt', 'x');

        $files = (new JobFinder())->phpFiles([$this->fixtureDir(), $a, '/caminho/inexistente']);

        $this->assertSame([$a, $b], $files);
    }

    public function test_inherits_interfaces_from_ancestors_in_the_same_file(): void
    {
        $file = $this->fixture('Inherited.php', <<<'PHP'
        <?php
        namespace App\Jobs;

        use Illuminate\Contracts\Queue\ShouldBeUnique;
        use Illuminate\Contracts\Queue\ShouldQueue;

        abstract class Base implements ShouldQueue, ShouldBeUnique {}
        class Leaf extends Base {}
        PHP);

        $jobs = (new JobFinder())->findInFile($file);
        $leaf = $jobs[1];

        $this->assertSame('App\Jobs\Leaf', $leaf->className);
        $this->assertSame('App\Jobs\Base', $leaf->parent);
        $this->assertTrue($leaf->implements('Illuminate\Contracts\Queue\ShouldBeUnique'));
    }
}
