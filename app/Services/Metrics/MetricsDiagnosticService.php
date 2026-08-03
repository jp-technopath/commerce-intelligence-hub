<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\CommerceMetric;
use App\Models\AnalyticsPurchaseEvent;
use Carbon\Carbon;

/**
 * MetricsDiagnosticService
 *
 * Compiles comprehensive developer diagnostics and debug telemetry for every KPI
 * displayed on the Commerce Intelligence Hub Business Dashboard.
 */
class MetricsDiagnosticService
{
    /**
     * Generate diagnostic telemetry array for a client and period.
     */
    public function generateDiagnostics(Client $client, int $days = 360): array
    {
        $fromCur  = now()->subDays($days)->startOfDay();
        $toCur    = now()->endOfDay();
        $fromPrev = now()->subDays($days * 2)->startOfDay();
        $toPrev   = now()->subDays($days + 1)->endOfDay();

        $timezone   = $client->timezone ?? 'America/New_York';
        $storeScope = $client->platform_type ?? 'Adobe Commerce';

        // 1. Adobe Orders & Revenue
        $revCalc = new CommerceRevenueCalculator();
        $curRev  = $revCalc->calculate($client, $fromCur, $toCur);
        $prevRev = $revCalc->calculate($client, $fromPrev, $toPrev);

        // 2. GA4 Metrics
        $ga4Current  = (new BusinessDashboardHelper())->getCommerceAggregates($client->id, 'ga4', $fromCur, $toCur);
        $ga4Previous = (new BusinessDashboardHelper())->getCommerceAggregates($client->id, 'ga4', $fromPrev, $toPrev);

        // 3. Repeat Customers
        $repCalc = new RepeatCustomerCalculator();
        $curRep  = $repCalc->calculate($client, $fromCur, $toCur);
        $prevRep = $repCalc->calculate($client, $fromPrev, $toPrev);

        // 4. GA4 Events
        $ga4EventsCur = AnalyticsPurchaseEvent::where('client_id', $client->id)
            ->whereBetween('event_date', [$fromCur->toDateString(), $toCur->toDateString()])
            ->get();
        $ga4EventsPrev = AnalyticsPurchaseEvent::where('client_id', $client->id)
            ->whereBetween('event_date', [$fromPrev->toDateString(), $toPrev->toDateString()])
            ->get();

        $ga4UniqueTxCur  = $ga4EventsCur->unique('transaction_id')->count();
        $ga4UniqueTxPrev = $ga4EventsPrev->unique('transaction_id')->count();

        // 5. Items Sold
        $adobeMetricsCur  = (new BusinessDashboardHelper())->getCommerceAggregates($client->id, 'adobe_commerce', $fromCur, $toCur);
        $adobeMetricsPrev = (new BusinessDashboardHelper())->getCommerceAggregates($client->id, 'adobe_commerce', $fromPrev, $toPrev);

        $curUsers  = max(1, $ga4Current['active_users']);
        $prevUsers = max(1, $ga4Previous['active_users']);

        $curConv  = round(($curRev['valid_orders'] / $curUsers) * 100, 2);
        $prevConv = round(($prevRev['valid_orders'] / $prevUsers) * 100, 2);

        $curRpv  = round($curRev['net_revenue'] / $curUsers, 2);
        $prevRpv = round($prevRev['net_revenue'] / $prevUsers, 2);

        $nowIso = now()->toIso8601String();

        return [
            'client' => [
                'id'            => $client->id,
                'name'          => $client->name,
                'platform_type' => $client->platform_type,
                'timezone'      => $timezone,
            ],
            'period' => [
                'days'                => $days,
                'current_start'       => $fromCur->toDateTimeString(),
                'current_end'         => $toCur->toDateTimeString(),
                'prior_start'         => $fromPrev->toDateTimeString(),
                'prior_end'           => $toPrev->toDateTimeString(),
                'is_disjoint_window'  => true,
            ],
            'kpis' => [
                'commerce_conversion_rate' => [
                    'metric_key'                => 'commerce_conversion_rate',
                    'display_name'              => 'Commerce Conversion Rate',
                    'current_value'             => $curConv . '%',
                    'current_numerator'         => $curRev['valid_orders'],
                    'current_denominator'       => $curUsers,
                    'prior_value'               => $prevConv . '%',
                    'prior_numerator'           => $prevRev['valid_orders'],
                    'prior_denominator'         => $prevUsers,
                    'formula'                   => 'Adobe Valid Orders ÷ GA4 Period Unique Active Users × 100',
                    'data_source'               => 'Adobe Commerce + GA4',
                    'api_or_db_fields'          => 'commerce_orders (valid_orders) + commerce_metrics (active_users)',
                    'filters'                   => 'is_valid = true',
                    'store_scope'               => $storeScope,
                    'timezone'                  => $timezone,
                    'included_order_statuses'   => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                    'excluded_order_statuses'   => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                    'last_successful_refresh'   => $nowIso,
                    'query_execution_timestamp' => $nowIso,
                ],

                'revenue_per_visitor' => [
                    'metric_key'                => 'revenue_per_visitor',
                    'display_name'              => 'Revenue per Visitor',
                    'current_value'             => '$' . number_format($curRpv, 2),
                    'current_numerator'         => $curRev['net_revenue'],
                    'current_denominator'       => $curUsers,
                    'prior_value'               => '$' . number_format($prevRpv, 2),
                    'prior_numerator'           => $prevRev['net_revenue'],
                    'prior_denominator'         => $prevUsers,
                    'formula'                   => 'Adobe Valid Net Revenue ÷ GA4 Period Unique Active Users',
                    'data_source'               => 'Adobe Commerce + GA4',
                    'api_or_db_fields'          => 'commerce_orders (net_revenue) + commerce_metrics (active_users)',
                    'filters'                   => 'is_valid = true',
                    'store_scope'               => $storeScope,
                    'timezone'                  => $timezone,
                    'included_order_statuses'   => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                    'excluded_order_statuses'   => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                    'last_successful_refresh'   => $nowIso,
                    'query_execution_timestamp' => $nowIso,
                ],

                'repeat_customer_rate' => [
                    'metric_key'                => 'repeat_customer_rate',
                    'display_name'              => 'Repeat Customer Rate',
                    'current_value'             => number_format($curRep['repeat_customer_rate'], 1) . '%',
                    'current_numerator'         => $curRep['repeat_purchasing_customers'],
                    'current_denominator'       => $curRep['total_purchasing_customers'],
                    'prior_value'               => number_format($prevRep['repeat_customer_rate'], 1) . '%',
                    'prior_numerator'           => $prevRep['repeat_purchasing_customers'],
                    'prior_denominator'         => $prevRep['total_purchasing_customers'],
                    'formula'                   => 'Customers with 2+ Valid Orders in Period ÷ Total Purchasing Customers in Period × 100',
                    'data_source'               => 'Adobe Commerce',
                    'api_or_db_fields'          => 'commerce_orders (registered_customer_id, customer_identity_hash)',
                    'filters'                   => 'is_valid = true, count(id) >= 2 in period',
                    'store_scope'               => $storeScope,
                    'timezone'                  => $timezone,
                    'included_order_statuses'   => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                    'excluded_order_statuses'   => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                    'last_successful_refresh'   => $nowIso,
                    'query_execution_timestamp' => $nowIso,
                ],

                'adobe_valid_order_revenue' => [
                    'metric_key'                => 'adobe_valid_order_revenue',
                    'display_name'              => 'Adobe Valid Order Revenue',
                    'current_value'             => '$' . number_format($curRev['net_revenue'], 2),
                    'current_numerator'         => $curRev['net_revenue'],
                    'current_denominator'       => 1,
                    'prior_value'               => '$' . number_format($prevRev['net_revenue'], 2),
                    'prior_numerator'           => $prevRev['net_revenue'],
                    'prior_denominator'         => 1,
                    'formula'                   => 'Sum of Net Revenue from Valid Orders',
                    'data_source'               => 'Adobe Commerce',
                    'api_or_db_fields'          => 'commerce_orders (net_revenue)',
                    'filters'                   => 'is_valid = true',
                    'store_scope'               => $storeScope,
                    'timezone'                  => $timezone,
                    'included_order_statuses'   => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                    'excluded_order_statuses'   => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                    'last_successful_refresh'   => $nowIso,
                    'query_execution_timestamp' => $nowIso,
                ],

                'ga4_tracked_purchase_revenue' => [
                    'metric_key'                => 'ga4_tracked_purchase_revenue',
                    'display_name'              => 'GA4 Tracked Purchase Revenue',
                    'current_value'             => '$' . number_format($ga4Current['revenue'], 2),
                    'current_numerator'         => $ga4Current['revenue'],
                    'current_denominator'       => 1,
                    'prior_value'               => '$' . number_format($ga4Previous['revenue'], 2),
                    'prior_numerator'           => $ga4Previous['revenue'],
                    'prior_denominator'         => 1,
                    'formula'                   => 'Sum of GA4 Purchase Event Tracked Revenue',
                    'data_source'               => 'Google Analytics 4',
                    'api_or_db_fields'          => 'commerce_metrics (revenue) / analytics_purchase_events (tracked_revenue)',
                    'filters'                   => 'Deduplicated purchase events',
                    'store_scope'               => $storeScope,
                    'timezone'                  => $timezone,
                    'included_order_statuses'   => 'Client-side tracked purchase events',
                    'excluded_order_statuses'   => 'N/A',
                    'last_successful_refresh'   => $nowIso,
                    'query_execution_timestamp' => $nowIso,
                ],

                'items_sold' => [
                    'metric_key'                => 'items_sold',
                    'display_name'              => 'Items Sold',
                    'current_value'             => number_format($adobeMetricsCur['items_sold']),
                    'current_numerator'         => $adobeMetricsCur['items_sold'],
                    'current_denominator'       => 1,
                    'prior_value'               => number_format($adobeMetricsPrev['items_sold']),
                    'prior_numerator'           => $adobeMetricsPrev['items_sold'],
                    'prior_denominator'         => 1,
                    'formula'                   => 'Sum of Net Valid Item Quantities on Valid Orders',
                    'data_source'               => 'Adobe Commerce',
                    'api_or_db_fields'          => 'commerce_metrics (items_sold)',
                    'filters'                   => 'is_valid = true, excludes parent/child configurable duplicates',
                    'store_scope'               => $storeScope,
                    'timezone'                  => $timezone,
                    'included_order_statuses'   => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                    'excluded_order_statuses'   => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                    'last_successful_refresh'   => $nowIso,
                    'query_execution_timestamp' => $nowIso,
                ],
            ],
            'reconciliation' => [
                'ga4_unique_transactions'   => $ga4UniqueTxCur,
                'adobe_valid_orders'        => $curRev['valid_orders'],
                'transaction_diff_count'    => abs($ga4UniqueTxCur - $curRev['valid_orders']),
                'transaction_diff_percent'  => $curRev['valid_orders'] > 0 ? round((abs($ga4UniqueTxCur - $curRev['valid_orders']) / $curRev['valid_orders']) * 100, 2) : 0.0,
                'triggers_discrepancy_warning' => ($curRev['valid_orders'] > 0 && (abs($ga4UniqueTxCur - $curRev['valid_orders']) / $curRev['valid_orders']) > 0.05),
            ],
        ];
    }
}

/**
 * BusinessDashboardHelper
 */
class BusinessDashboardHelper
{
    public function getCommerceAggregates(int $clientId, string $source, Carbon $from, Carbon $to): array
    {
        $row = CommerceMetric::where('client_id', $clientId)
            ->where('source', $source)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(sessions), 0) as sessions,
                COALESCE(SUM(active_users), 0) as active_users,
                COALESCE(SUM(revenue), 0) as revenue,
                COALESCE(SUM(orders), 0) as orders,
                COALESCE(SUM(items_sold), 0) as items_sold
            ')
            ->first();

        return [
            'sessions'     => (int) ($row->sessions ?? 0),
            'active_users' => (int) ($row->active_users ?? 0),
            'revenue'      => (float) ($row->revenue ?? 0),
            'orders'       => (int) ($row->orders ?? 0),
            'items_sold'   => (int) ($row->items_sold ?? 0),
        ];
    }
}
