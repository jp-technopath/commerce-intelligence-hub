<?php

namespace App\Services\Metrics;

use App\Models\AnalyticsPurchaseEvent;
use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\MetricReconciliationResult;
use Carbon\Carbon;

/**
 * RevenueReconciler
 *
 * Compares Adobe Net Valid Revenue against GA4 Tracked Purchase Revenue,
 * matches transaction IDs, and evaluates dual discrepancy thresholds (default: 5% & $250).
 */
class RevenueReconciler
{
    /**
     * Reconcile revenue for a client over a given period.
     */
    public function reconcile(Client $client, Carbon $from, Carbon $to): MetricReconciliationResult
    {
        // 1. Adobe orders
        $adobeOrders = CommerceOrder::where('client_id', $client->id)
            ->where('is_valid', true)
            ->whereBetween('order_date', [$from, $to])
            ->get();

        $adobeTxCount   = $adobeOrders->count();
        $adobeNetRev    = (float) $adobeOrders->sum('net_revenue');
        $adobeOrderIds  = $adobeOrders->pluck('source_order_id')->filter()->toArray();
        $adobeIncrIds   = $adobeOrders->pluck('source_increment_id')->filter()->toArray();
        $adobeLookup    = array_flip(array_merge($adobeOrderIds, $adobeIncrIds));

        // 2. GA4 purchase events
        $ga4Events = AnalyticsPurchaseEvent::where('client_id', $client->id)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $ga4TxCount     = $ga4Events->count();
        $ga4TrackedRev  = (float) $ga4Events->sum('tracked_revenue');
        $duplicateGa4   = $ga4Events->where('is_duplicate', true)->count();

        // 3. Match transaction IDs
        $matchedCount      = 0;
        $missingInAdobeCount = 0;

        foreach ($ga4Events as $event) {
            $txId = $event->transaction_id;
            if (isset($adobeLookup[$txId])) {
                $matchedCount++;
            } else {
                $missingInAdobeCount++;
            }
        }

        $missingInGa4Count = max(0, $adobeTxCount - $matchedCount);

        // 4. Differences
        $absDiff = abs($ga4TrackedRev - $adobeNetRev);
        $pctDiff = $adobeNetRev > 0 ? round(($absDiff / $adobeNetRev) * 100, 2) : 0.0;

        // 5. Configurable dual thresholds
        $thresholds   = $client->monitoring_config['reconciliation_thresholds'] ?? [];
        $pctThreshold = (float) ($thresholds['percentage'] ?? 5.0);
        $absThreshold = (float) ($thresholds['absolute'] ?? 250.0);

        // 6. Validation status
        $status = 'valid';
        if ($pctDiff >= $pctThreshold && $absDiff >= $absThreshold) {
            $status = $pctDiff >= 15.0 || $absDiff >= 500.0 ? 'material_discrepancy' : 'review_recommended';
        }

        return MetricReconciliationResult::updateOrCreate(
            [
                'client_id'       => $client->id,
                'reporting_start' => $from,
                'reporting_end'   => $to,
            ],
            [
                'adobe_transaction_count'   => $adobeTxCount,
                'ga4_transaction_count'     => $ga4TxCount,
                'matched_transaction_count' => $matchedCount,
                'missing_in_ga4_count'      => $missingInGa4Count,
                'missing_in_adobe_count'    => $missingInAdobeCount,
                'duplicate_ga4_count'       => $duplicateGa4,
                'adobe_net_revenue'         => $adobeNetRev,
                'ga4_tracked_revenue'       => $ga4TrackedRev,
                'absolute_difference'       => $absDiff,
                'percentage_difference'     => $pctDiff,
                'validation_status'         => $status,
                'calculation_version'       => KpiMetadataRegistry::CALCULATION_VERSION,
                'metadata_json'             => [
                    'percentage_threshold'  => $pctThreshold,
                    'absolute_threshold'    => $absThreshold,
                    'triggers_warning'      => ($status !== 'valid'),
                ],
            ]
        );
    }
}
