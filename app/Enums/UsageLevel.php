<?php

namespace App\Enums;

enum UsageLevel: string
{
    case Normal = 'normal';
    case Warning = 'warning';
    case Critical = 'critical';
    case Exceeded = 'exceeded';

    public static function fromPercentage(float $percentage): self
    {
        return match (true) {
            $percentage >= 100 => self::Exceeded,
            $percentage >= 95 => self::Critical,
            $percentage >= 80 => self::Warning,
            default => self::Normal,
        };
    }

    public function isBlocking(): bool
    {
        return $this === self::Exceeded;
    }

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Warning => 'Atenção',
            self::Critical => 'Crítico',
            self::Exceeded => 'Limite atingido',
        };
    }
}
