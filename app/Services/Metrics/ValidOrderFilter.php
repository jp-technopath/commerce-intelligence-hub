<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\Integration;

/**
 * ValidOrderFilter
 *
 * Centralized service evaluating order status and settlement eligibility
 * according to client and integration configuration settings.
 */
class ValidOrderFilter
{
    public const DEFAULT_ELIGIBLE_STATUSES = OrderQualificationPolicy::DEFAULT_ELIGIBLE_STATUSES;
    public const DEFAULT_EXCLUDED_STATUSES = OrderQualificationPolicy::DEFAULT_EXCLUDED_STATUSES;

    private OrderQualificationPolicy $policy;

    public function __construct(?OrderQualificationPolicy $policy = null)
    {
        $this->policy = $policy ?? new OrderQualificationPolicy();
    }

    /**
     * Evaluate full order qualification and financial classification.
     */
    public function evaluate(array $order, Client|Integration|null $context = null): OrderQualificationResult
    {
        return $this->policy->evaluate($order, $context);
    }

    /**
     * Get eligible order statuses for a client or integration.
     */
    public function getEligibleStatuses(Client|Integration|null $context = null): array
    {
        return $this->policy->getEligibleStatuses($context);
    }

    /**
     * Get excluded order statuses for a client or integration.
     */
    public function getExcludedStatuses(Client|Integration|null $context = null): array
    {
        return $this->policy->getExcludedStatuses($context);
    }

    /**
     * Determine if an order is valid given its state, status, settlement, and flags.
     *
     * @param  array<string, mixed>  $order
     */
    public function isValidOrder(array $order, Client|Integration|null $context = null): bool
    {
        return $this->policy->evaluate($order, $context)->isValid;
    }

    /**
     * Return the exclusion reason string if an order is invalid, or null if valid.
     */
    public function getExclusionReason(array $order, Client|Integration|null $context = null): ?string
    {
        return $this->policy->evaluate($order, $context)->exclusionReason;
    }

    /**
     * Calculate net revenue ensuring non-negative value after refunds.
     */
    public function calculateNetRevenue(float $grossRevenue, float $refundedRevenue = 0.0): float
    {
        return max(0.0, round($grossRevenue - $refundedRevenue, 2));
    }
}
