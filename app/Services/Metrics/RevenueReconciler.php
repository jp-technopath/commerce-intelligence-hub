<?php

namespace App\Services\Metrics;

use App\Models\AnalyticsPurchaseEvent;
use App\Models\Client;
use App\Models\CommerceMetric;
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
     * Summarize cross-platform transaction & revenue reconciliation without mutating persistence tables.
     *
     * @return array<string, mixed>
     */
    public function summarizeReadOnly(Client $client, Carbon $from, Carbon $to): array
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        $allOrders = CommerceOrder::where('client_id', $client->id)
            ->whereBetween('order_date', [$fromStart->toDateTimeString(), $toEnd->toDateTimeString()])
            ->get();

        $validOrders = $allOrders->where('is_valid', true);
        $excludedOrders = $allOrders->where('is_valid', false);

        $adobeTxCount = $validOrders->count();
        $adobeNetRev = round((float) $validOrders->sum('net_revenue'), 2);
        $adobeGrossValidRev = round((float) $validOrders->sum('gross_revenue'), 2);
        $adobeRefundedRev = round((float) $validOrders->sum('refunded_revenue'), 2);
        $adobeTaxAmount = round((float) $validOrders->sum('tax_amount'), 2);
        $adobeShippingAmount = round((float) $validOrders->sum('shipping_amount'), 2);
        $adobeExcludedCount = $excludedOrders->count();
        $adobeExcludedGrossRev = round((float) $excludedOrders->sum('gross_revenue'), 2);

        $excludedByReason = $excludedOrders
            ->groupBy(fn ($o) => $o->exclusion_reason ?: ($o->order_status ?: 'excluded'))
            ->map(fn ($group) => [
                'count'         => $group->count(),
                'gross_revenue' => round((float) $group->sum('gross_revenue'), 2),
            ])
            ->toArray();

        $validLookup = [];
        foreach ($validOrders as $o) {
            if (! empty($o->source_order_id)) {
                $validLookup[(string) $o->source_order_id] = true;
            }
            if (! empty($o->source_increment_id)) {
                $validLookup[(string) $o->source_increment_id] = true;
            }
        }

        $excludedLookup = [];
        foreach ($excludedOrders as $o) {
            if (! empty($o->source_order_id)) {
                $excludedLookup[(string) $o->source_order_id] = true;
            }
            if (! empty($o->source_increment_id)) {
                $excludedLookup[(string) $o->source_increment_id] = true;
            }
        }

        $ga4Events = AnalyticsPurchaseEvent::where('client_id', $client->id)
            ->whereDate('event_date', '>=', $fromStart->toDateString())
            ->whereDate('event_date', '<=', $toEnd->toDateString())
            ->get();

        $ga4MetricsSum = round((float) CommerceMetric::where('client_id', $client->id)
            ->where('source', 'ga4')
            ->whereBetween('date', [$fromStart->toDateString(), $toEnd->toDateString()])
            ->sum('revenue'), 2);

        $ga4EventTxCount = $ga4Events->count();
        $ga4EventTrackedRev = round((float) $ga4Events->sum('tracked_revenue'), 2);
        $duplicateGa4Count = $ga4Events->where('is_duplicate', true)->count();

        $matchedValidCount = 0;
        $matchedExcludedCount = 0;
        $matchedExcludedRevenue = 0.0;
        $unmatchedInAdobeCount = 0;
        $unmatchedInAdobeRevenue = 0.0;

        foreach ($ga4Events as $ev) {
            $txId = (string) $ev->transaction_id;
            if (isset($validLookup[$txId])) {
                $matchedValidCount++;
            } elseif (isset($excludedLookup[$txId])) {
                $matchedExcludedCount++;
                $matchedExcludedRevenue += (float) $ev->tracked_revenue;
            } else {
                $unmatchedInAdobeCount++;
                $unmatchedInAdobeRevenue += (float) $ev->tracked_revenue;
            }
        }

        $ga4AuthoritativeRev = $ga4EventTxCount > 0 ? $ga4EventTrackedRev : $ga4MetricsSum;
        $missingInGa4Count = $ga4EventTxCount > 0 ? max(0, $adobeTxCount - $matchedValidCount) : null;

        $absDiff = round(abs($ga4AuthoritativeRev - $adobeNetRev), 2);
        $pctDiff = $adobeNetRev > 0 ? round(($absDiff / $adobeNetRev) * 100, 1) : 0.0;

        $direction = 'aligned';
        if ($ga4AuthoritativeRev > $adobeNetRev) {
            $direction = 'ga4_exceeds_commerce';
        } elseif ($ga4AuthoritativeRev < $adobeNetRev) {
            $direction = 'commerce_exceeds_ga4';
        }

        return [
            'source_window_start'        => $fromStart->toDateString(),
            'source_window_end'          => $toEnd->toDateString(),
            'adobe_net_revenue'          => $adobeNetRev,
            'adobe_gross_valid_revenue'  => $adobeGrossValidRev,
            'adobe_refunded_revenue'     => $adobeRefundedRev,
            'adobe_tax_amount'           => $adobeTaxAmount,
            'adobe_shipping_amount'      => $adobeShippingAmount,
            'adobe_valid_count'          => $adobeTxCount,
            'adobe_excluded_count'       => $adobeExcludedCount,
            'adobe_excluded_gross_revenue' => $adobeExcludedGrossRev,
            'adobe_excluded_by_reason'   => $excludedByReason,
            'ga4_metrics_revenue'        => $ga4MetricsSum,
            'ga4_tracked_revenue'        => $ga4AuthoritativeRev,
            'ga4_transaction_count'      => $ga4EventTxCount,
            'duplicate_ga4_count'        => $duplicateGa4Count,
            'matched_valid_count'        => $matchedValidCount,
            'matched_excluded_count'     => $matchedExcludedCount,
            'matched_excluded_revenue'   => round($matchedExcludedRevenue, 2),
            'unmatched_in_adobe_count'   => $unmatchedInAdobeCount,
            'unmatched_in_adobe_revenue' => round($unmatchedInAdobeRevenue, 2),
            'missing_in_ga4_count'       => $missingInGa4Count,
            'variance_amount'            => $absDiff,
            'variance_pct'               => $pctDiff,
            'direction'                  => $direction,
            'has_transaction_events'     => $ga4EventTxCount > 0,
        ];
    }

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
            ->whereDate('event_date', '>=', $from->toDateString())
            ->whereDate('event_date', '<=', $to->toDateString())
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
