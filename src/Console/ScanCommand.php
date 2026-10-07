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
        {paths?* : Arquivos ou diretórios a analisar (padrão: config idempotency-linter.paths)}
        {--fail-on=low : Nível mínimo de risco que faz o comando falhar (high, medium, low, none)}
        {--format=text : Formato da saída (text, json)}';

    protected $description = 'Analisa jobs de fila em busca de efeitos colaterais sem proteção contra reexecução.';

    public function handle(Scanner $scanner): int
    {
        $failOn = strtolower((string) $this->option('fail-on'));
        $threshold = $failOn === 'none' ? null : RiskLevel::tryFrom($failOn);

        if ($failOn !== 'none' && $threshold === null) {
            $this->error("Valor inválido para --fail-on: {$failOn}. Use high, medium, low ou none.");

            return self::INVALID;
        }

        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error("Valor inválido para --format: {$format}. Use text ou json.");

            return self::INVALID;
        }

        $paths = $this->resolvePaths();
        $missing = array_values(array_filter($paths, fn (string $path) => ! file_exists($path)));

        $result = $scanner->scan($paths);

        if ($format === 'json') {
            $pathErrors = array_map(fn (string $path) => ['file' => $path, 'error' => 'Caminho não encontrado'], $missing);
            $reporter = new JsonReporter($this->relative(...));

            // Saída crua: o JSON não pode passar pelo formatador de estilos do console.
            $this->output->writeln($reporter->render($result, $pathErrors), OutputInterface::OUTPUT_RAW);
        } else {
            foreach ($missing as $path) {
                $this->warn("Caminho não encontrado: {$this->relative($path)}");
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
            '%s %d %s, %d com risco, %d %s corretamente.',
            $result->riskyJobCount() > 0 ? '❌' : '✅',
            $result->jobCount(),
            $result->jobCount() === 1 ? 'job analisado' : 'jobs analisados',
            $result->riskyJobCount(),
            $result->protectedJobCount(),
            $result->protectedJobCount() === 1 ? 'protegido' : 'protegidos',
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
