<?php

declare(strict_types=1);

namespace IdempotencyLinter;

use IdempotencyLinter\Analysis\Contracts\JobAnalyzer;
use IdempotencyLinter\Analysis\JobFinder;
use IdempotencyLinter\Report\ScanResult;
use PhpParser\Error as ParserError;

/**
 * Orquestra: localizar arquivos → encontrar jobs → analisar cada um.
 */
final class Scanner
{
    public function __construct(
        private readonly JobFinder $finder,
        private readonly JobAnalyzer $analyzer,
    ) {}

    /** @param list<string> $paths */
    public function scan(array $paths): ScanResult
    {
        $result = new ScanResult;

        foreach ($this->finder->phpFiles($paths) as $file) {
            try {
                $jobs = $this->finder->findInFile($file);
            } catch (ParserError $e) {
                $result->addError($file, $e->getMessage());

                continue;
            }

            foreach ($jobs as $job) {
                $result->addJob($job);

                foreach ($this->analyzer->analyze($job) as $finding) {
                    $result->addFinding($finding);
                }
            }
        }

        return $result;
    }
}
