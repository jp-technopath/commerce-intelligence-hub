<?php

namespace App\Enums;

enum ClientStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
    case Onboarding = 'onboarding';
    case Paused   = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Active     => 'Active',
            self::Inactive   => 'Inactive',
            self::Onboarding => 'Onboarding',
            self::Paused     => 'Paused',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active     => 'success',
            self::Inactive   => 'gray',
            self::Onboarding => 'warning',
            self::Paused     => 'info',
        };
    }
}
