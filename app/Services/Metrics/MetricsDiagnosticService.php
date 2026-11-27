<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\CommerceMetric;
use App\Models\AnalyticsPurchaseEvent;
use App\Models\EmailMarketingMetric;
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

    /**
     * Build segmented GA4 vs Magento conversion and traffic-dilution diagnostics
     * for equivalent preceding and current periods.
     *
     * @return array<string, mixed>
     */
    public function buildConversionWindowDiagnostics(
        Client $client,
        Carbon $from,
        Carbon $to,
        Carbon $fromPrev,
        Carbon $toPrev
    ): array {
        $curr = $this->summarizeConversionPeriod($client, $from, $to);
        $prev = $this->summarizeConversionPeriod($client, $fromPrev, $toPrev);

        $directSessionsDeltaPct = $prev['ga4_direct_sessions'] > 0
            ? round((($curr['ga4_direct_sessions'] - $prev['ga4_direct_sessions']) / $prev['ga4_direct_sessions']) * 100, 1)
            : null;
        $nonDirectCvrDeltaPct = $prev['ga4_non_direct_cvr'] > 0
            ? round((($curr['ga4_non_direct_cvr'] - $prev['ga4_non_direct_cvr']) / $prev['ga4_non_direct_cvr']) * 100, 1)
            : null;
        $magentoOrdersDeltaPct = $prev['magento_valid_orders'] > 0
            ? round((($curr['magento_valid_orders'] - $prev['magento_valid_orders']) / $prev['magento_valid_orders']) * 100, 1)
            : null;
        $magentoNetRevDeltaPct = $prev['magento_valid_net_revenue'] > 0
            ? round((($curr['magento_valid_net_revenue'] - $prev['magento_valid_net_revenue']) / $prev['magento_valid_net_revenue']) * 100, 1)
            : null;

        $diagnosisType = 'indeterminate';
        if ($curr['ga4_direct_share_pct'] >= 70.0 && ($directSessionsDeltaPct ?? 0) >= 100.0) {
            if (($magentoOrdersDeltaPct ?? 0) >= 0 && ($nonDirectCvrDeltaPct ?? 0) >= -5.0) {
                $diagnosisType = 'traffic_denominator_dilution';
            } else {
                $diagnosisType = 'mixed_dilution_and_commercial_softness';
            }
        } elseif (($magentoOrdersDeltaPct ?? 0) <= -10.0 || ($nonDirectCvrDeltaPct ?? 0) <= -10.0) {
            $diagnosisType = 'commercial_or_funnel_deterioration';
        }

        return [
            'source_window_start'       => $from->toDateString(),
            'source_window_end'         => $to->toDateString(),
            'prior_window_start'        => $fromPrev->toDateString(),
            'prior_window_end'          => $toPrev->toDateString(),
            'diagnosis_type'            => $diagnosisType,
            'current'                   => $curr,
            'previous'                  => $prev,
            'direct_sessions_delta_pct' => $directSessionsDeltaPct,
            'non_direct_cvr_delta_pct'  => $nonDirectCvrDeltaPct,
            'magento_orders_delta_pct'  => $magentoOrdersDeltaPct,
            'magento_net_rev_delta_pct' => $magentoNetRevDeltaPct,
        ];
    }

    /**
     * Build GA4 vs Klaviyo attribution-model diagnostics for a given closed window.
     *
     * @return array<string, mixed>
     */
    public function buildKlaviyoAttributionDiagnostics(Client $client, Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $emailRows = EmailMarketingMetric::where('client_id', $client->id)
            ->whereBetween('date', [$fromDate, $toDate])
            ->get();

        $klaviyoRevenue = round((float) $emailRows->sum('revenue'), 2);
        $flowRevenue = round((float) $emailRows->where('type', 'flow')->sum('revenue'), 2);
        $campaignRevenue = round((float) $emailRows->where('type', 'campaign')->sum('revenue'), 2);
        $zeroClickRevenue = round((float) $emailRows->filter(fn ($r) => (float) $r->revenue > 0 && (int) $r->clicks === 0)->sum('revenue'), 2);
        $zeroClickConversions = (int) $emailRows->filter(fn ($r) => (float) $r->revenue > 0 && (int) $r->clicks === 0)->sum('conversions');
        $clickedRevenue = round((float) $emailRows->filter(fn ($r) => (float) $r->revenue > 0 && (int) $r->clicks > 0)->sum('revenue'), 2);
        $totalClicks = (int) $emailRows->sum('clicks');
        $totalConversions = (int) $emailRows->sum('conversions');

        $ga4Rows = CommerceMetric::where('client_id', $client->id)
            ->where('source', 'ga4')
            ->whereBetween('date', [$fromDate, $toDate])
            ->get();

        $ga4EmailRev = 0.0;
        $ga4EmailSessions = 0;
        $ga4EmailTx = 0;
        foreach ($ga4Rows as $r) {
            $sb = is_array($r->source_breakdown_json) ? $r->source_breakdown_json : (json_decode($r->source_breakdown_json ?? '[]', true) ?: []);
            $email = $sb['email'] ?? $sb['Email'] ?? null;
            if (is_array($email)) {
                $ga4EmailRev += (float) ($email['revenue'] ?? 0);
                $ga4EmailSessions += (int) ($email['sessions'] ?? 0);
                $ga4EmailTx += (int) ($email['transactions'] ?? 0);
            } elseif (is_numeric($email)) {
                $ga4EmailRev += (float) $email;
            }
        }
        $ga4EmailRev = round($ga4EmailRev, 2);

        $zeroClickSharePct = $klaviyoRevenue > 0 ? round(($zeroClickRevenue / $klaviyoRevenue) * 100, 1) : 0.0;
        $diffAmount = round(abs($klaviyoRevenue - $ga4EmailRev), 2);
        $diffPct = $klaviyoRevenue > 0 ? round(($diffAmount / $klaviyoRevenue) * 100, 1) : 0.0;

        return [
            'source_window_start'        => $fromDate,
            'source_window_end'          => $toDate,
            'klaviyo_attributed_revenue' => $klaviyoRevenue,
            'ga4_email_revenue'          => $ga4EmailRev,
            'ga4_email_sessions'         => $ga4EmailSessions,
            'ga4_email_transactions'     => $ga4EmailTx,
            'flow_revenue'               => $flowRevenue,
            'campaign_revenue'           => $campaignRevenue,
            'zero_click_revenue'         => $zeroClickRevenue,
            'zero_click_conversions'     => $zeroClickConversions,
            'zero_click_share_pct'       => $zeroClickSharePct,
            'clicked_revenue'            => $clickedRevenue,
            'total_clicks'               => $totalClicks,
            'total_conversions'          => $totalConversions,
            'variance_amount'            => $diffAmount,
            'variance_pct'               => $diffPct,
            'utm_links_inspected'        => false,
        ];
    }

    /**
     * Aggregate equivalent GA4 (including source breakdown and funnel) + Magento order totals for a window.
     */
    private function summarizeConversionPeriod(Client $client, Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $ga4Rows = CommerceMetric::where('client_id', $client->id)
            ->where('source', 'ga4')
            ->whereBetween('date', [$fromDate, $toDate])
            ->get();

        $totSess = (int) $ga4Rows->sum('sessions');
        $totTx = (int) $ga4Rows->sum('orders');
        $totRev = round((float) $ga4Rows->sum('revenue'), 2);
        $directSess = 0;
        $directTx = 0;
        $funnel = ['view_item' => 0, 'add_to_cart' => 0, 'begin_checkout' => 0, 'purchase' => 0];
        $hasFunnel = false;

        foreach ($ga4Rows as $r) {
            $sb = is_array($r->source_breakdown_json) ? $r->source_breakdown_json : (json_decode($r->source_breakdown_json ?? '[]', true) ?: []);
            if (! empty($sb['direct']) && is_array($sb['direct'])) {
                $directSess += (int) ($sb['direct']['sessions'] ?? 0);
                $directTx += (int) ($sb['direct']['transactions'] ?? 0);
            }
            $md = is_array($r->metadata_json) ? $r->metadata_json : (json_decode($r->metadata_json ?? '[]', true) ?: []);
            if (! empty($md['funnel']) && is_array($md['funnel'])) {
                $hasFunnel = true;
                foreach ($funnel as $fk => $_) {
                    $funnel[$fk] += (int) ($md['funnel'][$fk] ?? 0);
                }
            }
        }

        $nonDirectSess = max(0, $totSess - $directSess);
        $nonDirectTx = max(0, $totTx - $directTx);

        $orders = CommerceOrder::where('client_id', $client->id)
            ->whereBetween('order_date', [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'])
            ->get(['is_valid', 'net_revenue', 'gross_revenue']);

        $validOrders = $orders->where('is_valid', true);
        $excludedOrders = $orders->where('is_valid', false);

        $checkoutCompletionPct = ($hasFunnel && $funnel['begin_checkout'] > 0)
            ? round(($funnel['purchase'] / $funnel['begin_checkout']) * 100, 1)
            : null;

        return [
            'ga4_sessions'              => $totSess,
            'ga4_orders'                => $totTx,
            'ga4_revenue'               => $totRev,
            'ga4_overall_cvr'           => $totSess > 0 ? round(($totTx / $totSess) * 100, 3) : 0.0,
            'ga4_direct_sessions'       => $directSess,
            'ga4_direct_share_pct'      => $totSess > 0 ? round(($directSess / $totSess) * 100, 1) : 0.0,
            'ga4_direct_orders'         => $directTx,
            'ga4_direct_cvr'            => $directSess > 0 ? round(($directTx / $directSess) * 100, 3) : 0.0,
            'ga4_non_direct_sessions'   => $nonDirectSess,
            'ga4_non_direct_orders'     => $nonDirectTx,
            'ga4_non_direct_cvr'        => $nonDirectSess > 0 ? round(($nonDirectTx / $nonDirectSess) * 100, 3) : 0.0,
            'funnel'                    => $hasFunnel ? $funnel : null,
            'checkout_completion_pct'   => $checkoutCompletionPct,
            'magento_valid_orders'      => $validOrders->count(),
            'magento_valid_net_revenue' => round((float) $validOrders->sum('net_revenue'), 2),
            'magento_excluded_orders'   => $excludedOrders->count(),
            'magento_excluded_gross'    => round((float) $excludedOrders->sum('gross_revenue'), 2),
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
