<?php

namespace App\Enums;

enum FindingStatus: string
{
    case New           = 'new';
    case Acknowledged  = 'acknowledged';
    case Snoozed       = 'snoozed';
    case Resolved      = 'resolved';
    case Dismissed     = 'dismissed';

    // Legacy statuses for backward compatibility
    case Investigating = 'investigating';
    case Accepted      = 'accepted';
    case Ignored       = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::New           => 'New',
            self::Acknowledged  => 'Acknowledged',
            self::Snoozed       => 'Snoozed',
            self::Resolved      => 'Resolved',
            self::Dismissed     => 'Dismissed',
            self::Investigating => 'Investigating',
            self::Accepted      => 'Accepted',
            self::Ignored       => 'Ignored',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New           => 'danger',
            self::Acknowledged  => 'info',
            self::Snoozed       => 'gray',
            self::Resolved      => 'success',
            self::Dismissed     => 'gray',
            self::Investigating => 'warning',
            self::Accepted      => 'primary',
            self::Ignored       => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::New           => 'heroicon-o-bell-alert',
            self::Acknowledged  => 'heroicon-o-hand-thumb-up',
            self::Snoozed       => 'heroicon-o-clock',
            self::Resolved      => 'heroicon-o-check-circle',
            self::Dismissed     => 'heroicon-o-x-circle',
            self::Investigating => 'heroicon-o-magnifying-glass',
            self::Accepted      => 'heroicon-o-check-badge',
            self::Ignored       => 'heroicon-o-archive-box',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [
            self::New,
            self::Acknowledged,
            self::Investigating,
            self::Accepted,
        ], true);
    }
}
