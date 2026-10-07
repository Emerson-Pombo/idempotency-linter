<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests;

use IdempotencyLinter\Analysis\Catalog\Catalog;
use IdempotencyLinter\Analysis\JobFinder;
use IdempotencyLinter\Analysis\SinkGuardAnalyzer;
use IdempotencyLinter\IdempotencyLinterServiceProvider;
use IdempotencyLinter\Report\Finding;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    private ?string $fixtureDir = null;

    protected function getPackageProviders($app): array
    {
        return [IdempotencyLinterServiceProvider::class];
    }

    protected function tearDown(): void
    {
        if ($this->fixtureDir !== null) {
            foreach (glob($this->fixtureDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->fixtureDir);
            $this->fixtureDir = null;
        }

        parent::tearDown();
    }

    /** Creates a temporary PHP file and returns its absolute path. */
    protected function fixture(string $name, string $code): string
    {
        $this->fixtureDir ??= sys_get_temp_dir().'/idempotency-linter-'.bin2hex(random_bytes(4));

        if (! is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir);
        }

        $path = $this->fixtureDir.'/'.$name;
        file_put_contents($path, $code);

        return $path;
    }

    protected function fixtureDir(): string
    {
        return $this->fixtureDir ?? throw new \LogicException('Nenhuma fixture criada.');
    }

    /**
     * Runs the real analyzer (with the package catalog) on a job's code.
     *
     * @return list<Finding>
     */
    protected function analyze(string $code, ?array $config = null): array
    {
        $file = $this->fixture('Job'.bin2hex(random_bytes(3)).'.php', $code);
        $analyzer = new SinkGuardAnalyzer(Catalog::fromConfig($config ?? require __DIR__.'/../config/idempotency-linter.php'));

        $findings = [];

        foreach ((new JobFinder)->findInFile($file) as $job) {
            array_push($findings, ...$analyzer->analyze($job));
        }

        return $findings;
    }
}
