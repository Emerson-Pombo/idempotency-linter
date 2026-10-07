<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Catalog;

use InvalidArgumentException;

/**
 * Uma regra de correspondência do catálogo (um item de "match" no config).
 */
final class MatchRule
{
    public const TYPES = ['static_call', 'method_call', 'function', 'interface', 'array_key'];

    /**
     * @param  list<string>  $methods  métodos (static_call/method_call) ou funções (function)
     * @param  list<string>  $keys  chaves de array (array_key)
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $class = null,
        public readonly array $methods = [],
        public readonly array $keys = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Tipo de match desconhecido no catálogo: {$type}");
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
