<?php

declare(strict_types=1);

namespace IdempotencyLinter\Report;

enum RiskLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'ALTO RISCO',
            self::Medium => 'MÉDIO RISCO',
            self::Low => 'BAIXO RISCO',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::High => '🔴',
            self::Medium => '🟠',
            self::Low => '🟡',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    /** Um nível abaixo, ou null quando já é o menor. */
    public function lower(): ?self
    {
        return match ($this) {
            self::High => self::Medium,
            self::Medium => self::Low,
            self::Low => null,
        };
    }
}
