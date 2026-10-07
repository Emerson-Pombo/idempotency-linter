<?php

declare(strict_types=1);

namespace IdempotencyLinter\Report;

/**
 * JSON report, meant for CI and other tools.
 *
 * The format is versioned in "version": incompatible changes bump the number.
 */
final class JsonReporter
{
    public const VERSION = 1;

    /**
     * @param  callable(string): string  $relativePath  turns an absolute path into the displayed one
     */
    public function __construct(private $relativePath) {}

    /**
     * @param  list<array{file: string, error: string}>  $pathErrors  errors found before the scan (e.g. a path that does not exist)
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
