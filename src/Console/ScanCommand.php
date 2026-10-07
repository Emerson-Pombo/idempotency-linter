<?php

declare(strict_types=1);

namespace IdempotencyLinter\Console;

use IdempotencyLinter\Report\Finding;
use IdempotencyLinter\Report\JsonReporter;
use IdempotencyLinter\Report\RiskLevel;
use IdempotencyLinter\Report\ScanResult;
use IdempotencyLinter\Scanner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class ScanCommand extends Command
{
    protected $signature = 'idempotency:scan
        {paths?* : Files or directories to analyze (default: config idempotency-linter.paths)}
        {--fail-on=low : Minimum risk level that makes the command fail (high, medium, low, none)}
        {--format=text : Output format (text, json)}';

    protected $description = 'Analyzes queue jobs for side effects that are not protected against re-execution.';

    public function handle(Scanner $scanner): int
    {
        $failOn = strtolower((string) $this->option('fail-on'));
        $threshold = $failOn === 'none' ? null : RiskLevel::tryFrom($failOn);

        if ($failOn !== 'none' && $threshold === null) {
            $this->error("Invalid value for --fail-on: {$failOn}. Use high, medium, low or none.");

            return self::INVALID;
        }

        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error("Invalid value for --format: {$format}. Use text or json.");

            return self::INVALID;
        }

        $paths = $this->resolvePaths();
        $missing = array_values(array_filter($paths, fn (string $path) => ! file_exists($path)));

        $result = $scanner->scan($paths);

        if ($format === 'json') {
            $pathErrors = array_map(fn (string $path) => ['file' => $path, 'error' => 'Path not found'], $missing);
            $reporter = new JsonReporter($this->relative(...));

            // Raw output: the JSON must not go through the console style formatter.
            $this->output->writeln($reporter->render($result, $pathErrors), OutputInterface::OUTPUT_RAW);
        } else {
            foreach ($missing as $path) {
                $this->warn("Path not found: {$this->relative($path)}");
            }

            $this->render($result);
        }

        if ($threshold !== null && $result->hasFindingsAtOrAbove($threshold)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolvePaths(): array
    {
        /** @var list<string> $paths */
        $paths = $this->argument('paths') ?: (array) config('idempotency-linter.paths', ['app/Jobs']);

        return array_map(
            fn (string $path) => $this->isAbsolute($path) ? $path : base_path($path),
            $paths,
        );
    }

    private function render(ScanResult $result): void
    {
        foreach ($result->errors() as $error) {
            $this->warn("⚠️  Could not analyze {$this->relative($error['file'])}: {$error['error']}");
        }

        foreach ($result->findings() as $finding) {
            $this->renderFinding($finding);
        }

        if ($result->jobCount() === 0) {
            $this->info('No jobs (ShouldQueue) found.');

            return;
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d %s, %d at risk, %d protected.',
            $result->riskyJobCount() > 0 ? '❌' : '✅',
            $result->jobCount(),
            $result->jobCount() === 1 ? 'job analyzed' : 'jobs analyzed',
            $result->riskyJobCount(),
            $result->protectedJobCount(),
        ));
    }

    private function renderFinding(Finding $finding): void
    {
        $this->line(sprintf(
            '%s <options=bold>%s</> — %s:%d',
            $finding->risk->icon(),
            $finding->risk->label(),
            $this->relative($finding->file),
            $finding->line,
        ));
        $this->line('   '.$finding->message);
        $this->line('   Job: '.$finding->jobClass);
    }

    private function relative(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
