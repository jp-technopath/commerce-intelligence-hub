<?php

namespace App\Enums;

enum FindingCategory: string
{
    case Revenue      = 'Revenue';
    case Conversion   = 'Conversion';
    case Behavioral   = 'Behavioral';
    case Search       = 'Search';
    case Checkout     = 'Checkout';
    case Customer     = 'Customer';
    case Technical    = 'Technical';
    case Merchandising = 'Merchandising';

    public function label(): string
    {
        return match ($this) {
            self::Revenue       => 'Revenue',
            self::Conversion    => 'Conversion',
            self::Behavioral    => 'Behavioral',
            self::Search        => 'Search',
            self::Checkout      => 'Checkout',
            self::Customer      => 'Customer',
            self::Technical     => 'Technical',
            self::Merchandising => 'Merchandising',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Revenue       => 'success',
            self::Conversion    => 'warning',
            self::Behavioral    => 'info',
            self::Search        => 'primary',
            self::Checkout      => 'danger',
            self::Customer      => 'secondary',
            self::Technical     => 'gray',
            self::Merchandising => 'primary',
        };
    }
}
