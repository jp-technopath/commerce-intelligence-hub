<?php

namespace App\Enums;

enum ActionItemStatus: string
{
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Blocked    = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Open       => 'Open',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Blocked    => 'Blocked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open       => 'info',
            self::InProgress => 'warning',
            self::Completed  => 'success',
            self::Blocked    => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open       => 'heroicon-o-clipboard',
            self::InProgress => 'heroicon-o-arrow-path',
            self::Completed  => 'heroicon-o-check-circle',
            self::Blocked    => 'heroicon-o-no-symbol',
        };
    }
}
