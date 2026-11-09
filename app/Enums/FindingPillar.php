<?php

namespace App\Enums;

enum FindingPillar: string
{
    case GrowthOpportunity = 'growth_opportunity';
    case BusinessRisk      = 'business_risk';
    case TechnicalHealth   = 'technical_health';

    public function label(): string
    {
        return match ($this) {
            self::GrowthOpportunity => 'Growth Opportunity',
            self::BusinessRisk      => 'Business Risk',
            self::TechnicalHealth   => 'Technical & Data Health',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::GrowthOpportunity => 'success',
            self::BusinessRisk      => 'warning',
            self::TechnicalHealth   => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GrowthOpportunity => 'heroicon-o-sparkles',
            self::BusinessRisk      => 'heroicon-o-exclamation-triangle',
            self::TechnicalHealth   => 'heroicon-o-wrench-screwdriver',
        };
    }
}
