<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Success = 'success';
    case Failed  = 'failed';
    case Running = 'running';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failed  => 'Failed',
            self::Running => 'Running',
            self::Skipped => 'Skipped',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failed  => 'danger',
            self::Running => 'warning',
            self::Skipped => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Success => 'heroicon-o-check-circle',
            self::Failed  => 'heroicon-o-x-circle',
            self::Running => 'heroicon-o-arrow-path',
            self::Skipped => 'heroicon-o-forward',
        };
    }
}
