<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis;

use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Localiza classes que implementam a interface de job (ShouldQueue por padrão).
 *
 * Limitação atual: só reconhece a interface declarada diretamente na classe
 * (não segue herança nem interfaces que estendem ShouldQueue).
 */
final class JobFinder
{
    private Parser $parser;

    public function __construct(
        private readonly string $jobInterface = 'Illuminate\Contracts\Queue\ShouldQueue',
        private readonly string $entryMethod = 'handle',
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @param  list<string>  $paths  arquivos ou diretórios
     * @return list<string>
     */
    public function phpFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<JobClass>
     *
     * @throws ParserError quando o arquivo tem erro de sintaxe
     */
    public function findInFile(string $file): array
    {
        $code = file_get_contents($file);

        if ($code === false) {
            return [];
        }

        $ast = $this->parser->parse($code) ?? [];

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);

        /** @var list<Class_> $classes */
        $classes = (new NodeFinder)->findInstanceOf($ast, Class_::class);

        $jobs = [];

        foreach ($classes as $class) {
            if ($class->name === null) {
                continue; // classe anônima
            }

            $interfaces = array_map(
                fn (Node\Name $name) => $name->toString(),
                $class->implements,
            );

            if (! in_array(ltrim($this->jobInterface, '\\'), $interfaces, true)) {
                continue;
            }

            $jobs[] = new JobClass(
                file: $file,
                className: $class->namespacedName?->toString() ?? $class->name->toString(),
                line: $class->getStartLine(),
                interfaces: $interfaces,
                node: $class,
                entryMethod: $class->getMethod($this->entryMethod),
            );
        }

        return $jobs;
    }
}
