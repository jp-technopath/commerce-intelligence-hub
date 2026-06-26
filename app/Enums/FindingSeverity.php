<?php

namespace App\Enums;

enum FindingSeverity: string
{
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low      => 'Low',
            self::Medium   => 'Medium',
            self::High     => 'High',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low      => 'success',
            self::Medium   => 'warning',
            self::High     => 'danger',
            self::Critical => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Low      => 'heroicon-o-information-circle',
            self::Medium   => 'heroicon-o-exclamation-triangle',
            self::High     => 'heroicon-o-exclamation-circle',
            self::Critical => 'heroicon-o-fire',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Low      => 1,
            self::Medium   => 2,
            self::High     => 3,
            self::Critical => 4,
        };
    }
}
