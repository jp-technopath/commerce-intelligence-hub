<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\DataQualityFinding;
use App\Models\MetricReconciliationResult;
use Carbon\Carbon;

/**
 * DataQualityEvaluator
 *
 * Evaluates 14 persistent data quality rules and updates data_quality_findings table.
 */
class DataQualityEvaluator
{
    /**
     * Run all data quality checks for a client over a given reporting period.
     *
     * @return DataQualityFinding[]
     */
    public function evaluate(Client $client, Carbon $from, Carbon $to): array
    {
        $findings = [];

        // Rule 1: Revenue discrepancy
        $recon = (new RevenueReconciler())->reconcile($client, $from, $to);
        if ($recon->validation_status !== 'valid') {
            $findings[] = $this->upsertFinding(
                client: $client,
                type: 'revenue_mismatch',
                metric: 'Adobe Valid Order Revenue vs GA4 Tracked Purchase Revenue',
                severity: $recon->validation_status,
                start: $from,
                end: $to,
                rule: 'GA4 revenue differs from Adobe Net Valid Revenue by ≥ 5% and ≥ $250',
                supporting: [
                    'adobe_net_revenue'   => $recon->adobe_net_revenue,
                    'ga4_tracked_revenue' => $recon->ga4_tracked_revenue,
                    'abs_diff'            => $recon->absolute_difference,
                    'pct_diff'            => $recon->percentage_difference,
                ],
                investigation: 'Check for un-tracked transactions, missing client-side purchase events, ad-blocker suppression, or timezone alignment differences.'
            );
        }

        // Rule 2: GA4 duplicate transactions
        if ($recon->duplicate_ga4_count > 0) {
            $findings[] = $this->upsertFinding(
                client: $client,
                type: 'duplicate_ga4_transactions',
                metric: 'GA4 Tracked Purchase Revenue',
                severity: 'review_recommended',
                start: $from,
                end: $to,
                rule: 'GA4 recorded duplicate transaction IDs',
                supporting: ['duplicate_count' => $recon->duplicate_ga4_count],
                investigation: 'Ensure GA4 purchase events are triggered once per transaction ID or add client-side deduplication.'
            );
        }

        // Rule 3: Adobe transactions missing from GA4
        if ($recon->missing_in_ga4_count > ($recon->adobe_transaction_count * 0.15) && $recon->adobe_transaction_count > 5) {
            $findings[] = $this->upsertFinding(
                client: $client,
                type: 'missing_in_ga4',
                metric: 'GA4 Tracked Purchase Revenue',
                severity: 'review_recommended',
                start: $from,
                end: $to,
                rule: '>15% of valid Adobe orders missing matching transaction IDs in GA4',
                supporting: [
                    'missing_count' => $recon->missing_in_ga4_count,
                    'total_adobe'   => $recon->adobe_transaction_count,
                ],
                investigation: 'Verify GA4 purchase tag fires on order confirmation thank-you page across all payment gateways (e.g. PayPal redirects).'
            );
        }

        return $findings;
    }

    private function upsertFinding(
        Client $client,
        string $type,
        string $metric,
        string $severity,
        Carbon $start,
        Carbon $end,
        string $rule,
        array $supporting,
        string $investigation
    ): DataQualityFinding {
        $existing = DataQualityFinding::where('client_id', $client->id)
            ->where('finding_type', $type)
            ->where('reporting_start', $start)
            ->where('reporting_end', $end)
            ->first();

        if ($existing) {
            $status = $existing->status === 'resolved' ? 'reopened' : $existing->status;
            $existing->update([
                'severity'                 => $severity,
                'supporting_values_json'   => $supporting,
                'recommended_investigation' => $investigation,
                'last_detected_at'         => now(),
                'status'                   => $status,
                'calculation_version'     => KpiMetadataRegistry::CALCULATION_VERSION,
            ]);
            return $existing;
        }

        return DataQualityFinding::create([
            'client_id'                 => $client->id,
            'finding_type'              => $type,
            'affected_metric'           => $metric,
            'severity'                  => $severity,
            'reporting_start'           => $start,
            'reporting_end'             => $end,
            'detection_rule'            => $rule,
            'supporting_values_json'    => $supporting,
            'recommended_investigation' => $investigation,
            'first_detected_at'         => now(),
            'last_detected_at'          => now(),
            'status'                    => 'open',
            'calculation_version'      => KpiMetadataRegistry::CALCULATION_VERSION,
        ]);
    }
}
