<?php

namespace App\Enums;

enum ImpactNature: string
{
    case ObservedSpendingDecline = 'observed_spending_decline';
    case HistoricalReorderValue  = 'historical_reorder_value';
    case ModeledOpportunity      = 'modeled_opportunity';
    case VerifiedFinancialLoss   = 'verified_financial_loss';
    case DirectionalOpportunity  = 'directional_opportunity';

    public function label(): string
    {
        return match ($this) {
            self::ObservedSpendingDecline => 'Observed Spending Decline',
            self::HistoricalReorderValue  => 'Historical Reorder Value',
            self::ModeledOpportunity      => 'Modeled Incremental Opportunity',
            self::VerifiedFinancialLoss   => 'Verified Financial Loss',
            self::DirectionalOpportunity  => 'Directional Opportunity',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ObservedSpendingDecline => 'Observed drop in historical quarterly spend run-rate; reflects purchasing velocity risk, not realized loss.',
            self::HistoricalReorderValue  => 'Historical average order value or replenishment basket size; benchmark for outreach, not guaranteed revenue.',
            self::ModeledOpportunity      => 'Statistically modeled revenue recapture potential based on historical replenishment probabilities.',
            self::VerifiedFinancialLoss   => 'Confirmed financial deficit resulting from formal contract cancellation or account termination.',
            self::DirectionalOpportunity  => 'Directional indicator of commercial potential where precise order history is limited.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ObservedSpendingDecline => 'danger',
            self::HistoricalReorderValue  => 'info',
            self::ModeledOpportunity      => 'success',
            self::VerifiedFinancialLoss   => 'danger',
            self::DirectionalOpportunity  => 'gray',
        };
    }
}
