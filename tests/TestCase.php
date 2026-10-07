<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests;

use IdempotencyLinter\IdempotencyLinterServiceProvider;
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

    /** Cria um arquivo PHP temporário e devolve o caminho absoluto. */
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
}
