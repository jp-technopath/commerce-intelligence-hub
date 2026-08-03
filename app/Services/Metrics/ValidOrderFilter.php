<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\Integration;

/**
 * ValidOrderFilter
 *
 * Centralized service evaluating order status eligibility and exclusions
 * according to client and integration configuration settings.
 */
class ValidOrderFilter
{
    public const DEFAULT_ELIGIBLE_STATUSES = ['complete', 'processing'];
    public const DEFAULT_EXCLUDED_STATUSES = ['canceled', 'closed', 'holded', 'fraud', 'test', 'duplicate'];

    /**
     * Get eligible order statuses for a client or integration.
     */
    public function getEligibleStatuses(Client|Integration|null $context = null): array
    {
        if (! $context) {
            return self::DEFAULT_ELIGIBLE_STATUSES;
        }

        $config = match (true) {
            $context instanceof Client      => $context->monitoring_config['order_settings'] ?? [],
            $context instanceof Integration => $context->monitoring_config['order_settings'] ?? $context->client->monitoring_config['order_settings'] ?? [],
            default                          => [],
        };

        return array_map('strtolower', $config['eligible_statuses'] ?? self::DEFAULT_ELIGIBLE_STATUSES);
    }

    /**
     * Get excluded order statuses for a client or integration.
     */
    public function getExcludedStatuses(Client|Integration|null $context = null): array
    {
        if (! $context) {
            return self::DEFAULT_EXCLUDED_STATUSES;
        }

        $config = match (true) {
            $context instanceof Client      => $context->monitoring_config['order_settings'] ?? [],
            $context instanceof Integration => $context->monitoring_config['order_settings'] ?? $context->client->monitoring_config['order_settings'] ?? [],
            default                          => [],
        };

        return array_map('strtolower', $config['excluded_statuses'] ?? self::DEFAULT_EXCLUDED_STATUSES);
    }

    /**
     * Determine if an order is valid given its status and flags.
     *
     * @param  array{status?: string, is_test?: bool, is_fraud?: bool, is_duplicate?: bool, is_fully_refunded?: bool}  $order
     */
    public function isValidOrder(array $order, Client|Integration|null $context = null): bool
    {
        return $this->getExclusionReason($order, $context) === null;
    }

    /**
     * Return the exclusion reason string if an order is invalid, or null if valid.
     */
    public function getExclusionReason(array $order, Client|Integration|null $context = null): ?string
    {
        if (! empty($order['is_test'])) {
            return 'test_order';
        }

        if (! empty($order['is_fraud'])) {
            return 'fraudulent_order';
        }

        if (! empty($order['is_duplicate'])) {
            return 'duplicate_order';
        }

        if (! empty($order['is_fully_refunded'])) {
            return 'fully_refunded';
        }

        $status           = strtolower($order['status'] ?? 'unknown');
        $eligibleStatuses = $this->getEligibleStatuses($context);
        $excludedStatuses = $this->getExcludedStatuses($context);

        if (in_array($status, $excludedStatuses, true)) {
            return 'status_excluded:' . $status;
        }

        if (! in_array($status, $eligibleStatuses, true)) {
            return 'status_not_eligible:' . $status;
        }

        return null;
    }
}
