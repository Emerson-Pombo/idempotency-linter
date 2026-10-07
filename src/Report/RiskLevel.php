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
            self::High => 'HIGH RISK',
            self::Medium => 'MEDIUM RISK',
            self::Low => 'LOW RISK',
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

    /** One level down, or null when it is already the lowest. */
    public function lower(): ?self
    {
        return match ($this) {
            self::High => self::Medium,
            self::Medium => self::Low,
            self::Low => null,
        };
    }
}
