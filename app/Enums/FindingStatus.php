<?php

namespace App\Enums;

enum FindingStatus: string
{
    // Forge Action Lifecycle
    case New         = 'new';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Assigned    = 'assigned';
    case Resolved    = 'resolved';
    case Dismissed   = 'dismissed';

    // Legacy statuses for backward compatibility
    case Acknowledged  = 'acknowledged';
    case Snoozed       = 'snoozed';
    case Investigating = 'investigating';
    case Accepted      = 'accepted';
    case Ignored       = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::New           => 'New',
            self::UnderReview   => 'Under Review',
            self::Approved      => 'Approved',
            self::Assigned      => 'Assigned',
            self::Resolved      => 'Resolved',
            self::Dismissed     => 'Dismissed',
            self::Acknowledged  => 'Acknowledged',
            self::Snoozed       => 'Snoozed',
            self::Investigating => 'Under Review',
            self::Accepted      => 'Approved',
            self::Ignored       => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New           => 'danger',
            self::UnderReview   => 'warning',
            self::Approved      => 'primary',
            self::Assigned      => 'info',
            self::Resolved      => 'success',
            self::Dismissed     => 'gray',
            self::Acknowledged  => 'info',
            self::Snoozed       => 'gray',
            self::Investigating => 'warning',
            self::Accepted      => 'primary',
            self::Ignored       => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::New           => 'heroicon-o-sparkles',
            self::UnderReview   => 'heroicon-o-eye',
            self::Approved      => 'heroicon-o-check-badge',
            self::Assigned      => 'heroicon-o-user-check',
            self::Resolved      => 'heroicon-o-check-circle',
            self::Dismissed     => 'heroicon-o-x-circle',
            self::Acknowledged  => 'heroicon-o-hand-thumb-up',
            self::Snoozed       => 'heroicon-o-clock',
            self::Investigating => 'heroicon-o-magnifying-glass',
            self::Accepted      => 'heroicon-o-check-badge',
            self::Ignored       => 'heroicon-o-archive-box',
        };
    }

    public static function openValues(): array
    {
        return [
            self::New->value,
            self::UnderReview->value,
            self::Approved->value,
            self::Assigned->value,
            self::Acknowledged->value,
            self::Investigating->value,
            self::Accepted->value,
        ];
    }

    public static function deduplicationOpenValues(): array
    {
        return array_merge(self::openValues(), [
            self::Snoozed->value,
        ]);
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }
}
