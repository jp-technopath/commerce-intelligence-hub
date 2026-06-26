<?php

namespace App\Enums;

enum IntegrationStatus: string
{
    case Active      = 'active';
    case Inactive    = 'inactive';
    case Error       = 'error';
    case Pending     = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Active   => 'Active',
            self::Inactive => 'Inactive',
            self::Error    => 'Error',
            self::Pending  => 'Pending Setup',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active   => 'success',
            self::Inactive => 'gray',
            self::Error    => 'danger',
            self::Pending  => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Active   => 'heroicon-o-check-circle',
            self::Inactive => 'heroicon-o-pause-circle',
            self::Error    => 'heroicon-o-x-circle',
            self::Pending  => 'heroicon-o-clock',
        };
    }
}
