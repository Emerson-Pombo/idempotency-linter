<?php

declare(strict_types=1);

namespace IdempotencyLinter\Report;

/**
 * Relatório em JSON, pensado para CI e para outras ferramentas.
 *
 * O formato é versionado em "version": mudanças incompatíveis incrementam o número.
 */
final class JsonReporter
{
    public const VERSION = 1;

    /**
     * @param  callable(string): string  $relativePath  converte um caminho absoluto no exibido
     */
    public function __construct(private $relativePath) {}

    /**
     * @param  list<array{file: string, error: string}>  $pathErrors  erros anteriores à varredura (ex.: caminho inexistente)
     */
    public function render(ScanResult $result, array $pathErrors = []): string
    {
        $errors = [...$pathErrors, ...$result->errors()];

        $document = [
            'version' => self::VERSION,
            'summary' => [
                'jobs' => $result->jobCount(),
                'risky_jobs' => $result->riskyJobCount(),
                'protected_jobs' => $result->protectedJobCount(),
                'findings' => count($result->findings()),
                'errors' => count($errors),
            ],
            'findings' => array_map(fn (Finding $finding) => [
                'file' => ($this->relativePath)($finding->file),
                'line' => $finding->line,
                'job' => $finding->jobClass,
                'sink' => $finding->sink,
                'risk' => $finding->risk->value,
                'message' => $finding->message,
            ], $result->findings()),
            'errors' => array_map(fn (array $error) => [
                'file' => ($this->relativePath)($error['file']),
                'error' => $error['error'],
            ], $errors),
        ];

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
