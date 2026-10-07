<?php

declare(strict_types=1);

namespace IdempotencyLinter\Console;

use IdempotencyLinter\Report\Finding;
use IdempotencyLinter\Report\RiskLevel;
use IdempotencyLinter\Report\ScanResult;
use IdempotencyLinter\Scanner;
use Illuminate\Console\Command;

final class ScanCommand extends Command
{
    protected $signature = 'idempotency:scan
        {paths?* : Arquivos ou diretórios a analisar (padrão: config idempotency-linter.paths)}
        {--fail-on=low : Nível mínimo de risco que faz o comando falhar (high, medium, low, none)}';

    protected $description = 'Analisa jobs de fila em busca de efeitos colaterais sem proteção contra reexecução.';

    public function handle(Scanner $scanner): int
    {
        $failOn = strtolower((string) $this->option('fail-on'));
        $threshold = $failOn === 'none' ? null : RiskLevel::tryFrom($failOn);

        if ($failOn !== 'none' && $threshold === null) {
            $this->error("Valor inválido para --fail-on: {$failOn}. Use high, medium, low ou none.");

            return self::INVALID;
        }

        $paths = $this->resolvePaths();

        foreach ($paths as $path) {
            if (! file_exists($path)) {
                $this->warn("Caminho não encontrado: {$this->relative($path)}");
            }
        }

        $result = $scanner->scan($paths);

        $this->render($result);

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
            $this->warn("⚠️  Não foi possível analisar {$this->relative($error['file'])}: {$error['error']}");
        }

        foreach ($result->findings() as $finding) {
            $this->renderFinding($finding);
        }

        if ($result->jobCount() === 0) {
            $this->info('Nenhum job (ShouldQueue) encontrado.');

            return;
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d %s analisados, %d com risco, %d protegidos corretamente.',
            $result->riskyJobCount() > 0 ? '❌' : '✅',
            $result->jobCount(),
            $result->jobCount() === 1 ? 'job' : 'jobs',
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
