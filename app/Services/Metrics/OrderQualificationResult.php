<?php

namespace App\Services\Metrics;

/**
 * Immutable evaluation result produced by OrderQualificationPolicy.
 */
final class OrderQualificationResult
{
    public const CLASS_QUALIFIED_PAID                   = 'qualified_paid';
    public const CLASS_QUALIFIED_B2B_INVOICED           = 'qualified_b2b_invoiced';
    public const CLASS_QUALIFIED_B2B_CREDIT_TERM        = 'qualified_b2b_credit_terms';
    public const CLASS_QUALIFIED_PARTIAL                = 'qualified_partial_settlement';
    public const CLASS_QUALIFIED_LEGACY_STATUS          = 'qualified_legacy_status';
    public const CLASS_QUALIFIED_LEGACY_COMPLETED       = 'qualified_legacy_completed';
    public const CLASS_QUALIFIED_SETTLEMENT_UNVERIFIED  = 'qualified_settlement_unverified';
    public const CLASS_PENDING_UNCONFIRMED              = 'pending_or_unconfirmed';
    public const CLASS_EXCLUDED_TERMINAL                = 'excluded_terminal';
    public const CLASS_EXCLUDED_AMBIGUOUS_LEGACY        = 'excluded_ambiguous_legacy';

    public function __construct(
        public readonly bool $isValid,
        public readonly string $classification,
        public readonly ?string $exclusionReason,
        public readonly ?string $orderState,
        public readonly string $orderStatus,
        public readonly ?string $paymentMethod,
        public readonly float $grossRevenue,
        public readonly float $canceledRevenue,
        public readonly float $activeGrossRevenue,
        public readonly float $invoicedRevenue,
        public readonly float $paidRevenue,
        public readonly float $refundedRevenue,
        public readonly float $outstandingReceivable,
        public readonly float $qualifyingGrossRevenue,
        public readonly float $qualifyingNetRevenue,
        public readonly float $unsettledBalance = 0.0,
        public readonly ?string $qualificationReason = null,
        public readonly float $qualifyingOrderValue = 0.0,
        public readonly float $settlementUnverifiedRevenue = 0.0,
        public readonly float $refundableCoreDeposit = 0.0,
        public readonly float $coreDepositRefunded = 0.0,
        public readonly float $outstandingCoreDeposit = 0.0,
        public readonly array $provenance = [],
    ) {}

    public function isSettledInFull(): bool
    {
        return $this->classification === self::CLASS_QUALIFIED_PAID;
    }

    public function isB2bReceivable(): bool
    {
        return $this->outstandingReceivable > 0.0 && $this->isValid;
    }

    public function isSettlementUnverified(): bool
    {
        return $this->isValid && $this->settlementUnverifiedRevenue > 0.0;
    }

    public function toArray(): array
    {
        return [
            'is_valid'                      => $this->isValid,
            'classification'                => $this->classification,
            'qualification_reason'          => $this->qualificationReason,
            'exclusion_reason'              => $this->exclusionReason,
            'order_state'                   => $this->orderState,
            'order_status'                  => $this->orderStatus,
            'payment_method'                => $this->paymentMethod,
            'gross_revenue'                 => $this->grossRevenue,
            'canceled_revenue'              => $this->canceledRevenue,
            'active_gross_revenue'          => $this->activeGrossRevenue,
            'invoiced_revenue'              => $this->invoicedRevenue,
            'paid_revenue'                  => $this->paidRevenue,
            'refunded_revenue'              => $this->refundedRevenue,
            'outstanding_receivable'        => $this->outstandingReceivable,
            'qualifying_gross_revenue'      => $this->qualifyingGrossRevenue,
            'qualifying_order_value'        => $this->qualifyingOrderValue,
            'qualifying_net_revenue'        => $this->qualifyingNetRevenue,
            'settlement_unverified_revenue' => $this->settlementUnverifiedRevenue,
            'refundable_core_deposit'       => $this->refundableCoreDeposit,
            'core_deposit_refunded'         => $this->coreDepositRefunded,
            'outstanding_core_deposit'      => $this->outstandingCoreDeposit,
            'unsettled_balance'             => $this->unsettledBalance,
            'provenance'                    => $this->provenance,
        ];
    }
}
