<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Catalog;

use InvalidArgumentException;

/**
 * Declara que chamar um dos "methods" em "class" (estaticamente, como numa
 * facade, ou em uma instância desse tipo) devolve um objeto do tipo "returns".
 * É assim que o linter segue encadeamentos como Mail::to($u)->cc($c)->send($m)
 * sem inferir tipos.
 */
final class Chain
{
    /** @param list<string> $methods */
    public function __construct(
        public readonly string $class,
        public readonly array $methods,
        public readonly string $returns,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['class', 'returns'] as $key) {
            if (empty($data[$key])) {
                throw new InvalidArgumentException("Encadeamento do catálogo sem a chave '{$key}'.");
            }
        }

        return new self(
            class: ltrim((string) $data['class'], '\\'),
            methods: array_values(array_map('strval', $data['methods'] ?? [])),
            returns: ltrim((string) $data['returns'], '\\'),
        );
    }
}
