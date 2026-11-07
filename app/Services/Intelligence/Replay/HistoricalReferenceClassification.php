<?php

namespace App\Services\Intelligence\Replay;

class HistoricalReferenceClassification
{
    public const VALID_ACTIONABLE_ISSUE = 'valid_actionable_issue';
    public const VALID_DIAGNOSTIC_SIGNAL = 'valid_diagnostic_signal';
    public const FALSE_POSITIVE = 'false_positive';
    public const DUPLICATE = 'duplicate';
    public const INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    /**
     * Reviewed reference classifications for Goldkamp findings #160-#181.
     *
     * @return array<int, array>
     */
    public static function getReferenceFindings(): array
    {
        return [
            160 => [
                'type' => 'revenue_change_decrease',
                'title' => 'Revenue decreased 41% month-over-month',
                'classification' => self::DUPLICATE,
                'remediation_target' => 'duplicate_of_170',
                'notes' => 'Redundant multi-window detection of commerce revenue decline.',
            ],
            161 => [
                'type' => 'aov_change_decrease',
                'title' => 'Average order value decreased 32%',
                'classification' => self::VALID_DIAGNOSTIC_SIGNAL,
                'remediation_target' => 'monitoring',
                'notes' => 'Valid diagnostic observation of B2B order mix shifting to smaller reorders.',
            ],
            162 => [
                'type' => 'dead_clicks_increase',
                'title' => 'Dead click rate increased 87.1%',
                'classification' => self::INSUFFICIENT_EVIDENCE,
                'remediation_target' => 'suppress_sample_size',
                'notes' => 'Low absolute click volume; noisy baseline.',
            ],
            163 => [
                'type' => 'error_clicks_increase',
                'title' => 'Error click rate increased 42.9%',
                'classification' => self::VALID_DIAGNOSTIC_SIGNAL,
                'remediation_target' => 'monitoring',
                'notes' => 'Legitimate diagnostic signal of UI frustration.',
            ],
            164 => [
                'type' => 'friction_score_increase',
                'title' => 'Overall friction score increased 27.5%',
                'classification' => self::INSUFFICIENT_EVIDENCE,
                'remediation_target' => 'suppress_coverage',
                'notes' => 'Clarity API timeouts resulted in incomplete baseline window.',
            ],
            165 => [
                'type' => 'unified_conversion_rate_decrease',
                'title' => 'Conversion rate decreased 29.5% month-over-month',
                'classification' => self::DUPLICATE,
                'remediation_target' => 'duplicate_of_179',
                'notes' => 'Redundant cross-window alert for conversion decline.',
            ],
            166 => [
                'type' => 'funnel_cart_to_checkout_dropoff_increase',
                'title' => 'Cart → Checkout abandonment up 43.4%',
                'classification' => self::INSUFFICIENT_EVIDENCE,
                'remediation_target' => 'suppress_qualification',
                'notes' => 'Unverified funnel event dropoff without qualified order data.',
            ],
            167 => [
                'type' => 'revenue_friction_correlation',
                'title' => 'Revenue down 41.0% while UX friction up 27.5%',
                'classification' => self::DUPLICATE,
                'remediation_target' => 'combinatorial_duplicate',
                'notes' => 'Pairwise combination of friction and revenue already covered.',
            ],
            168 => [
                'type' => 'ux_degradation_conversion_drop',
                'title' => 'Friction score up 27.5% while orders down 13.2%',
                'classification' => self::DUPLICATE,
                'remediation_target' => 'combinatorial_duplicate',
                'notes' => 'Pairwise combination of friction and orders already covered.',
            ],
            169 => [
                'type' => 'dead_clicks_revenue_correlation',
                'title' => 'Dead clicks up 87.1% while revenue down 41.0%',
                'classification' => self::DUPLICATE,
                'remediation_target' => 'combinatorial_duplicate',
                'notes' => 'Pairwise combination of dead clicks and revenue already covered.',
            ],
            170 => [
                'type' => 'gk_commerce_revenue_decline_30d',
                'title' => 'Commerce revenue declining (30 days) (-58.69%)',
                'classification' => self::FALSE_POSITIVE,
                'remediation_target' => 'stage1_data_fix',
                'notes' => 'False positive resulting from global order filter excluding Goldkamp confirmed orders.',
            ],
            171 => [
                'type' => 'gk_commerce_orders_decline_30d',
                'title' => 'Commerce orders declining (30 days) (-26.19%)',
                'classification' => self::FALSE_POSITIVE,
                'remediation_target' => 'stage1_data_fix',
                'notes' => 'False positive resulting from excluding confirmed orders, plus $11 unit mismatch.',
            ],
            172 => [
                'type' => 'gk_commerce_aov_decline_30d',
                'title' => 'Average order value declining (30 days) (-44.03%)',
                'classification' => self::FALSE_POSITIVE,
                'remediation_target' => 'stage1_data_fix',
                'notes' => 'Distorted by uncounted confirmed orders and fabricated $535.61 revenue loss.',
            ],
            173 => [
                'type' => 'gk_ga4_vs_commerce_revenue_variance_30d',
                'title' => 'GA4 vs Commerce revenue reporting variance (157.5% Discrepancy)',
                'classification' => self::FALSE_POSITIVE,
                'remediation_target' => 'stage1_data_fix',
                'notes' => 'Disproved: weekly variance was 1.87% and 30-day variance fell to 13.89% (under 20% rule threshold).',
            ],
            174 => [
                'type' => 'ga4_klaviyo_attribution_variance',
                'title' => 'GA4 vs Klaviyo Attribution Variance (100% Discrepancy)',
                'classification' => self::VALID_ACTIONABLE_ISSUE,
                'remediation_target' => 'marketing_utm_fix',
                'notes' => 'Genuine actionable issue: Klaviyo email campaigns lack UTM tagging, misclassifying email revenue in GA4 as direct/unassigned.',
            ],
            175 => [
                'type' => 'conversion_change_increase',
                'title' => 'Conversion rate increased 30.7%',
                'classification' => self::FALSE_POSITIVE,
                'remediation_target' => 'stage2_qualification',
                'notes' => 'Spurious single-day noise during abnormal traffic event.',
            ],
            176 => [
                'type' => 'script_errors_increase',
                'title' => 'JavaScript error rate increased 767.3%',
                'classification' => self::VALID_ACTIONABLE_ISSUE,
                'remediation_target' => 'engineering_pdp_fix',
                'notes' => 'Genuine technical issue: cart_item_options exception on packaging tapes affecting ~1,750 sessions/day.',
            ],
            177 => [
                'type' => 'revenue_script_error_correlation',
                'title' => 'Revenue down 23.1% while JavaScript errors up 767.3%',
                'classification' => self::FALSE_POSITIVE,
                'remediation_target' => 'stage1_and_stage2_fix',
                'notes' => 'Defective negative sign ($-29,391.40) and assumed causation linking packaging tape JS error to storewide revenue drop.',
            ],
            178 => [
                'type' => 'traffic_up_revenue_down_correlation',
                'title' => 'Traffic up 79.8% but revenue down 23.1%',
                'classification' => self::FALSE_POSITIVE,
                'remediation_target' => 'stage1_and_stage2_fix',
                'notes' => 'Defective negative sign ($-29,391.40) and assumed causation treating direct traffic surge as lost sales.',
            ],
            179 => [
                'type' => 'gk_ga4_conversion_rate_decline_30d',
                'title' => 'Conversion rate deteriorating (30 days) (-43.48%)',
                'classification' => self::VALID_DIAGNOSTIC_SIGNAL,
                'remediation_target' => 'diagnostic_traffic_surge',
                'notes' => 'Valid diagnostic signal: conversion rate was diluted by the non-converting direct traffic surge.',
            ],
            180 => [
                'type' => 'quick_backs_increase',
                'title' => 'Quickback rate increased 22.5%',
                'classification' => self::VALID_DIAGNOSTIC_SIGNAL,
                'remediation_target' => 'monitoring',
                'notes' => 'Valid diagnostic monitoring signal of catalog browse behavior.',
            ],
            181 => [
                'type' => 'funnel_overall_conversion_decrease',
                'title' => 'Funnel conversion (View → Purchase) declined 17.5%',
                'classification' => self::VALID_DIAGNOSTIC_SIGNAL,
                'remediation_target' => 'diagnostic_traffic_surge',
                'notes' => 'Valid diagnostic signal confirming view inflation from the traffic event.',
            ],
        ];
    }
}
