<?php

namespace App\Enums;

enum DeploymentType: string
{
    case Theme           = 'theme';
    case PlatformRelease = 'platform_release';
    case Checkout        = 'checkout';
    case Search          = 'search';
    case AppInstall      = 'app_install';
    case Promotion       = 'promotion';
    case Configuration   = 'configuration';
    case Other           = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Theme           => 'Theme Update',
            self::PlatformRelease => 'Platform Release',
            self::Checkout        => 'Checkout Change',
            self::Search          => 'Search Configuration',
            self::AppInstall      => 'App Installation',
            self::Promotion       => 'Promotion / Campaign',
            self::Configuration   => 'Configuration Change',
            self::Other           => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Theme           => 'primary',
            self::PlatformRelease => 'warning',
            self::Checkout        => 'danger',
            self::Search          => 'info',
            self::AppInstall      => 'success',
            self::Promotion       => 'warning',
            self::Configuration   => 'gray',
            self::Other           => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Theme           => 'heroicon-o-paint-brush',
            self::PlatformRelease => 'heroicon-o-arrow-up-circle',
            self::Checkout        => 'heroicon-o-credit-card',
            self::Search          => 'heroicon-o-magnifying-glass',
            self::AppInstall      => 'heroicon-o-puzzle-piece',
            self::Promotion       => 'heroicon-o-tag',
            self::Configuration   => 'heroicon-o-cog-6-tooth',
            self::Other           => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }
}
