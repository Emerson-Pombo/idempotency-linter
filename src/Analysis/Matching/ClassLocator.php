<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

use IdempotencyLinter\Analysis\JobClass;
use PhpParser\Error as ParserError;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use Throwable;

/**
 * Locates the source code of the project's classes and traits.
 *
 * It looks first in the originating file (classes in the same file) and then
 * through the autoloader (`ReflectionClass::getFileName`), without instantiating
 * anything. Classes from `vendor/`, PHP internals, interfaces and enums are never
 * located. Each file is read once.
 */
final class ClassLocator
{
    /** @var array<string, array<string, Class_|Trait_>> */
    private array $files = [];

    private readonly Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function contextFor(JobClass $job): ClassContext
    {
        return new ClassContext($job->node, $job->file, $this);
    }

    public function locate(string $name, ?string $hintFile = null): ?ClassContext
    {
        $name = ltrim($name, '\\');
        $key = strtolower($name);

        if ($hintFile !== null && isset($this->declared($hintFile)[$key])) {
            return new ClassContext($this->declared($hintFile)[$key], $hintFile, $this);
        }

        $file = $this->fileOf($name);

        if ($file === null || ! isset($this->declared($file)[$key])) {
            return null;
        }

        return new ClassContext($this->declared($file)[$key], $file, $this);
    }

    private function fileOf(string $name): ?string
    {
        try {
            if (! class_exists($name) && ! trait_exists($name)) {
                return null;
            }

            $file = (new ReflectionClass($name))->getFileName();
        } catch (Throwable) {
            return null;
        }

        if ($file === false || str_contains(str_replace('\\', '/', $file), '/vendor/')) {
            return null;
        }

        return $file;
    }

    /** @return array<string, Class_|Trait_> */
    private function declared(string $file): array
    {
        return $this->files[$file] ??= $this->parse($file);
    }

    /** @return array<string, Class_|Trait_> */
    private function parse(string $file): array
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return [];
        }

        try {
            $ast = $this->parser->parse($code) ?? [];
        } catch (ParserError) {
            return [];
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);

        $finder = new NodeFinder;
        $declared = [];

        foreach ([...$finder->findInstanceOf($ast, Class_::class), ...$finder->findInstanceOf($ast, Trait_::class)] as $node) {
            $name = $node->namespacedName?->toString() ?? $node->name?->toString();

            if ($name !== null) {
                $declared[strtolower($name)] = $node;
            }
        }

        return $declared;
    }
}
