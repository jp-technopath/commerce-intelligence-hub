<?php

namespace App\Enums;

enum ActionItemSource: string
{
    case Ai     = 'ai';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Ai     => 'AI Generated',
            self::Manual => 'Manual',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ai     => 'primary',
            self::Manual => 'gray',
        };
    }
}
