<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Catalog;

use InvalidArgumentException;

/**
 * A catalog matching rule (one "match" item in the config).
 */
final class MatchRule
{
    public const TYPES = ['static_call', 'method_call', 'function', 'interface', 'array_key'];

    /**
     * @param  list<string>  $methods  methods (static_call/method_call) or functions (function)
     * @param  list<string>  $keys  array keys (array_key)
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $class = null,
        public readonly array $methods = [],
        public readonly array $keys = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown match type in catalog: {$type}");
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            class: isset($data['class']) ? ltrim((string) $data['class'], '\\') : null,
            methods: array_values(array_map('strval', $data['methods'] ?? $data['functions'] ?? [])),
            keys: array_values(array_map('strval', $data['keys'] ?? [])),
        );
    }
}
