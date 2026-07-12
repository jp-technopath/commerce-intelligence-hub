<?php

namespace App\Enums;

enum ConnectedAccountStatus: string
{
    case Active  = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Error   = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Active  => 'Active',
            self::Revoked => 'Revoked',
            self::Expired => 'Expired',
            self::Error   => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active  => 'success',
            self::Revoked => 'gray',
            self::Expired => 'warning',
            self::Error   => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Active  => 'heroicon-o-check-circle',
            self::Revoked => 'heroicon-o-x-circle',
            self::Expired => 'heroicon-o-clock',
            self::Error   => 'heroicon-o-exclamation-triangle',
        };
    }
}
