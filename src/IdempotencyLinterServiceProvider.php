<?php

declare(strict_types=1);

namespace IdempotencyLinter;

use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;
use IdempotencyLinter\Analysis\JobFinder;
use IdempotencyLinter\Analysis\NullAnalyzer;
use IdempotencyLinter\Console\ScanCommand;
use Illuminate\Support\ServiceProvider;

final class IdempotencyLinterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/idempotency-linter.php', 'idempotency-linter');

        $this->app->bind(JobFinder::class, fn ($app) => new JobFinder(
            jobInterface: $app['config']->get('idempotency-linter.job_interface', 'Illuminate\Contracts\Queue\ShouldQueue'),
            entryMethod: $app['config']->get('idempotency-linter.entry_method', 'handle'),
        ));

        // TODO: trocar pelo motor de sinks/guards.
        $this->app->bind(JobAnalyzer::class, NullAnalyzer::class);

        $this->app->bind(Scanner::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/idempotency-linter.php' => config_path('idempotency-linter.php'),
        ], 'idempotency-linter-config');

        $this->commands([
            ScanCommand::class,
        ]);
    }
}
