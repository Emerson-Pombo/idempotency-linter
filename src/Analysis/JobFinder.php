<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis;

use IdempotencyLinter\Analysis\Matching\ClassMatcher;
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
 * Uma classe é job quando implementa a interface de job, diretamente ou por uma
 * interface que a estende, ou quando herda de uma classe que é job. Pais no mesmo
 * arquivo são resolvidos pelo AST; pais em outros arquivos, pelo autoload (sem
 * instanciar nada). Pai que não carrega não torna a classe um job.
 */
final class JobFinder
{
    private Parser $parser;

    public function __construct(
        private readonly string $jobInterface = 'Illuminate\Contracts\Queue\ShouldQueue',
        private readonly string $entryMethod = 'handle',
        ?Parser $parser = null,
        private readonly ClassMatcher $classes = new ClassMatcher,
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

        $declared = [];

        foreach ($classes as $class) {
            if ($class->name !== null) {
                $declared[strtolower($this->fullName($class))] = $class;
            }
        }

        $jobs = [];

        foreach ($classes as $class) {
            if ($class->name === null) {
                continue; // classe anônima
            }

            if (! $this->isJob($class, $declared)) {
                continue;
            }

            $interfaces = $this->declaredInterfaces($class, $declared);

            $jobs[] = new JobClass(
                file: $file,
                className: $this->fullName($class),
                line: $class->getStartLine(),
                interfaces: $interfaces,
                node: $class,
                entryMethod: $class->getMethod($this->entryMethod),
                parent: $class->extends?->toString(),
            );
        }

        return $jobs;
    }

    /**
     * @param  array<string, Class_>  $declared  classes nomeadas do arquivo, por nome completo em minúsculas
     * @param  array<string, true>  $seen  para não entrar em loop em heranças cíclicas
     */
    private function isJob(Class_ $class, array $declared, array $seen = []): bool
    {
        $key = strtolower($this->fullName($class));

        if (isset($seen[$key])) {
            return false;
        }

        $seen[$key] = true;

        foreach ($class->implements as $interface) {
            if ($this->classes->matches($interface->toString(), $this->jobInterface)) {
                return true;
            }
        }

        if ($class->extends === null) {
            return false;
        }

        $parent = $class->extends->toString();
        $local = $declared[strtolower($parent)] ?? null;

        return $local !== null
            ? $this->isJob($local, $declared, $seen)
            : $this->classes->matches($parent, $this->jobInterface);
    }

    /**
     * Interfaces declaradas na classe e nos ancestrais do mesmo arquivo. Ancestrais
     * de outros arquivos ficam por conta de {@see JobClass::$parent}.
     *
     * @param  array<string, Class_>  $declared
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private function declaredInterfaces(Class_ $class, array $declared, array $seen = []): array
    {
        $key = strtolower($this->fullName($class));

        if (isset($seen[$key])) {
            return [];
        }

        $seen[$key] = true;

        $interfaces = array_map(fn (Node\Name $name) => $name->toString(), $class->implements);
        $parent = $class->extends !== null ? ($declared[strtolower($class->extends->toString())] ?? null) : null;

        if ($parent !== null) {
            array_push($interfaces, ...$this->declaredInterfaces($parent, $declared, $seen));
        }

        return array_values(array_unique($interfaces));
    }

    private function fullName(Class_ $class): string
    {
        return $class->namespacedName?->toString() ?? $class->name?->toString() ?? '';
    }
}
