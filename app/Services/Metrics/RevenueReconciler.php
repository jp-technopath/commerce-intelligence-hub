<?php

namespace App\Services\Metrics;

use App\Models\AnalyticsPurchaseEvent;
use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\MetricReconciliationResult;
use Carbon\Carbon;

class RevenueReconciler
{
    public function reconcile(
        Client $client,
        Carbon $from,
        Carbon $to
    ): MetricReconciliationResult {
        $adobeOrders = CommerceOrder::where('client_id', $client->id)
            ->where('is_valid', true)
            ->whereBetween('order_date', [$from, $to])
            ->get();

        $adobeTxCount = $adobeOrders->count();
        $adobeNetRev = (float) $adobeOrders->sum('net_revenue');

        $adobeOrderIds = $adobeOrders
            ->pluck('source_order_id')
            ->filter()
            ->toArray();

        $adobeIncrementIds = $adobeOrders
            ->pluck('source_increment_id')
            ->filter()
            ->toArray();

        $adobeLookup = array_flip(
            array_merge($adobeOrderIds, $adobeIncrementIds)
        );

        $ga4Events = AnalyticsPurchaseEvent::where('client_id', $client->id)
            ->whereDate('event_date', '>=', $from->toDateString())
            ->whereDate('event_date', '<=', $to->toDateString())
            ->get();

        $ga4TxCount = $ga4Events->count();
        $ga4TrackedRev = (float) $ga4Events->sum('tracked_revenue');
        $duplicateGa4 = $ga4Events
            ->where('is_duplicate', true)
            ->count();

        $matchedCount = 0;
        $missingInAdobeCount = 0;

        foreach ($ga4Events as $event) {
            if (isset($adobeLookup[$event->transaction_id])) {
                $matchedCount++;
            } else {
                $missingInAdobeCount++;
            }
        }

        $missingInGa4Count = max(
            0,
            $adobeTxCount - $matchedCount
        );

        $absoluteDifference = abs(
            $ga4TrackedRev - $adobeNetRev
        );

        $percentageDifference = $adobeNetRev > 0
            ? round(
                ($absoluteDifference / $adobeNetRev) * 100,
                2
            )
            : 0.0;

        $thresholds = $client
            ->monitoring_config['reconciliation_thresholds']
            ?? [];

        $percentageThreshold = (float) (
            $thresholds['percentage'] ?? 5.0
        );

        $absoluteThreshold = (float) (
            $thresholds['absolute'] ?? 250.0
        );

        $validationStatus = 'valid';

        if (
            $percentageDifference >= $percentageThreshold
            && $absoluteDifference >= $absoluteThreshold
        ) {
            $validationStatus =
                $percentageDifference >= 15.0
                || $absoluteDifference >= 500.0
                    ? 'material_discrepancy'
                    : 'review_recommended';
        }

        return MetricReconciliationResult::updateOrCreate(
            [
                'client_id' => $client->id,
                'reporting_start' => $from,
                'reporting_end' => $to,
            ],
            [
                'adobe_transaction_count' => $adobeTxCount,
                'ga4_transaction_count' => $ga4TxCount,
                'matched_transaction_count' => $matchedCount,
                'missing_in_ga4_count' => $missingInGa4Count,
                'missing_in_adobe_count' => $missingInAdobeCount,
                'duplicate_ga4_count' => $duplicateGa4,
                'adobe_net_revenue' => $adobeNetRev,
                'ga4_tracked_revenue' => $ga4TrackedRev,
                'absolute_difference' => $absoluteDifference,
                'percentage_difference' => $percentageDifference,
                'validation_status' => $validationStatus,
                'calculation_version' => KpiMetadataRegistry::CALCULATION_VERSION,
                'metadata_json' => [
                    'percentage_threshold' => $percentageThreshold,
                    'absolute_threshold' => $absoluteThreshold,
                    'triggers_warning' => $validationStatus !== 'valid',
                ],
            ]
        );
    }
}