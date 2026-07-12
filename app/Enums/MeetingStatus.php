<?php

namespace App\Enums;

enum MeetingStatus: string
{
    case Detected          = 'detected';
    case NeedsMapping      = 'needs_mapping';
    case PrepPending       = 'prep_pending';
    case PrepGenerated     = 'prep_generated';
    case DraftCreated      = 'draft_created';
    case Completed         = 'completed';
    case FollowUpGenerated = 'followup_generated';
    case Canceled          = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Detected          => 'Detected',
            self::NeedsMapping      => 'Needs Mapping',
            self::PrepPending       => 'Prep Pending',
            self::PrepGenerated     => 'Prep Generated',
            self::DraftCreated      => 'Draft Created',
            self::Completed         => 'Completed',
            self::FollowUpGenerated => 'Follow-Up Generated',
            self::Canceled          => 'Canceled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Detected          => 'info',
            self::NeedsMapping      => 'warning',
            self::PrepPending       => 'gray',
            self::PrepGenerated     => 'primary',
            self::DraftCreated      => 'success',
            self::Completed         => 'success',
            self::FollowUpGenerated => 'primary',
            self::Canceled          => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Detected          => 'heroicon-o-signal',
            self::NeedsMapping      => 'heroicon-o-map-pin',
            self::PrepPending       => 'heroicon-o-clock',
            self::PrepGenerated     => 'heroicon-o-document-check',
            self::DraftCreated      => 'heroicon-o-envelope',
            self::Completed         => 'heroicon-o-check-circle',
            self::FollowUpGenerated => 'heroicon-o-chat-bubble-left-right',
            self::Canceled          => 'heroicon-o-x-circle',
        };
    }
}
