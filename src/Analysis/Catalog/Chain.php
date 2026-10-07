<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Catalog;

use InvalidArgumentException;

/**
 * Declares that calling one of the "methods" on "class" (statically, as on a
 * facade, or on an instance of that type) returns an object of type "returns".
 * This is how the linter follows chains such as Mail::to($u)->cc($c)->send($m)
 * without inferring types.
 */
final class Chain
{
    /** @param list<string> $methods */
    public function __construct(
        public readonly string $class,
        public readonly array $methods,
        public readonly string $returns,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['class', 'returns'] as $key) {
            if (empty($data[$key])) {
                throw new InvalidArgumentException("Catalog chain is missing the '{$key}' key.");
            }
        }

        return new self(
            class: ltrim((string) $data['class'], '\\'),
            methods: array_values(array_map('strval', $data['methods'] ?? [])),
            returns: ltrim((string) $data['returns'], '\\'),
        );
    }
}
