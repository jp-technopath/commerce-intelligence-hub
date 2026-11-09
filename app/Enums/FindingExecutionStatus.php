<?php

namespace App\Enums;

enum FindingExecutionStatus: string
{
    case New             = 'new';
    case UnderReview     = 'under_review';
    case Approved        = 'approved';
    case Assigned        = 'assigned';
    case WorkInProgress  = 'work_in_progress';
    case ActionCompleted = 'action_completed';
    case Closed          = 'closed';
    case Dismissed       = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::New             => 'New',
            self::UnderReview     => 'Under Review',
            self::Approved        => 'Approved',
            self::Assigned        => 'Assigned',
            self::WorkInProgress  => 'Work in Progress',
            self::ActionCompleted => 'Action Completed',
            self::Closed          => 'Closed',
            self::Dismissed       => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New             => 'gray',
            self::UnderReview     => 'warning',
            self::Approved        => 'primary',
            self::Assigned        => 'info',
            self::WorkInProgress  => 'info',
            self::ActionCompleted => 'success',
            self::Closed          => 'success',
            self::Dismissed       => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::New             => 'heroicon-o-sparkles',
            self::UnderReview     => 'heroicon-o-eye',
            self::Approved        => 'heroicon-o-check-badge',
            self::Assigned        => 'heroicon-o-user',
            self::WorkInProgress  => 'heroicon-o-arrow-path',
            self::ActionCompleted => 'heroicon-o-check-circle',
            self::Closed          => 'heroicon-o-archive-box',
            self::Dismissed       => 'heroicon-o-x-circle',
        };
    }
}
