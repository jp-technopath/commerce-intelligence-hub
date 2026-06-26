<?php

namespace App\Enums;

enum FindingStatus: string
{
    case New          = 'new';
    case Investigating = 'investigating';
    case Accepted     = 'accepted';
    case Resolved     = 'resolved';
    case Ignored      = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::New           => 'New',
            self::Investigating => 'Investigating',
            self::Accepted      => 'Accepted',
            self::Resolved      => 'Resolved',
            self::Ignored       => 'Ignored',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New           => 'danger',
            self::Investigating => 'warning',
            self::Accepted      => 'primary',
            self::Resolved      => 'success',
            self::Ignored       => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::New           => 'heroicon-o-bell-alert',
            self::Investigating => 'heroicon-o-magnifying-glass',
            self::Accepted      => 'heroicon-o-check-badge',
            self::Resolved      => 'heroicon-o-check-circle',
            self::Ignored       => 'heroicon-o-archive-box',
        };
    }
}
