<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\CommerceMetric;

/**
 * Metrics Aggregator — Phase 3
 *
 * Responsible for aggregating raw connector data into daily CommerceMetric snapshots.
 * Called by each connector after data is retrieved.
 *
 * Handles:
 *   - Upsert of daily commerce metric records
 *   - Calculation of derived metrics (AOV from revenue/orders)
 *   - Aggregation of source and device breakdowns
 */
class MetricsAggregator
{
    public function aggregateCommerce(Client $client, array $rawData, \DateTimeInterface $date): CommerceMetric
    {
        // TODO: Phase 3 — implement commerce metric aggregation
        throw new \RuntimeException('MetricsAggregator not yet implemented. Scheduled for Phase 3.');
    }
}
