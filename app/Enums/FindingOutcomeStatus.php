<?php

namespace App\Enums;

enum FindingOutcomeStatus: string
{
    case PendingExecution = 'pending_execution';
    case AwaitingResults  = 'awaiting_results';
    case OutcomeMeasured  = 'outcome_measured';
    case NoOrdersObserved = 'no_orders_observed';
    case Dismissed        = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::PendingExecution => 'Pending Execution',
            self::AwaitingResults  => 'Awaiting Results',
            self::OutcomeMeasured  => 'Outcome Measured',
            self::NoOrdersObserved => 'No Orders Observed',
            self::Dismissed        => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingExecution => 'gray',
            self::AwaitingResults  => 'warning',
            self::OutcomeMeasured  => 'success',
            self::NoOrdersObserved => 'danger',
            self::Dismissed        => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PendingExecution => 'heroicon-o-clock',
            self::AwaitingResults  => 'heroicon-o-arrow-path',
            self::OutcomeMeasured  => 'heroicon-o-banknotes',
            self::NoOrdersObserved => 'heroicon-o-x-circle',
            self::Dismissed        => 'heroicon-o-no-symbol',
        };
    }
}
