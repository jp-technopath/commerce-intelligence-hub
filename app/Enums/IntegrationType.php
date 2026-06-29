<?php

namespace App\Enums;

enum IntegrationType: string
{
    case Shopify       = 'shopify';
    case AdobeCommerce = 'adobe_commerce';
    case GA4           = 'ga4';
    case Clarity       = 'clarity';
    case NewRelic      = 'new_relic';
    case Klaviyo       = 'klaviyo';

    public function label(): string
    {
        return match ($this) {
            self::Shopify       => 'Shopify',
            self::AdobeCommerce => 'Adobe Commerce',
            self::GA4           => 'Google Analytics 4',
            self::Clarity       => 'Microsoft Clarity',
            self::NewRelic      => 'New Relic',
            self::Klaviyo       => 'Klaviyo',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Shopify       => 'heroicon-o-shopping-bag',
            self::AdobeCommerce => 'heroicon-o-building-storefront',
            self::GA4           => 'heroicon-o-chart-bar',
            self::Clarity       => 'heroicon-o-cursor-arrow-ripple',
            self::NewRelic      => 'heroicon-o-server-stack',
            self::Klaviyo       => 'heroicon-o-envelope',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Shopify       => 'success',
            self::AdobeCommerce => 'warning',
            self::GA4           => 'primary',
            self::Clarity       => 'info',
            self::NewRelic      => 'danger',
            self::Klaviyo       => 'success',
        };
    }

    public function metricCategories(): array
    {
        return match ($this) {
            self::Shopify       => ['commerce', 'inventory'],
            self::AdobeCommerce => ['commerce', 'inventory'],
            self::GA4           => ['commerce', 'performance'],
            self::Clarity       => ['behavioral'],
            self::NewRelic      => ['performance'],
            self::Klaviyo       => ['email_marketing'],
        };
    }
}
