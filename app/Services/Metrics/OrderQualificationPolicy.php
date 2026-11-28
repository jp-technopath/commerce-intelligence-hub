<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\Integration;

/**
 * Reusable, client-scoped order qualification policy combining Magento system state,
 * invoice/payment settlement evidence, credit-term rules, and status workflows.
 * Never treats GA4 purchase events as proof of commercial settlement.
 */
class OrderQualificationPolicy
{
    public const DEFAULT_ELIGIBLE_STATUSES = ['complete', 'processing'];
    public const DEFAULT_EXCLUDED_STATUSES = ['canceled', 'closed', 'holded', 'fraud', 'test', 'duplicate', 'payment_review'];

    public const EXCLUDED_STATES = ['canceled', 'closed', 'holded', 'payment_review', 'fraud'];
    public const PENDING_STATES  = ['new', 'pending_payment'];
    public const ACTIVE_STATES   = ['complete', 'processing'];

    public const DEFAULT_B2B_CREDIT_TERM_METHODS = [
        'purchaseorder',
        'checkmo',
        'banktransfer',
        'stripe_payments_invoice',
        'net30',
        'invoice',
    ];

    public const EPSILON = 0.01;

    /**
     * Resolve client/integration order_settings from monitoring_config.
     */
    public function resolveOrderSettings(Client|Integration|null $context = null): array
    {
        if (! $context) {
            return [];
        }

        return match (true) {
            $context instanceof Client      => $context->monitoring_config['order_settings'] ?? [],
            $context instanceof Integration => $context->monitoring_config['order_settings'] ?? ($context->client?->monitoring_config['order_settings'] ?? []),
            default                         => [],
        };
    }

    public function getEligibleStatuses(Client|Integration|null $context = null): array
    {
        $config = $this->resolveOrderSettings($context);

        return array_values(array_unique(array_map(
            'strtolower',
            $config['eligible_statuses'] ?? self::DEFAULT_ELIGIBLE_STATUSES
        )));
    }

    public function getExcludedStatuses(Client|Integration|null $context = null): array
    {
        $config = $this->resolveOrderSettings($context);

        return array_values(array_unique(array_map(
            'strtolower',
            $config['excluded_statuses'] ?? self::DEFAULT_EXCLUDED_STATUSES
        )));
    }

    public function evaluate(array $order, Client|Integration|null $context = null): OrderQualificationResult
    {
        $config = $this->resolveOrderSettings($context);
        $status = strtolower(trim((string) ($order['status'] ?? $order['order_status'] ?? 'unknown'))) ?: 'unknown';
        $state  = isset($order['state']) && $order['state'] !== ''
            ? strtolower(trim((string) $order['state']))
            : (isset($order['order_state']) && $order['order_state'] !== '' ? strtolower(trim((string) $order['order_state'])) : null);

        $paymentMethod = $order['payment_method']
            ?? ($order['payment']['method'] ?? null);
        $paymentMethod = is_string($paymentMethod) && trim($paymentMethod) !== ''
            ? strtolower(trim($paymentMethod))
            : null;

        $gross      = $this->money($order['base_grand_total'] ?? $order['gross_revenue'] ?? $order['grand_total'] ?? 0.0);
        $refunded   = $this->money($order['base_total_refunded'] ?? $order['refunded_revenue'] ?? $order['total_refunded'] ?? 0.0);
        $canceled   = $this->money($order['base_total_canceled'] ?? $order['canceled_revenue'] ?? $order['total_canceled'] ?? 0.0);
        $invoiced   = $this->money($order['base_total_invoiced'] ?? $order['invoiced_revenue'] ?? $order['total_invoiced'] ?? 0.0);
        $paid       = $this->money($order['base_total_paid'] ?? $order['paid_revenue'] ?? $order['total_paid'] ?? ($order['payment']['base_amount_paid'] ?? 0.0));
        $authorized = $this->money($order['base_amount_authorized'] ?? ($order['payment']['base_amount_authorized'] ?? 0.0));

        $activeGross = max(0.0, round($gross - $canceled, 2));
        $config      = $this->resolveOrderSettings($context);
        $coreMetrics = $this->extractCoreDepositMetrics($order, $config, $activeGross, $refunded);

        // 1. Explicit boolean & terminal exclusions
        if (! empty($order['is_test'])) {
            return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_TERMINAL, 'test_order', $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }
        if (! empty($order['is_fraud'])) {
            return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_TERMINAL, 'fraudulent_order', $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }
        if (! empty($order['is_duplicate'])) {
            return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_TERMINAL, 'duplicate_order', $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }
        $isFullyRefunded = ! empty($order['is_fully_refunded']) || ($activeGross > 0.0 && $refunded >= ($activeGross - self::EPSILON));
        if ($isFullyRefunded) {
            return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_TERMINAL, 'fully_refunded', $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }
        if ($gross > 0.0 && $canceled >= ($gross - self::EPSILON)) {
            return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_TERMINAL, 'fully_canceled', $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }

        // 2. Status-level and system-state exclusions
        $excludedStatuses = $this->getExcludedStatuses($context);
        $eligibleStatuses = $this->getEligibleStatuses($context);

        if (in_array($status, $excludedStatuses, true)) {
            return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_TERMINAL, 'status_excluded:' . $status, $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }

        if ($state !== null && in_array($state, self::EXCLUDED_STATES, true)) {
            return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_TERMINAL, 'state_excluded:' . $state, $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }

        $creditTermMethods = array_map(
            'strtolower',
            $config['b2b_credit_term_methods'] ?? self::DEFAULT_B2B_CREDIT_TERM_METHODS
        );
        $b2bAuthorizedStatuses = array_map(
            'strtolower',
            $config['b2b_authorized_statuses'] ?? ['confirmed']
        );
        $allowAuthorizedB2b    = (bool) ($config['allow_authorized_b2b_confirmed'] ?? false);
        $allowInvoicedPending  = (bool) ($config['allow_invoiced_pending_payment'] ?? false);
        $suConfig              = is_array($config['settlement_unverified_workflows'] ?? null) ? $config['settlement_unverified_workflows'] : [];
        $lcConfig              = is_array($config['legacy_completed_workflows'] ?? null) ? $config['legacy_completed_workflows'] : [];

        // 3. Pending / Unconfirmed states (e.g. Magento state=new or state=pending_payment)
        if ($state !== null && in_array($state, self::PENDING_STATES, true)) {
            $isQualifiedPendingInvoice = $allowInvoicedPending
                && $activeGross > 0.0
                && $invoiced >= ($activeGross - self::EPSILON)
                && $paymentMethod !== null
                && in_array($paymentMethod, $creditTermMethods, true);

            if ($isQualifiedPendingInvoice) {
                $paidNet      = max(0.0, round(min($paid, $activeGross) - $refunded, 2));
                $invoicedNet  = max(0.0, round(min($invoiced, $activeGross) - $refunded, 2));
                $qualOrderVal = max(0.0, round($activeGross - $refunded, 2));
                $receivable   = max(0.0, round($qualOrderVal - $paidNet, 2));

                return $this->buildValidResult(
                    classification: OrderQualificationResult::CLASS_QUALIFIED_B2B_INVOICED,
                    qualificationReason: 'b2b_invoiced_receivable',
                    orderState: $state,
                    orderStatus: $status,
                    paymentMethod: $paymentMethod,
                    gross: $gross,
                    canceled: $canceled,
                    activeGross: $activeGross,
                    invoicedNet: $invoicedNet,
                    paidNet: $paidNet,
                    refunded: $refunded,
                    receivable: $receivable,
                    qualifyingGross: $activeGross,
                    qualifyingOrderValue: $qualOrderVal,
                    coreMetrics: $coreMetrics,
                    config: $config,
                );
            }

            return $this->invalidResult(OrderQualificationResult::CLASS_PENDING_UNCONFIRMED, 'state_pending:' . $state, $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }

        // 4. Minimal status-only payload without state or settlement telemetry (backward-compatible callers & tests)
        $hasFinancialTelemetry = array_key_exists('base_total_invoiced', $order)
            || array_key_exists('invoiced_revenue', $order)
            || array_key_exists('base_total_paid', $order)
            || array_key_exists('paid_revenue', $order)
            || array_key_exists('state', $order)
            || array_key_exists('order_state', $order);

        if (! $hasFinancialTelemetry) {
            if (! in_array($status, $eligibleStatuses, true)) {
                return $this->invalidResult(OrderQualificationResult::CLASS_PENDING_UNCONFIRMED, 'status_not_eligible:' . $status, $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
            }

            $qualOrderVal = max(0.0, round($activeGross - $refunded, 2));
            return $this->buildValidResult(
                classification: OrderQualificationResult::CLASS_QUALIFIED_LEGACY_STATUS,
                qualificationReason: 'legacy_status_only',
                orderState: null,
                orderStatus: $status,
                paymentMethod: $paymentMethod,
                gross: $gross,
                canceled: $canceled,
                activeGross: $activeGross,
                invoicedNet: $invoiced,
                paidNet: $paid,
                refunded: $refunded,
                receivable: 0.0,
                qualifyingGross: $activeGross,
                qualifyingOrderValue: $qualOrderVal,
                coreMetrics: $coreMetrics,
                config: $config,
            );
        }

        // 5. Active Magento states (complete / processing, or null state with settlement telemetry)
        $isStatusExplicitlyEligible = in_array($status, $eligibleStatuses, true);
        $isSettlementUnverifiedStatusCandidate = ! empty($suConfig['enabled'])
            && $paymentMethod !== null
            && in_array($paymentMethod, array_map('strtolower', $suConfig['payment_methods'] ?? []), true)
            && in_array($status, array_map('strtolower', $suConfig['allowed_statuses'] ?? []), true);

        if (! $isStatusExplicitlyEligible && $state !== 'complete' && ! $isSettlementUnverifiedStatusCandidate) {
            return $this->invalidResult(OrderQualificationResult::CLASS_PENDING_UNCONFIRMED, 'status_not_eligible:' . $status, $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
        }

        // 5a. Zero-value active order (e.g., 100% discounted/warranty replacement) — reject ambiguous empty $0 order with non-zero card authorization
        if ($activeGross <= 0.0) {
            if ($authorized > self::EPSILON && $paid <= self::EPSILON && $invoiced <= self::EPSILON) {
                return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_AMBIGUOUS_LEGACY, 'ambiguous_zero_total_with_authorization', $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
            }

            return $this->buildValidResult(
                classification: OrderQualificationResult::CLASS_QUALIFIED_PAID,
                qualificationReason: 'settled_paid',
                orderState: $state,
                orderStatus: $status,
                paymentMethod: $paymentMethod,
                gross: $gross,
                canceled: $canceled,
                activeGross: 0.0,
                invoicedNet: 0.0,
                paidNet: 0.0,
                refunded: $refunded,
                receivable: 0.0,
                qualifyingGross: 0.0,
                qualifyingOrderValue: 0.0,
                coreMetrics: $coreMetrics,
                config: $config,
            );
        }

        // 5b. Fully paid commercial order
        if ($paid >= ($activeGross - self::EPSILON)) {
            $qualOrderVal = max(0.0, round($activeGross - $refunded, 2));
            $paidNet      = max(0.0, round(min($paid, $activeGross) - $refunded, 2));
            $invoicedNet  = max(0.0, round(min(max($invoiced, $paid), $activeGross) - $refunded, 2));

            return $this->buildValidResult(
                classification: OrderQualificationResult::CLASS_QUALIFIED_PAID,
                qualificationReason: 'settled_paid',
                orderState: $state,
                orderStatus: $status,
                paymentMethod: $paymentMethod,
                gross: $gross,
                canceled: $canceled,
                activeGross: $activeGross,
                invoicedNet: $invoicedNet,
                paidNet: $paidNet,
                refunded: $refunded,
                receivable: 0.0,
                qualifyingGross: $activeGross,
                qualifyingOrderValue: $qualOrderVal,
                coreMetrics: $coreMetrics,
                config: $config,
            );
        }

        // 5c. Fully invoiced B2B order with unpaid receivable balance
        if ($invoiced >= ($activeGross - self::EPSILON)) {
            $qualOrderVal = max(0.0, round($activeGross - $refunded, 2));
            $paidNet      = max(0.0, round($paid - min($paid, $refunded), 2));
            $invoicedNet  = max(0.0, round(min($invoiced, $activeGross) - $refunded, 2));
            $receivable   = max(0.0, round($qualOrderVal - $paidNet, 2));

            return $this->buildValidResult(
                classification: OrderQualificationResult::CLASS_QUALIFIED_B2B_INVOICED,
                qualificationReason: 'b2b_invoiced_receivable',
                orderState: $state,
                orderStatus: $status,
                paymentMethod: $paymentMethod,
                gross: $gross,
                canceled: $canceled,
                activeGross: $activeGross,
                invoicedNet: $invoicedNet,
                paidNet: $paidNet,
                refunded: $refunded,
                receivable: $receivable,
                qualifyingGross: $activeGross,
                qualifyingOrderValue: $qualOrderVal,
                coreMetrics: $coreMetrics,
                config: $config,
            );
        }

        // 5d. Partial settlement / partial invoice — strictly cap qualifying amount to settled portion, NEVER full gross
        $settledCap = max($paid, $invoiced);
        if ($settledCap > self::EPSILON && $settledCap < ($activeGross - self::EPSILON)) {
            $qualGross    = round(min($settledCap, $activeGross), 2);
            $qualOrderVal = max(0.0, round($qualGross - $refunded, 2));
            $paidNet      = max(0.0, round($paid - min($paid, $refunded), 2));
            $invoicedNet  = max(0.0, round($invoiced - min($invoiced, $refunded), 2));
            $receivable   = max(0.0, round($invoicedNet - $paidNet, 2));
            $unsettled    = max(0.0, round($activeGross - $qualGross, 2));

            if ($qualOrderVal <= 0.0) {
                return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_TERMINAL, 'partial_settlement_refunded', $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
            }

            return $this->buildValidResult(
                classification: OrderQualificationResult::CLASS_QUALIFIED_PARTIAL,
                qualificationReason: 'partial_settlement',
                orderState: $state,
                orderStatus: $status,
                paymentMethod: $paymentMethod,
                gross: $gross,
                canceled: $canceled,
                activeGross: $activeGross,
                invoicedNet: $invoicedNet,
                paidNet: $paidNet,
                refunded: $refunded,
                receivable: $receivable,
                qualifyingGross: $qualGross,
                qualifyingOrderValue: $qualOrderVal,
                coreMetrics: $coreMetrics,
                config: $config,
                unsettledBalance: $unsettled,
            );
        }

        // 5e. Uninvoiced & unpaid active order (e.g., B2B net terms OR full-amount authorized confirmed workflow)
        $isCreditTermMethod = $paymentMethod !== null && in_array($paymentMethod, $creditTermMethods, true);
        $isAuthorizedFullB2b = $allowAuthorizedB2b
            && in_array($status, $b2bAuthorizedStatuses, true)
            && $authorized >= ($activeGross - self::EPSILON);

        if ($isCreditTermMethod || $isAuthorizedFullB2b) {
            $qualOrderVal = max(0.0, round($activeGross - $refunded, 2));
            return $this->buildValidResult(
                classification: OrderQualificationResult::CLASS_QUALIFIED_B2B_CREDIT_TERM,
                qualificationReason: 'b2b_credit_terms',
                orderState: $state,
                orderStatus: $status,
                paymentMethod: $paymentMethod,
                gross: $gross,
                canceled: $canceled,
                activeGross: $activeGross,
                invoicedNet: 0.0,
                paidNet: 0.0,
                refunded: $refunded,
                receivable: $qualOrderVal,
                qualifyingGross: $activeGross,
                qualifyingOrderValue: $qualOrderVal,
                coreMetrics: $coreMetrics,
                config: $config,
            );
        }

        // 5f. Client-scoped settlement-unverified workflow (e.g., active PayPal orders pending invoice/payment sync)
        $shippedQty = $this->resolveShippedQuantity($order);
        if ($isSettlementUnverifiedStatusCandidate
            && ($state === null || in_array($state, array_map('strtolower', $suConfig['allowed_states'] ?? ['processing', 'complete']), true))
            && ($authorized > self::EPSILON || $shippedQty > self::EPSILON)
        ) {
            $qualOrderVal   = max(0.0, round($activeGross - $refunded, 2));
            $hasExpiredVoid = $this->hasExpiredVoidHistory($order);
            $qualReason     = $shippedQty > self::EPSILON
                ? 'paypal_settlement_unverified_shipped'
                : 'paypal_settlement_unverified_pending_fulfillment';

            return $this->buildValidResult(
                classification: OrderQualificationResult::CLASS_QUALIFIED_SETTLEMENT_UNVERIFIED,
                qualificationReason: $qualReason,
                orderState: $state,
                orderStatus: $status,
                paymentMethod: $paymentMethod,
                gross: $gross,
                canceled: $canceled,
                activeGross: $activeGross,
                invoicedNet: 0.0,
                paidNet: 0.0,
                refunded: $refunded,
                receivable: 0.0,
                qualifyingGross: $activeGross,
                qualifyingOrderValue: $qualOrderVal,
                coreMetrics: $coreMetrics,
                config: $config,
                settlementUnverifiedRevenue: $qualOrderVal,
                provenance: [
                    'workflow'          => 'paypal_settlement_unverified',
                    'shipped_qty'       => $shippedQty,
                    'authorized_amount' => $authorized,
                    'ipn_expired_void'  => $hasExpiredVoid,
                ],
            );
        }

        // 5g. Client-scoped legacy pre-cutoff completed workflow (Modified Option 1B — strictly bounded by cutoff_before)
        if (! empty($lcConfig['enabled'])) {
            $createdAtStr = (string) ($order['created_at'] ?? $order['order_date'] ?? '');
            $cutoff       = (string) ($lcConfig['cutoff_before'] ?? '2023-01-01');
            $reqStatus    = strtolower((string) ($lcConfig['required_status'] ?? 'complete'));
            $allowedSt    = array_map('strtolower', $lcConfig['allowed_states'] ?? ['processing', 'complete']);
            $allowedPms   = array_map('strtolower', $lcConfig['allowed_payment_methods'] ?? []);

            if ($createdAtStr !== ''
                && substr($createdAtStr, 0, 10) < $cutoff
                && $status === $reqStatus
                && $state !== null
                && in_array($state, $allowedSt, true)
                && $paymentMethod !== null
                && in_array($paymentMethod, $allowedPms, true)
            ) {
                $transId = $order['payment']['last_trans_id'] ?? $order['payment']['cc_trans_id'] ?? null;
                $hasCommercialEvidence = $authorized > self::EPSILON || $shippedQty > self::EPSILON || ! empty($transId);

                if (! $hasCommercialEvidence) {
                    return $this->invalidResult(OrderQualificationResult::CLASS_EXCLUDED_AMBIGUOUS_LEGACY, 'unsupported_legacy_complete', $state, $status, $paymentMethod, $gross, $canceled, $activeGross, $invoiced, $paid, $refunded, $coreMetrics);
                }

                $qualOrderVal = max(0.0, round($activeGross - $refunded, 2));
                return $this->buildValidResult(
                    classification: OrderQualificationResult::CLASS_QUALIFIED_LEGACY_COMPLETED,
                    qualificationReason: 'legacy_pre2023_authorizenet_completed',
                    orderState: $state,
                    orderStatus: $status,
                    paymentMethod: $paymentMethod,
                    gross: $gross,
                    canceled: $canceled,
                    activeGross: $activeGross,
                    invoicedNet: 0.0,
                    paidNet: 0.0,
                    refunded: $refunded,
                    receivable: 0.0,
                    qualifyingGross: $activeGross,
                    qualifyingOrderValue: $qualOrderVal,
                    coreMetrics: $coreMetrics,
                    config: $config,
                    settlementUnverifiedRevenue: $qualOrderVal,
                    provenance: [
                        'workflow'          => 'pre2023_authorizenet_completed',
                        'cutoff_before'     => $cutoff,
                        'authorized_amount' => $authorized,
                        'shipped_qty'       => $shippedQty,
                        'transaction_id'    => $transId ? true : false,
                    ],
                );
            }
        }

        // Authorized-only, under-authorized, or uninvoiced/unpaid active order without credit-term qualification
        $exclusionReason = match (true) {
            $authorized >= ($activeGross - self::EPSILON) => 'unsettled_authorized_only',
            $authorized > 0.0                             => 'unsettled_under_authorized',
            default                                       => 'unsettled_uninvoiced_unpaid',
        };

        return $this->invalidResult(
            OrderQualificationResult::CLASS_PENDING_UNCONFIRMED,
            $exclusionReason,
            $state,
            $status,
            $paymentMethod,
            $gross,
            $canceled,
            $activeGross,
            $invoiced,
            $paid,
            $refunded,
            $coreMetrics
        );
    }

    /**
     * @param array{refundable_core_deposit: float, core_deposit_refunded: float, outstanding_core_deposit: float} $coreMetrics
     */
    private function buildValidResult(
        string $classification,
        string $qualificationReason,
        ?string $orderState,
        string $orderStatus,
        ?string $paymentMethod,
        float $gross,
        float $canceled,
        float $activeGross,
        float $invoicedNet,
        float $paidNet,
        float $refunded,
        float $receivable,
        float $qualifyingGross,
        float $qualifyingOrderValue,
        array $coreMetrics,
        array $config,
        float $unsettledBalance = 0.0,
        float $settlementUnverifiedRevenue = 0.0,
        array $provenance = [],
    ): OrderQualificationResult {
        $outstandingDeposit = $coreMetrics['outstanding_core_deposit'];
        $deductOutstanding = (bool) ($config['core_deposit_settings']['deduct_outstanding_from_merchandise_revenue'] ?? false);

        // Prevent double deduction: posted credit-memo refunds are already deducted inside $qualifyingOrderValue;
        // only remaining unrefunded/unreturned deposits ($outstandingDeposit) are excluded from net merchandise revenue.
        $qualifyingNet = ($deductOutstanding && $outstandingDeposit > 0.0)
            ? max(0.0, round($qualifyingOrderValue - $outstandingDeposit, 2))
            : $qualifyingOrderValue;

        if ($settlementUnverifiedRevenue > 0.0) {
            $settlementUnverifiedRevenue = $qualifyingNet;
        }

        return new OrderQualificationResult(
            isValid: true,
            classification: $classification,
            exclusionReason: null,
            orderState: $orderState,
            orderStatus: $orderStatus,
            paymentMethod: $paymentMethod,
            grossRevenue: $gross,
            canceledRevenue: $canceled,
            activeGrossRevenue: $activeGross,
            invoicedRevenue: $invoicedNet,
            paidRevenue: $paidNet,
            refundedRevenue: $refunded,
            outstandingReceivable: $receivable,
            qualifyingGrossRevenue: $qualifyingGross,
            qualifyingNetRevenue: $qualifyingNet,
            unsettledBalance: $unsettledBalance,
            qualificationReason: $qualificationReason,
            qualifyingOrderValue: $qualifyingOrderValue,
            settlementUnverifiedRevenue: $settlementUnverifiedRevenue,
            refundableCoreDeposit: $coreMetrics['refundable_core_deposit'],
            coreDepositRefunded: $coreMetrics['core_deposit_refunded'],
            outstandingCoreDeposit: $outstandingDeposit,
            provenance: $provenance,
        );
    }

    /**
     * @param array{refundable_core_deposit: float, core_deposit_refunded: float, outstanding_core_deposit: float} $coreMetrics
     */
    private function invalidResult(
        string $classification,
        string $reason,
        ?string $state,
        string $status,
        ?string $paymentMethod,
        float $gross,
        float $canceled,
        float $activeGross,
        float $invoiced,
        float $paid,
        float $refunded,
        array $coreMetrics = ['refundable_core_deposit' => 0.0, 'core_deposit_refunded' => 0.0, 'outstanding_core_deposit' => 0.0]
    ): OrderQualificationResult {
        return new OrderQualificationResult(
            isValid: false,
            classification: $classification,
            exclusionReason: $reason,
            orderState: $state,
            orderStatus: $status,
            paymentMethod: $paymentMethod,
            grossRevenue: $gross,
            canceledRevenue: $canceled,
            activeGrossRevenue: $activeGross,
            invoicedRevenue: $invoiced,
            paidRevenue: $paid,
            refundedRevenue: $refunded,
            outstandingReceivable: 0.0,
            qualifyingGrossRevenue: 0.0,
            qualifyingNetRevenue: 0.0,
            unsettledBalance: 0.0,
            qualificationReason: $reason,
            qualifyingOrderValue: 0.0,
            settlementUnverifiedRevenue: 0.0,
            refundableCoreDeposit: $coreMetrics['refundable_core_deposit'],
            coreDepositRefunded: $coreMetrics['core_deposit_refunded'],
            outstandingCoreDeposit: 0.0,
            provenance: [],
        );
    }

    /**
     * Decompose refundable core deposits (e.g. Fooman Surcharge), credit-memo deposit refunds,
     * adjustment fees retained by the merchant, and unrefunded outstanding refundable deposits.
     *
     * @return array{refundable_core_deposit: float, core_deposit_refunded: float, outstanding_core_deposit: float}
     */
    private function extractCoreDepositMetrics(array $order, array $config, float $activeGross, float $refunded): array
    {
        $cdConfig = $config['core_deposit_settings'] ?? null;
        if (! is_array($cdConfig) || empty($cdConfig['enabled'])) {
            return ['refundable_core_deposit' => 0.0, 'core_deposit_refunded' => 0.0, 'outstanding_core_deposit' => 0.0];
        }

        $coreDeposit = 0.0;
        if (array_key_exists('refundable_core_deposit', $order) && $order['refundable_core_deposit'] !== null) {
            $coreDeposit = $this->money($order['refundable_core_deposit']);
        } else {
            $codes  = array_map('strtolower', $cdConfig['surcharge_codes'] ?? ['fooman_surcharge']);
            $labels = array_map('strtolower', $cdConfig['surcharge_labels'] ?? ['refundable deposit']);
            foreach (($order['extension_attributes']['fooman_total_group']['items'] ?? []) as $fi) {
                $code  = strtolower((string) ($fi['code'] ?? ''));
                $label = strtolower((string) ($fi['label'] ?? ''));
                $matchesCode  = $code !== '' && in_array($code, $codes, true);
                $matchesLabel = false;
                foreach ($labels as $lbl) {
                    if ($lbl !== '' && str_contains($label, $lbl)) {
                        $matchesLabel = true;
                        break;
                    }
                }
                if ($matchesCode || $matchesLabel) {
                    $coreDeposit = round($coreDeposit + $this->money($fi['base_amount'] ?? $fi['amount'] ?? 0.0), 2);
                }
            }
        }

        if ($coreDeposit <= 0.0) {
            return ['refundable_core_deposit' => 0.0, 'core_deposit_refunded' => 0.0, 'outstanding_core_deposit' => 0.0];
        }

        if ((! isset($order['items']) || ! is_array($order['items']))
            && array_key_exists('core_deposit_refunded', $order)
            && $order['core_deposit_refunded'] !== null
        ) {
            $coreRefunded = $this->money($order['core_deposit_refunded']);
        } else {
            $merchRefund = (float) ($order['base_shipping_refunded'] ?? 0.0);
            foreach (($order['items'] ?? []) as $it) {
                if (! empty($it['parent_item_id'])) {
                    continue;
                }
                $merchRefund += (float) ($it['base_amount_refunded'] ?? 0.0)
                    + (float) ($it['base_tax_refunded'] ?? 0.0)
                    - (float) ($it['base_discount_refunded'] ?? 0.0);
            }
            $merchRefundCap = max(0.0, round($activeGross - $coreDeposit, 2));
            $merchRefund    = max(0.0, min(round($merchRefund, 2), $merchRefundCap));
            $coreRefunded   = round(min($coreDeposit, max(0.0, $refunded - $merchRefund)), 2);
        }

        $adjNeg      = $this->money($order['base_adjustment_negative'] ?? 0.0);
        $settledCore = min($coreDeposit, round($coreRefunded + $adjNeg, 2));
        $outstanding = max(0.0, round($coreDeposit - $settledCore, 2));

        return [
            'refundable_core_deposit'  => $coreDeposit,
            'core_deposit_refunded'    => $coreRefunded,
            'outstanding_core_deposit' => $outstanding,
        ];
    }

    private function resolveShippedQuantity(array $order): float
    {
        if (array_key_exists('shipped_qty', $order) && $order['shipped_qty'] !== null) {
            return (float) $order['shipped_qty'];
        }

        $shipped = 0.0;
        foreach (($order['items'] ?? []) as $it) {
            if (! empty($it['parent_item_id'])) {
                continue;
            }
            $shipped += (float) ($it['qty_shipped'] ?? 0.0);
        }

        return $shipped;
    }

    private function hasExpiredVoidHistory(array $order): bool
    {
        foreach (($order['status_histories'] ?? []) as $h) {
            $comment = strtolower((string) ($h['comment'] ?? ''));
            if (str_contains($comment, 'expired') && str_contains($comment, 'void')) {
                return true;
            }
        }

        return false;
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0.0), 2);
    }
}
