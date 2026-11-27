<?php

namespace App\Services\Intelligence;

use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingStatus;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\Finding;
use App\Services\Intelligence\Detectors\AccountContractionDetector;
use App\Services\Intelligence\Detectors\B2bAccountReactivationDetector;
use App\Services\Intelligence\Detectors\LongTermWinbackDetector;
use App\Services\Intelligence\Detectors\ReplenishmentCadenceDetector;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OpportunityExplanationService
{
    public const ACTIVE_DETECTOR_VERSIONS = [
        LongTermWinbackDetector::KEY         => LongTermWinbackDetector::VERSION,
        B2bAccountReactivationDetector::KEY  => B2bAccountReactivationDetector::VERSION,
        ReplenishmentCadenceDetector::KEY    => ReplenishmentCadenceDetector::VERSION,
        AccountContractionDetector::KEY      => AccountContractionDetector::VERSION,
    ];

    public function __construct(
        protected CustomerPurchaseAnalysisService $analysisService
    ) {}

    /**
     * Generate actionable, calibrated, context-rich explanations for business opportunities.
     *
     * @param  Finding  $finding
     * @param  mixed  $evidenceOrFact
     * @param  mixed  $factOrDisplay
     * @param  array|null  $customerDisplay
     * @param  array|null  $profile
     * @return array
     */
    public function explainFinding(
        Finding $finding,
        mixed $evidenceOrFact = null,
        mixed $factOrDisplay = null,
        ?array $customerDisplay = null,
        ?array $profile = null
    ): array {
        if (is_array($evidenceOrFact)) {
            $evidence = $evidenceOrFact;
            $fact = $factOrDisplay instanceof CommerceCustomerPurchaseFact ? $factOrDisplay : null;
            $customerDisplay = is_array($customerDisplay) ? $customerDisplay : (is_array($factOrDisplay) ? $factOrDisplay : null);
        } else {
            $fact = $evidenceOrFact instanceof CommerceCustomerPurchaseFact ? $evidenceOrFact : null;
            $customerDisplay = is_array($factOrDisplay) ? $factOrDisplay : $customerDisplay;
            $evidence = is_array($finding->evidence_json) ? $finding->evidence_json : json_decode($finding->evidence_json ?? '[]', true);
        }

        if ($customerDisplay === null) {
            $customerDisplay = [
                'primary_name'   => $finding->title ?? 'Account Opportunity',
                'customer_name'  => $finding->customer_name ?? ($evidence['customer_name'] ?? null) ?? ($finding->metadata_json['customer_name'] ?? null),
                'customer_email' => $finding->customer_email ?? ($evidence['customer_email'] ?? null) ?? ($finding->metadata_json['customer_email'] ?? null),
                'company_name'   => $finding->company_name ?? ($evidence['company_name'] ?? null) ?? ($finding->metadata_json['company_name'] ?? null),
            ];
        }

        $rawKey = (string) (
            $finding->source_id 
            ?? $finding->detector_key 
            ?? ($finding->metadata_json['detector_key'] ?? null)
            ?? ($evidence['detector_key'] ?? null)
            ?? ''
        );

        $detectorKey = str_starts_with($rawKey, 'opportunity.') 
            ? Str::after($rawKey, 'opportunity.') 
            : (str_starts_with($rawKey, 'risk.') ? Str::after($rawKey, 'risk.') : $rawKey);

        $impactNature = $finding->impact_nature instanceof \App\Enums\ImpactNature 
            ? $finding->impact_nature->value 
            : (string) ($finding->impact_nature ?? '');

        if (!$detectorKey || $detectorKey === 'opportunity' || $detectorKey === 'risk' || $detectorKey === '') {
            if ($impactNature === 'historical_purchasing_episode_benchmark' || str_contains(strtolower($finding->title ?? ''), 'winback')) {
                $detectorKey = 'long_term_winback';
            } elseif ($impactNature === 'historical_reorder_value' || str_contains(strtolower($finding->title ?? ''), 'replenishment')) {
                $detectorKey = 'replenishment_cadence';
            } elseif ($impactNature === 'observed_spending_decline' || str_contains(strtolower($finding->title ?? ''), 'contraction')) {
                $detectorKey = 'account_contraction';
            } elseif (str_contains(strtolower($finding->title ?? ''), 'reactivation')) {
                $detectorKey = 'b2b_account_reactivation';
            } else {
                $detectorKey = $finding->finding_type;
            }
        }

        $contactMetadata = $finding->metadata_json['contact_verification'] 
            ?? ($evidence['contact_verification'] ?? []);

        $contactAssessment = static::assessContactConsistency(
            customerName: $customerDisplay['customer_name'] ?? $finding->customer_name ?? ($evidence['customer_name'] ?? null) ?? $fact?->customer_name,
            customerEmail: $customerDisplay['customer_email'] ?? $finding->customer_email ?? ($evidence['customer_email'] ?? null) ?? $fact?->customer_email,
            companyName: $customerDisplay['company_name'] ?? $finding->company_name ?? ($evidence['company_name'] ?? null) ?? $fact?->company_name,
            contactMetadata: is_array($contactMetadata) ? $contactMetadata : []
        );

        $revalidation = $this->validateFindingEvidence($finding, $evidence, $fact);

        $isAccountOpportunity = !empty($finding->account_identity_hash) 
            || !empty($evidence['account_hash'])
            || !empty($finding->metadata_json['account_hash'])
            || in_array($detectorKey, ['long_term_winback', 'b2b_account_reactivation', 'replenishment_cadence', 'account_contraction', 'wholesale_retention'], true)
            || !empty($finding->customer_name)
            || !empty($finding->customer_email)
            || !empty($finding->metadata_json['account_label'])
            || !empty($evidence['account_label']);

        $res = match ($detectorKey) {
            'long_term_winback'         => $this->explainLongTermWinback($finding, $evidence, $fact, $customerDisplay, $contactAssessment, $profile, $revalidation),
            'b2b_account_reactivation'   => $this->explainB2bReactivation($finding, $evidence, $fact, $customerDisplay, $contactAssessment, $profile, $revalidation),
            'replenishment_cadence'      => $this->explainReplenishment($finding, $evidence, $fact, $customerDisplay, $contactAssessment, $profile, $revalidation),
            'account_contraction'       => $this->explainContraction($finding, $evidence, $fact, $customerDisplay, $contactAssessment, $profile, $revalidation),
            default                      => $isAccountOpportunity
                ? $this->explainGenericOrTechnical($finding, $evidence, $fact, $customerDisplay, $contactAssessment, $revalidation)
                : $this->explainOperationalOrDiagnostic($finding, $evidence, $revalidation),
        };

        if ($isAccountOpportunity) {
            $res['is_account_opportunity'] = true;
            $res['requires_revalidation'] = $revalidation['requires_revalidation'];
            $res['revalidation_reason']   = $revalidation['revalidation_reason'];
            $res['detector_version']      = $revalidation['detector_version'];
            $res['evaluated_at']          = $revalidation['evaluated_at'];
            $res['contact_status']        = $contactAssessment['status_key'];
            $res['contact_badge']         = $contactAssessment['badge_text'];
            $res['contact_badge_color']   = $contactAssessment['badge_color'];
            $res['contact_status_label']   = $contactAssessment['status_label'];
            $res['contact_provenance']    = $contactAssessment['provenance'];
            $res['contact_is_verified']   = (bool) ($contactAssessment['is_verified'] ?? false);
        } else {
            $res['is_account_opportunity'] = false;
        }

        return $res;
    }

    /**
     * Validate whether stored finding evidence matches the active versioned detector evaluation,
     * meets detector-specific required evidence schemas, and remains fresh relative to latest purchasing data.
     *
     * @param  Finding  $finding
     * @param  array  $evidence
     * @param  CommerceCustomerPurchaseFact|null  $fact
     * @return array
     */
    public function validateFindingEvidence(Finding $finding, array $evidence, ?CommerceCustomerPurchaseFact $fact = null): array
    {
        $rawKey = (string) (
            $finding->source_id 
            ?? $finding->detector_key 
            ?? ($finding->metadata_json['detector_key'] ?? null)
            ?? ($evidence['detector_key'] ?? null)
            ?? ''
        );

        if ($rawKey === 'account_contraction' || str_contains($rawKey, 'contraction')) {
            $fullKey = 'risk.account_contraction';
        } elseif (str_contains($rawKey, 'winback')) {
            $fullKey = 'opportunity.long_term_winback';
        } elseif (str_contains($rawKey, 'reactivation')) {
            $fullKey = 'opportunity.b2b_account_reactivation';
        } elseif (str_contains($rawKey, 'replenishment')) {
            $fullKey = 'opportunity.replenishment_cadence';
        } else {
            $fullKey = str_starts_with($rawKey, 'opportunity.') || str_starts_with($rawKey, 'risk.') 
                ? $rawKey 
                : 'opportunity.' . $rawKey;
        }

        $activeVersions = [
            'opportunity.long_term_winback'        => LongTermWinbackDetector::VERSION,
            'opportunity.b2b_account_reactivation' => B2bAccountReactivationDetector::VERSION,
            'opportunity.replenishment_cadence'    => ReplenishmentCadenceDetector::VERSION,
            'risk.account_contraction'             => AccountContractionDetector::VERSION,
        ];

        $expectedVersion = $activeVersions[$fullKey] ?? null;
        $storedVersion = $evidence['detector_version'] 
            ?? ($finding->impact_assumptions_json['detector_version'] ?? null) 
            ?? ($finding->metadata_json['detector_version'] ?? null);

        // 1. Missing or outdated detector version
        if ($expectedVersion && ($storedVersion === null || $storedVersion !== $expectedVersion)) {
            return [
                'requires_revalidation' => true,
                'revalidation_reason'   => "Detector version is missing or outdated (stored: " . ($storedVersion ?? 'none') . ", current: {$expectedVersion})",
                'detector_version'      => $storedVersion,
                'evaluated_at'          => $evidence['evaluated_at'] ?? null,
            ];
        }

        // 2. Detector-specific required authoritative evidence fields
        $requiredKeys = match (true) {
            str_contains($fullKey, 'winback') => [
                'cadence_threshold_days', 'days_since_last_purchase', 'days_overdue', 'evaluated_at'
            ],
            str_contains($fullKey, 'reactivation') => [
                'cadence_threshold_days', 'days_since_last_purchase', 'days_overdue', 'evaluated_at'
            ],
            str_contains($fullKey, 'replenishment') => [
                'cadence_threshold_days', 'days_since_last_purchase', 'evaluated_at', 'median_interval_days'
            ],
            str_contains($fullKey, 'contraction') => [
                'baseline_spend', 'eval_spend', 'evaluated_at'
            ],
            default => ['evaluated_at'],
        };

        foreach ($requiredKeys as $rk) {
            if (!isset($evidence[$rk])) {
                return [
                    'requires_revalidation' => true,
                    'revalidation_reason'   => "Required authoritative evidence key '{$rk}' is missing for {$fullKey}",
                    'detector_version'      => $storedVersion,
                    'evaluated_at'          => $evidence['evaluated_at'] ?? null,
                ];
            }
        }

        // 3. Stored values conflict with current evaluation
        if (str_contains($fullKey, 'winback') && isset($evidence['adaptive_multiplier']) && (float) $evidence['adaptive_multiplier'] > 3.201) {
            return [
                'requires_revalidation' => true,
                'revalidation_reason'   => "Stored adaptive multiplier ({$evidence['adaptive_multiplier']}) conflicts with Method D cap (3.2)",
                'detector_version'      => $storedVersion,
                'evaluated_at'          => $evidence['evaluated_at'] ?? null,
            ];
        }

        // 4. Stale or incomplete source cadence data
        if (str_contains($fullKey, 'winback') && (empty($evidence['median_interval_days']) && empty($evidence['cadence_days']))) {
            return [
                'requires_revalidation' => true,
                'revalidation_reason'   => 'Source cadence data is incomplete (missing median interval days)',
                'detector_version'      => $storedVersion,
                'evaluated_at'          => $evidence['evaluated_at'] ?? null,
            ];
        }

        // 5. Evidence freshness: Flag revalidation when newer purchasing data exists since evaluation
        if ($fact) {
            if (!empty($evidence['evaluated_at']) && $fact->latest_valid_order_at) {
                $evaluatedAt = \Illuminate\Support\Carbon::parse($evidence['evaluated_at']);
                if ($fact->latest_valid_order_at->gt($evaluatedAt)) {
                    return [
                        'requires_revalidation' => true,
                        'revalidation_reason'   => "Newer purchasing data detected: customer placed an order on {$fact->latest_valid_order_at->toDateString()} after evaluation at {$evaluatedAt->toDateString()}",
                        'detector_version'      => $storedVersion,
                        'evaluated_at'          => $evidence['evaluated_at'] ?? null,
                    ];
                }
            }

            $evaluatedOrders = $evidence['order_count'] ?? $evidence['episode_count'] ?? null;
            if ($evaluatedOrders !== null && $fact->lifetime_valid_order_count !== null) {
                if ((int) $fact->lifetime_valid_order_count > (int) $evaluatedOrders) {
                    return [
                        'requires_revalidation' => true,
                        'revalidation_reason'   => "Purchasing history count changed: account now has {$fact->lifetime_valid_order_count} lifetime orders (evaluated with {$evaluatedOrders})",
                        'detector_version'      => $storedVersion,
                        'evaluated_at'          => $evidence['evaluated_at'] ?? null,
                    ];
                }
            }
        }

        // 6. Explicit stale source data flag
        if (!empty($finding->metadata_json['source_data_stale']) || !empty($evidence['source_data_stale'])) {
            return [
                'requires_revalidation' => true,
                'revalidation_reason'   => 'Underlying customer purchase source data is flagged as stale',
                'detector_version'      => $storedVersion,
                'evaluated_at'          => $evidence['evaluated_at'] ?? null,
            ];
        }

        return [
            'requires_revalidation' => false,
            'revalidation_reason'   => null,
            'detector_version'      => $storedVersion ?? $expectedVersion,
            'evaluated_at'          => $evidence['evaluated_at'] ?? null,
        ];
    }

    /**
     * Explain Long-Term Enterprise Winback opportunities strictly from authoritative detector evidence.
     */
    protected function explainLongTermWinback(
        Finding $finding,
        array $evidence,
        ?CommerceCustomerPurchaseFact $fact,
        array $customerDisplay,
        array $contact,
        ?array $profile = null,
        array $revalidation = []
    ): array {
        $customerName = $customerDisplay['primary_name'] ?? 'Account';
        $requiresRevalidation = $revalidation['requires_revalidation'] ?? false;
        $revalidationReason = $revalidation['revalidation_reason'] ?? null;

        // Authoritative detector evidence values
        $medianInterval = (int) ($evidence['median_interval_days'] ?? $evidence['cadence_days'] ?? 45);
        $cv = isset($evidence['variance_cv']) 
            ? (float) $evidence['variance_cv'] 
            : (isset($evidence['cadence_variability_cv']) ? (float) $evidence['cadence_variability_cv'] : null);
        $adaptiveMultiplier = isset($evidence['adaptive_multiplier']) 
            ? (float) $evidence['adaptive_multiplier'] 
            : 2.0;

        $rawCadenceThreshold = isset($evidence['cadence_threshold_days'])
            ? (int) $evidence['cadence_threshold_days']
            : null;

        // Prioritize the effective Winback threshold used to qualify eligibility (never display raw 118d cadence as 181d eligibility threshold)
        $authoritativeThreshold = isset($evidence['effective_min_dormancy'])
            ? (int) $evidence['effective_min_dormancy']
            : ($rawCadenceThreshold !== null ? max(181, $rawCadenceThreshold) : null);

        $daysInactive = (int) ($evidence['days_since_last_purchase'] 
            ?? $evidence['days_since_last_order'] 
            ?? $evidence['days_inactive'] 
            ?? $fact?->days_since_last_order 
            ?? 0);

        // Fallback calculation for mock or unmigrated tests
        if ($authoritativeThreshold === null) {
            $methodD = static::calculateMethodDThreshold($medianInterval, $cv, minDormancy: 181);
            $authoritativeThreshold = $methodD['effective_threshold'];
            $rawCadenceThreshold = $methodD['cadence_days'];
            $adaptiveMultiplier = $methodD['multiplier'];
        } elseif ($rawCadenceThreshold === null) {
            $rawCadenceThreshold = (int) round($medianInterval * $adaptiveMultiplier);
        }

        $overdueDays = isset($evidence['days_overdue']) 
            ? (int) $evidence['days_overdue'] 
            : max(0, $daysInactive - $authoritativeThreshold);

        $storedThreshold = isset($evidence['threshold']) ? (int) $evidence['threshold'] : null;
        $hasLegacyThreshold = $storedThreshold && abs($storedThreshold - $authoritativeThreshold) > 5;

        // Purchasing interval deviation ratio strictly against median purchasing interval (never threshold ratio labeled as "normal")
        $intervalDeviationRatio = $medianInterval > 0 ? round($daysInactive / $medianInterval, 1) : null;
        $thresholdRatio = $authoritativeThreshold > 0 ? round($daysInactive / $authoritativeThreshold, 2) : 1.0;

        $episodes = (int) ($evidence['episode_count'] ?? $evidence['purchasing_episodes_count'] ?? $fact?->lifetime_valid_order_count ?? 0);
        $lifetimeSpend = (float) ($evidence['lifetime_net_purchasing_value'] ?? $evidence['lifetime_spend'] ?? $fact?->lifetime_net_revenue ?? 0.0);
        $benchmark = (float) ($evidence['historical_episode_benchmark'] ?? $finding->estimated_revenue_impact ?? 0.0);

        // Order-header commercial summary pre-loaded via CustomerPurchaseAnalysisService (zero DB queries here)
        $orderSummary = is_array($evidence['winback_order_summary'] ?? null) ? $evidence['winback_order_summary'] : [];
        $lastOrderAtRaw = $orderSummary['last_order_at'] ?? ($evidence['latest_order_at'] ?? $evidence['latest_order'] ?? null);
        $lastOrderDateFormatted = null;
        if ($lastOrderAtRaw) {
            try {
                $lastOrderDateFormatted = Carbon::parse($lastOrderAtRaw)->format('M j, Y');
            } catch (\Throwable) {
                $lastOrderDateFormatted = (string) $lastOrderAtRaw;
            }
        }
        $lastOrderAmount = isset($orderSummary['last_order_net_revenue']) ? (float) $orderSummary['last_order_net_revenue'] : null;
        $preDormancy12mSpend = isset($orderSummary['pre_dormancy_12m_spend']) ? (float) $orderSummary['pre_dormancy_12m_spend'] : null;
        $preDormancy12mOrders = isset($orderSummary['pre_dormancy_12m_orders']) ? (int) $orderSummary['pre_dormancy_12m_orders'] : null;
        $peakMonths = ! empty($orderSummary['peak_ordering_months']) && is_array($orderSummary['peak_ordering_months'])
            ? $orderSummary['peak_ordering_months']
            : [];

        $evalAtRaw = $revalidation['evaluated_at'] ?? ($evidence['evaluated_at'] ?? null);
        $evaluatedDateFormatted = null;
        if ($evalAtRaw) {
            try {
                $evaluatedDateFormatted = Carbon::parse($evalAtRaw)->format('M j, Y');
            } catch (\Throwable) {
                $evaluatedDateFormatted = (string) $evalAtRaw;
            }
        }

        // 1. One concise, mathematically consistent explanation of the business issue
        $revalNotice = $requiresRevalidation ? " [Revalidation Required: {$revalidationReason}]" : '';
        $lastPurchaseClause = $lastOrderDateFormatted
            ? (' (last qualifying purchase ' . $lastOrderDateFormatted . ($lastOrderAmount !== null ? (', $' . number_format($lastOrderAmount, 2)) : '') . ')')
            : '';
        $intervalClause = $intervalDeviationRatio !== null
            ? " ({$intervalDeviationRatio}x its typical {$medianInterval}-day purchasing interval)"
            : '';
        $preDormancyClause = ($preDormancy12mSpend !== null && $preDormancy12mSpend > 0 && $preDormancy12mOrders !== null)
            ? (' Pre-dormancy 12-month spend totaled $' . number_format($preDormancy12mSpend, 2) . " across {$preDormancy12mOrders} " . Str::plural('order', $preDormancy12mOrders) . '.')
            : '';

        $explanation = "{$customerName} has been inactive for {$daysInactive} days{$lastPurchaseClause}, exceeding its effective {$authoritativeThreshold}-day Winback threshold by {$overdueDays} days{$intervalClause}. Prior history reflects {$episodes} purchasing episodes totaling $" . number_format($lifetimeSpend, 2) . " in lifetime spend.{$preDormancyClause}{$revalNotice}";

        // 2. Three meaningful supporting metrics
        $supportingMetrics = [
            [
                'label'   => 'Inactivity vs Threshold',
                'value'   => "{$daysInactive}d (Effective: {$authoritativeThreshold}d)",
                'subtext' => "+{$overdueDays}d past Winback eligibility" . ($lastOrderDateFormatted ? " · Last: {$lastOrderDateFormatted}" : ''),
                'color'   => 'rose',
            ],
            [
                'label'   => 'Cadence & Interval Ratio',
                'value'   => $intervalDeviationRatio !== null ? "{$intervalDeviationRatio}x typical cycle" : "+{$overdueDays}d overdue",
                'subtext' => "{$medianInterval}d median interval · Raw cadence: {$rawCadenceThreshold}d",
                'color'   => 'amber',
            ],
            [
                'label'   => 'Commercial Scale',
                'value'   => "{$episodes} episodes · $" . number_format($benchmark, 2),
                'subtext' => ($preDormancy12mSpend !== null && $preDormancy12mSpend > 0
                    ? ('$' . number_format($preDormancy12mSpend, 2) . ' in final 12m · ')
                    : '') . '$' . number_format($lifetimeSpend, 2) . ' lifetime',
                'color'   => 'primary',
            ],
        ];

        // 3. Evidence-grounded recommended action enriching contact verification with order-header patterns
        $commercialAngleParts = [];
        if ($lastOrderDateFormatted && $lastOrderAmount !== null) {
            $commercialAngleParts[] = "reference last order of $" . number_format($lastOrderAmount, 2) . " on {$lastOrderDateFormatted}";
        } elseif ($lastOrderDateFormatted) {
            $commercialAngleParts[] = "reference last order on {$lastOrderDateFormatted}";
        }
        if ($preDormancy12mSpend !== null && $preDormancy12mSpend > 0 && $preDormancy12mOrders !== null) {
            $commercialAngleParts[] = "historical {$medianInterval}-day reorder cycle ($" . number_format($preDormancy12mSpend, 2) . " / {$preDormancy12mOrders} orders in final active year)";
        } else {
            $commercialAngleParts[] = "typical $" . number_format($benchmark, 2) . " episode basket across {$episodes} historical episodes";
        }
        if (! empty($peakMonths)) {
            $commercialAngleParts[] = 'prior ordering peaks in ' . implode(' & ', $peakMonths);
        }
        $recommendedAction = rtrim($contact['recommended_action'], '.') . ' When engaging, ' . implode(', ', $commercialAngleParts) . '.';

        // 4. Outcome presentation
        $outcomePresentation = $this->buildOutcomePresentation($finding);

        // 5. Expandable detailed view (retaining raw cadence math alongside effective threshold & order-line data limitation)
        $floorNote = ($rawCadenceThreshold < $authoritativeThreshold)
            ? " Because {$rawCadenceThreshold}d falls below the {$authoritativeThreshold}d Winback minimum floor, eligibility is governed by the {$authoritativeThreshold}-day effective Winback threshold."
            : " Effective Winback threshold matches the calibrated {$authoritativeThreshold}d cadence threshold.";
        $calibrationNotes = $hasLegacyThreshold 
            ? "Method D: {$medianInterval}d median interval × {$adaptiveMultiplier} adaptive multiplier = {$rawCadenceThreshold}d raw cadence threshold.{$floorNote} Finding record originally listed {$storedThreshold}d under legacy 4.0x model."
            : "Method D: {$medianInterval}d median interval × {$adaptiveMultiplier} adaptive multiplier = {$rawCadenceThreshold}d raw cadence threshold.{$floorNote}";

        $expandableDetails = [
            'opportunity_type'            => 'Long-Term Enterprise Winback',
            'relationship_tier'           => $evidence['relationship_tier'] ?? 'Proven Recurring Account',
            'cadence_methodology'         => $calibrationNotes,
            'median_cadence_days'         => $medianInterval,
            'raw_cadence_threshold_days'  => $rawCadenceThreshold,
            'calibrated_threshold_days'   => $authoritativeThreshold,
            'effective_threshold_days'    => $authoritativeThreshold,
            'days_since_last_purchase'    => $daysInactive,
            'days_overdue_effective'      => $overdueDays,
            'interval_deviation_ratio'    => $intervalDeviationRatio,
            'threshold_ratio'             => $thresholdRatio,
            'cadence_variability_cv'      => $cv,
            'calibrated_multiplier'       => $adaptiveMultiplier,
            'last_order_date_formatted'   => $lastOrderDateFormatted,
            'last_order_amount'           => $lastOrderAmount,
            'last_order_increment_id'     => $orderSummary['last_order_increment_id'] ?? null,
            'pre_dormancy_12m_spend'      => $preDormancy12mSpend,
            'pre_dormancy_12m_orders'     => $preDormancy12mOrders,
            'peak_ordering_months'        => $peakMonths,
            'order_line_limitation_note'  => 'Order-header telemetry only (dates, frequencies, and spend). SKU/category-level line-item history is not yet synced into Forge.',
            'financial_definitions'       => [
                'lifetime_spend_definition'    => '$' . number_format($lifetimeSpend, 2) . ': Cumulative customer purchases across all recorded valid orders (scale benchmark, not projected revenue).',
                'episode_benchmark_definition' => '$' . number_format($benchmark, 2) . ': Average historical order value per purchasing episode, serving as a baseline reference for typical reorders.',
                'Historical Lifetime Spend'    => '$' . number_format($lifetimeSpend, 2) . ': Cumulative customer purchases across all recorded valid orders (scale benchmark, not projected revenue).',
                'Episode Benchmark'            => '$' . number_format($benchmark, 2) . ': Average historical order value per purchasing episode, serving as a baseline reference for typical reorders.',
            ],
            'contact_status'              => $contact['status_label'] ?? $contact['contact_label'],
            'contact_verification'        => $contact['reason'],
            'contact_provenance'          => $contact['provenance'] ?? null,
            'first_order_date'            => $evidence['first_order_at'] ?? $evidence['first_order'] ?? null,
            'latest_order_date'           => $lastOrderAtRaw,
            'order_span_days'             => $evidence['order_span_days'] ?? $evidence['order_span'] ?? null,
            'has_legacy_discrepancy'      => $hasLegacyThreshold,
            'requires_revalidation'       => $requiresRevalidation,
            'revalidation_reason'         => $revalidationReason,
            'detector_version'            => $revalidation['detector_version'] ?? ($evidence['detector_version'] ?? '2.1.0-method-d'),
            'evaluated_at'                => $evalAtRaw,
            'evaluated_date_formatted'    => $evaluatedDateFormatted,
        ];

        return [
            'opportunity_type'         => 'Long-Term Enterprise Winback',
            'concise_explanation'      => $explanation,
            'supporting_metrics'       => $supportingMetrics,
            'recommended_action'       => $recommendedAction,
            'outcome_presentation'     => $outcomePresentation,
            'last_order_date_label'    => $lastOrderDateFormatted,
            'last_order_amount'        => $lastOrderAmount,
            'evaluated_date_label'     => $evaluatedDateFormatted,
            'expandable_details'       => $expandableDetails,
        ];
    }

    /**
     * Explain B2B Account Reactivation opportunities (recently dormant accounts).
     */
    protected function explainB2bReactivation(
        Finding $finding,
        array $evidence,
        ?CommerceCustomerPurchaseFact $fact,
        array $customerDisplay,
        array $contact,
        ?array $profile = null,
        array $revalidation = []
    ): array {
        $customerName = $customerDisplay['primary_name'] ?? 'Account';
        $requiresRevalidation = $revalidation['requires_revalidation'] ?? false;
        $daysInactive = (int) ($evidence['days_since_last_purchase'] ?? $evidence['days_since_last_order'] ?? $fact?->days_since_last_order ?? 0);
        $threshold = (int) ($evidence['cadence_threshold_days'] ?? $evidence['dormancy_threshold_days'] ?? $evidence['threshold'] ?? 60);
        $medianInterval = (int) ($evidence['median_interval_days'] ?? $evidence['median_interval'] ?? 30);
        $orders = (int) ($evidence['order_count'] ?? $fact?->lifetime_valid_order_count ?? 0);
        $aov = (float) ($evidence['historical_aov'] ?? $finding->estimated_revenue_impact ?? 0.0);
        $lifetimeSpend = (float) ($evidence['lifetime_net_purchasing_value'] ?? $fact?->lifetime_net_revenue ?? 0.0);

        $overdue = isset($evidence['days_overdue']) ? (int) $evidence['days_overdue'] : max(0, $daysInactive - $threshold);

        $explanation = "{$customerName} has been inactive for {$daysInactive} days, exceeding the {$threshold}-day dormancy threshold by {$overdue} days. Account has {$orders} past orders averaging $" . number_format($aov, 2) . " per order.";

        $supportingMetrics = [
            [
                'label'   => 'Inactivity Window',
                'value'   => "{$daysInactive}d (Threshold: {$threshold}d)",
                'subtext' => "{$overdue}d beyond expected reorder",
                'color'   => 'amber',
            ],
            [
                'label'   => 'Cadence Interval',
                'value'   => "{$medianInterval}d median",
                'subtext' => "Based on {$orders} historical orders",
                'color'   => 'primary',
            ],
            [
                'label'   => 'Order Benchmark',
                'value'   => "$" . number_format($aov, 2) . " AOV",
                'subtext' => "$" . number_format($lifetimeSpend, 2) . " lifetime spend",
                'color'   => 'emerald',
            ],
        ];

        return [
            'opportunity_type'     => 'B2B Account Reactivation',
            'concise_explanation' => $explanation,
            'supporting_metrics'   => $supportingMetrics,
            'recommended_action'   => $contact['recommended_action'],
            'outcome_presentation' => $this->buildOutcomePresentation($finding),
            'expandable_details'   => [
                'opportunity_type'      => 'B2B Account Reactivation',
                'relationship_tier'     => $evidence['relationship_tier'] ?? 'Standard Account',
                'cadence_methodology'   => "Dormancy threshold calculated at {$threshold} days based on {$medianInterval}-day median cycle.",
                'financial_definitions' => [
                    'Historical Reorder Value' => "$" . number_format($aov, 2) . ": Historical average order value.",
                    'Lifetime Spend'           => "$" . number_format($lifetimeSpend, 2) . ": Cumulative recorded purchases.",
                ],
                'contact_status'        => $contact['status_label'] ?? $contact['contact_label'],
                'contact_verification'  => $contact['reason'],
                'contact_provenance'    => $contact['provenance'] ?? null,
                'first_order_date'      => $evidence['first_order_at'] ?? null,
                'latest_order_date'     => $evidence['latest_order_at'] ?? null,
                'requires_revalidation' => $requiresRevalidation,
                'revalidation_reason'   => $revalidation['revalidation_reason'] ?? null,
                'detector_version'      => $revalidation['detector_version'] ?? ($evidence['detector_version'] ?? '2.0.0'),
                'evaluated_at'          => $revalidation['evaluated_at'] ?? ($evidence['evaluated_at'] ?? null),
            ],
        ];
    }

    /**
     * Explain Replenishment Cadence opportunities (routine consumable buyers).
     */
    protected function explainReplenishment(
        Finding $finding,
        array $evidence,
        ?CommerceCustomerPurchaseFact $fact,
        array $customerDisplay,
        array $contact,
        ?array $profile = null,
        array $revalidation = []
    ): array {
        $customerName = $customerDisplay['primary_name'] ?? 'Account';
        $requiresRevalidation = $revalidation['requires_revalidation'] ?? false;
        $daysSince = (int) ($evidence['days_since_last_purchase'] ?? $evidence['days_since_last_order'] ?? $fact?->days_since_last_order ?? 0);
        $cadenceDays = (int) ($evidence['cadence_threshold_days'] ?? $evidence['median_interval_days'] ?? $evidence['cadence_days'] ?? $evidence['cadence'] ?? 30);
        $daysOverdue = isset($evidence['days_overdue']) ? (int) $evidence['days_overdue'] : max(0, $daysSince - $cadenceDays);
        $aov = (float) ($evidence['historical_aov'] ?? $finding->estimated_revenue_impact ?? 0.0);
        $orders = (int) ($evidence['lifetime_orders'] ?? $evidence['order_count'] ?? $fact?->lifetime_valid_order_count ?? 0);
        $cv = isset($evidence['variance_cv']) ? round((float) $evidence['variance_cv'], 2) : (isset($evidence['cv']) ? round((float) $evidence['cv'], 2) : 0.25);

        $explanation = "{$customerName} is {$daysOverdue} days past their established {$cadenceDays}-day consumable replenishment cycle (last ordered {$daysSince} days ago). Historical reorders average $" . number_format($aov, 2) . ".";

        $supportingMetrics = [
            [
                'label'   => 'Replenishment Cycle',
                'value'   => "{$cadenceDays}d interval",
                'subtext' => "Regular buyer (cv: {$cv})",
                'color'   => 'primary',
            ],
            [
                'label'   => 'Cycle Timing',
                'value'   => "+{$daysOverdue}d overdue",
                'subtext' => "{$daysSince}d since last order",
                'color'   => 'amber',
            ],
            [
                'label'   => 'Expected Reorder',
                'value'   => "$" . number_format($aov, 2) . " AOV",
                'subtext' => "Across {$orders} repeat orders",
                'color'   => 'emerald',
            ],
        ];

        return [
            'opportunity_type'     => 'Consumable Replenishment',
            'concise_explanation' => $explanation,
            'supporting_metrics'   => $supportingMetrics,
            'recommended_action'   => $contact['recommended_action'],
            'outcome_presentation' => $this->buildOutcomePresentation($finding),
            'expandable_details'   => [
                'opportunity_type'      => 'Consumable Replenishment',
                'relationship_tier'     => 'Regular Consumable Purchaser',
                'cadence_methodology'   => "Regularity confirmed via low purchasing variance (cv: {$cv} <= 0.50) with {$cadenceDays}-day median interval.",
                'financial_definitions' => [
                    'Historical Reorder Value' => "$" . number_format($aov, 2) . ": Historical average order value for routine replenishment.",
                ],
                'contact_status'        => $contact['status_label'] ?? $contact['contact_label'],
                'contact_verification'  => $contact['reason'],
                'contact_provenance'    => $contact['provenance'] ?? null,
                'requires_revalidation' => $requiresRevalidation,
                'revalidation_reason'   => $revalidation['revalidation_reason'] ?? null,
                'detector_version'      => $revalidation['detector_version'] ?? ($evidence['detector_version'] ?? '2.0.0'),
                'evaluated_at'          => $revalidation['evaluated_at'] ?? ($evidence['evaluated_at'] ?? null),
            ],
        ];
    }

    /**
     * Explain Account Contraction (revenue contraction risk).
     */
    protected function explainContraction(
        Finding $finding,
        array $evidence,
        ?CommerceCustomerPurchaseFact $fact,
        array $customerDisplay,
        array $contact,
        ?array $profile = null,
        array $revalidation = []
    ): array {
        $customerName = $customerDisplay['primary_name'] ?? 'Account';
        $requiresRevalidation = $revalidation['requires_revalidation'] ?? false;
        $declineAmount = (float) ($evidence['decline_amount'] ?? $evidence['revenue_drop'] ?? $finding->estimated_revenue_impact ?? 0.0);
        $declinePct = round((float) ($evidence['decline_pct'] ?? $evidence['decline_percentage'] ?? $evidence['contraction_pct'] ?? 0.0), 1);
        $baselineSpend = (float) ($evidence['baseline_spend'] ?? $evidence['baseline_quarterly_spend'] ?? 0.0);
        $evalSpend = (float) ($evidence['eval_spend'] ?? $evidence['current_quarter_spend'] ?? 0.0);

        $explanation = "{$customerName} spending contracted by {$declinePct}% over the trailing 180 days, dropping from a $" . number_format($baselineSpend, 2) . " quarterly baseline to $" . number_format($evalSpend, 2) . " (shortfall of $" . number_format($declineAmount, 2) . ").";

        $supportingMetrics = [
            [
                'label'   => 'Spending Decline',
                'value'   => "-$" . number_format($declineAmount, 2),
                'subtext' => "-{$declinePct}% contraction",
                'color'   => 'rose',
            ],
            [
                'label'   => 'Quarterly Baseline',
                'value'   => "$" . number_format($baselineSpend, 2),
                'subtext' => 'Historical baseline run-rate',
                'color'   => 'gray',
            ],
            [
                'label'   => 'Recent Spend',
                'value'   => "$" . number_format($evalSpend, 2),
                'subtext' => 'Contracted quarterly volume',
                'color'   => 'amber',
            ],
        ];

        return [
            'opportunity_type'     => 'Account Contraction Risk',
            'concise_explanation' => $explanation,
            'supporting_metrics'   => $supportingMetrics,
            'recommended_action'   => $contact['recommended_action'],
            'outcome_presentation' => $this->buildOutcomePresentation($finding),
            'expandable_details'   => [
                'opportunity_type'      => 'Account Contraction Risk',
                'baseline_spend'        => $baselineSpend,
                'eval_spend'            => $evalSpend,
                'decline_amount'        => $declineAmount,
                'decline_percentage'    => $declinePct,
                'financial_definitions' => [
                    'Spending Contraction' => "$" . number_format($declineAmount, 2) . ": Trailing run-rate drop against historical baseline.",
                ],
                'contact_status'        => $contact['status_label'] ?? $contact['contact_label'],
                'contact_verification'  => $contact['reason'],
                'contact_provenance'    => $contact['provenance'] ?? null,
                'requires_revalidation' => $requiresRevalidation,
                'revalidation_reason'   => $revalidation['revalidation_reason'] ?? null,
                'detector_version'      => $revalidation['detector_version'] ?? ($evidence['detector_version'] ?? '2.0.0'),
                'evaluated_at'          => $revalidation['evaluated_at'] ?? ($evidence['evaluated_at'] ?? null),
            ],
        ];
    }

    /**
     * Explanation for operational, analytics, marketing, and technical findings.
     * Prevents assuming non-account findings are customers, avoids misleading $0.00 pipeline,
     * distinguishes observations from implications, and provides freshness badges.
     */
    public function explainOperationalOrDiagnostic(
        Finding $finding,
        array $evidence,
        array $revalidation = []
    ): array {
        $metadata = is_array($finding->metadata_json)
            ? $finding->metadata_json
            : (json_decode($finding->metadata_json ?? '[]', true) ?: []);

        $type = (string) ($finding->finding_type ?? '');
        $ruleKey = (string) ($metadata['rule_key'] ?? '');
        $family = (string) ($metadata['primary_signal']['family'] ?? '');
        $category = (string) ($finding->finding_category?->value ?? $finding->finding_category ?? '');

        // 1. Resolve Scope & Target Team
        $classification = FindingTypeRegistry::classify($finding);
        $scopeType = $metadata['scope_type'] ?? $finding->source_type ?? null;
        $scopeTarget = $metadata['scope_target'] ?? $finding->source_id ?? null;

        // Strip legacy unverified page stub on storewide Clarity findings
        $isLegacyClarityStub = ($scopeTarget === 'tapes_packaging_tapes');
        if ($isLegacyClarityStub) {
            $scopeType = 'sitewide';
            $scopeTarget = 'clarity_script_errors';
        }

        $scopeLabel = $scopeType && $scopeTarget
            ? (Str::headline($scopeType) . ': ' . str_replace('_', ' ', $scopeTarget))
            : ($scopeTarget ?? 'Site-wide');

        $targetTeam = $metadata['actionability']['target_team'] ?? $finding->target_role ?? $classification['target_role'];
        if (! $targetTeam) {
            $targetTeam = match ($category) {
                'Technical' => 'engineering',
                'Conversion', 'Attribution' => 'marketing_operations',
                'Behavioral' => 'analytics',
                default => 'ecommerce_operations',
            };
        }

        $targetTeamLabel = match ($targetTeam) {
            'engineering' => 'Engineering',
            'marketing_operations', 'marketing' => 'Marketing Operations',
            'analytics' => 'Analytics',
            'ecommerce_operations', 'e_commerce' => 'E-commerce Operations',
            'sales', 'executive_sales' => 'Executive Sales',
            default => Str::headline($targetTeam),
        };

        // 2. Data Freshness & Observation Period (6-timestamp contract passed from FindingFreshnessService, never updated_at!)
        $freshness = is_array($evidence['freshness_contract'] ?? null) ? $evidence['freshness_contract'] : [];
        $firstDetectedAt = $freshness['first_detected_at']
            ?? $finding->detected_at?->toIso8601String()
            ?? ($metadata['primary_signal']['detected_at'] ?? null);
        $lastObservedAt = $freshness['last_observed_at']
            ?? $evidence['last_observed_at']
            ?? ($metadata['source_window_end'] ?? $firstDetectedAt);
        $periodDays = $metadata['period_days'] ?? ($evidence['period_days'] ?? null);

        $isOutdated = (bool) ($freshness['is_outdated'] ?? false);
        $requiresRevalidation = (bool) ($freshness['requires_revalidation'] ?? $isOutdated);
        $freshnessBadge = $freshness['badge'] ?? 'Observation Window Unverified';
        $freshnessColor = $freshness['color'] ?? 'gray';
        $freshnessNote = $freshness['note'] ?? 'Source observation telemetry recorded.';

        if (empty($freshness) && $lastObservedAt) {
            try {
                $observedDate = \Carbon\Carbon::parse($lastObservedAt);
                $daysAgo = (int) round($observedDate->diffInDays(now()));
                if ($daysAgo > 7) {
                    $isOutdated = true;
                    $requiresRevalidation = true;
                    $freshnessBadge = 'Historical Observation (' . $observedDate->format('M j, Y') . ')';
                    $freshnessColor = 'amber';
                    $freshnessNote = "Historical observation ({$daysAgo} days ago): requires re-validation against current source logs.";
                } else {
                    $freshnessBadge = 'Current Evidence (Through ' . $observedDate->format('M j, Y') . ')';
                    $freshnessColor = 'emerald';
                    $freshnessNote = 'Observation verified against recent source telemetry.';
                }
            } catch (\Throwable) {
                // Keep defaults
            }
        }

        $observationPeriodText = $freshness['observation_period_label']
            ?? ($periodDays ? "Trailing {$periodDays} days" : 'Monitoring episode');

        // 3. Scenario-Specific Evidence Extraction (Strict consumer of verified/analytical evidence)
        $isScriptError = str_contains($type, 'script_error') || str_contains($family, 'script_error');
        $isCampaignTagging = str_contains($type, 'campaign_tagging') || str_contains($family, 'campaign_tagging');
        $isTrafficSurge = str_contains($type, 'volume_surge') || str_contains($family, 'volume_surge') || str_contains($type, 'traffic');
        $isCvrDecline = str_contains($type, 'conversion_rate') || str_contains($ruleKey, 'conversion_rate');
        $isAttributionVariance = str_contains($type, 'attribution_variance') || str_contains($type, 'platform_variance') || str_contains($ruleKey, 'attribution_variance');
        $isRevenueDrop = str_contains($type, 'revenue_drop') || str_contains($ruleKey, 'revenue_drop');

        $opportunityType = Str::headline($finding->finding_type ?? 'Operational Finding');
        $supportingMetrics = [];
        $displayTitle = $finding->title ?: $opportunityType;

        if ($isScriptError) {
            $errorCount = (int) ($evidence['error_count'] ?? $metadata['primary_signal']['evidence']['error_count'] ?? 0);
            $monitoredSessions = (int) ($evidence['monitored_sessions'] ?? $evidence['clarity_sessions'] ?? $metadata['primary_signal']['evidence']['clarity_sessions'] ?? 0);
            $affectedPct = $evidence['affected_session_pct'] ?? null;
            $estimatedAffected = $evidence['estimated_affected_sessions'] ?? null;
            $dailyPctRange = $evidence['daily_affected_session_pct_range'] ?? null;
            $ratePerSession = $monitoredSessions > 0 ? round($errorCount / $monitoredSessions, 2) : (float) ($metadata['primary_signal']['current_value'] ?? 0.0);

            if ($isLegacyClarityStub || str_contains(strtolower($displayTitle), 'packaging tapes') || str_contains($displayTitle, '+216.5%')) {
                $displayTitle = 'Storewide Clarity JavaScript errors elevated';
            }

            $primaryMetricValue = number_format($errorCount) . ' Errors';
            $primaryMetricLabel = 'Storewide Clarity Script Errors';

            $affectedText = ($affectedPct !== null && $estimatedAffected !== null)
                ? (" (~" . number_format((int) $estimatedAffected) . " sessions / " . number_format((float) $affectedPct, 1) . "% affected in single-day telemetry)")
                : ($dailyPctRange ? " (daily session share {$dailyPctRange[0]}%–{$dailyPctRange[1]}%)" : '');

            $confirmedObservation = number_format($errorCount) . " storewide JavaScript exceptions logged in Microsoft Clarity across " . number_format($monitoredSessions) . " monitored sessions ({$ratePerSession} errors/monitored session){$affectedText}. Clarity reports project-level counts without per-URL exception stack traces.";
            $possibleImplication = "Elevated front-end script errors can degrade buyer interactions across storefront pages; verify console logs and affected templates before estimating commercial impact (error volume does not establish lost sales).";
            $recommendedAction = "Task {$targetTeamLabel} with reproducing front-end console exceptions across key storefront templates and reviewing browser error telemetry.";

            $affectedSubtext = ($affectedPct !== null && $estimatedAffected !== null)
                ? ("~" . number_format((int) $estimatedAffected) . " sessions with errors ({$affectedPct}%)")
                : 'Total Clarity traffic (not error-affected count)';

            $supportingMetrics = [
                ['label' => 'Script Error Events', 'value' => number_format($errorCount), 'subtext' => "{$ratePerSession} per monitored session", 'color' => 'rose'],
                ['label' => 'Monitored Sessions', 'value' => number_format($monitoredSessions), 'subtext' => $affectedSubtext, 'color' => 'amber'],
                ['label' => 'Telemetry Scope', 'value' => 'Storewide (Clarity)', 'subtext' => 'Page/stacktrace not synced', 'color' => 'gray'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Remediation owner', 'color' => 'primary'],
            ];
        } elseif ($isCampaignTagging) {
            $klaviyoRev = (float) ($evidence['klaviyo_revenue'] ?? $metadata['primary_signal']['evidence']['klaviyo_revenue'] ?? 0.0);
            $ga4EmailRev = (float) ($evidence['ga4_email_revenue'] ?? $metadata['primary_signal']['evidence']['ga4_email_revenue'] ?? 0.0);
            $issue = $evidence['issue'] ?? $metadata['primary_signal']['evidence']['issue'] ?? 'Email attribution discrepancy';

            $primaryMetricValue = '$' . number_format($klaviyoRev, 2);
            $primaryMetricLabel = 'Klaviyo Attributed Revenue';

            $confirmedObservation = "Email platform attributed $" . number_format($klaviyoRev, 2) . " in revenue while GA4 Email channel recorded $" . number_format($ga4EmailRev, 2) . ". Campaign link UTM parameters have not been directly inspected.";
            $possibleImplication = "Attribution model differences (including open/click windows vs GA4 session-click attribution) or un-tagged links can cause GA4 to omit email credit. This reflects reporting attribution differences, not lost sales.";
            $recommendedAction = "Task {$targetTeamLabel} with reviewing Klaviyo attribution windows, verifying campaign link UTM tagging, and reconciling email reporting against GA4.";

            $supportingMetrics = [
                ['label' => 'Klaviyo Attributed Rev', 'value' => '$' . number_format($klaviyoRev, 2), 'subtext' => 'Platform attributed revenue (not ad spend)', 'color' => 'blue'],
                ['label' => 'GA4 Email Revenue', 'value' => '$' . number_format($ga4EmailRev, 2), 'subtext' => 'GA4 session-attributed email channel', 'color' => 'gray'],
                ['label' => 'Classification', 'value' => 'Attribution Variance', 'subtext' => 'Reporting alignment (not lost sales)', 'color' => 'amber'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Configuration owner', 'color' => 'primary'],
            ];
        } elseif ($isTrafficSurge) {
            $surgePct = (float) ($evidence['surge_pct'] ?? $metadata['primary_signal']['evidence']['surge_pct'] ?? 0.0);
            $directShare = (float) ($evidence['direct_share_pct'] ?? $metadata['primary_signal']['evidence']['direct_share_pct'] ?? 0.0);
            $currentVal = (int) ($evidence['direct_sessions'] ?? $metadata['primary_signal']['current_value'] ?? 0);
            $previousVal = (int) ($evidence['prior_direct_sessions'] ?? $metadata['primary_signal']['previous_value'] ?? 0);
            $currConv = $evidence['storewide_conversions'] ?? null;
            $prevConv = $evidence['prior_storewide_conversions'] ?? null;

            $primaryMetricValue = '+' . number_format($surgePct, 1) . '% Surge';
            $primaryMetricLabel = 'Direct Traffic Volume Surge';

            $convClause = ($currConv !== null && $prevConv !== null)
                ? " while storewide conversion counts went from {$prevConv} to {$currConv}."
                : ' without an equivalent lift in purchase conversions.';
            $confirmedObservation = "Direct traffic volume surged by +" . number_format($surgePct, 1) . "% (" . number_format($previousVal) . " → " . number_format($currentVal) . " sessions), representing " . number_format($directShare, 1) . "% of site traffic" . $convClause;
            $possibleImplication = "Unassigned volume spikes without conversion lift can stem from un-tagged marketing/email redirects, referral stripping, or automated traffic. Inspect server logs and user-agents rather than assuming automated bots without evidence.";
            $recommendedAction = "Task {$targetTeamLabel} with inspecting referrer headers, landing pages, user-agent distributions, and GA4 channel rules to isolate the Direct traffic origin.";

            $supportingMetrics = [
                ['label' => 'Direct Session Volume', 'value' => number_format($currentVal), 'subtext' => 'Prior period: ' . number_format($previousVal), 'color' => 'amber'],
                ['label' => 'Direct Share', 'value' => number_format($directShare, 1) . '%', 'subtext' => 'Share of GA4 store traffic', 'color' => 'blue'],
                ['label' => 'Conversion Volume', 'value' => $currConv !== null ? "{$currConv} orders" : 'Unchanged Count', 'subtext' => $prevConv !== null ? "Prior period: {$prevConv} orders" : 'Rate diluted by traffic surge', 'color' => 'gray'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Investigation owner', 'color' => 'primary'],
            ];
        } elseif ($isCvrDecline) {
            $diffPct = (float) ($metadata['difference_pct'] ?? 0.0);
            $currentCvr = (float) ($metadata['metric_a']['value'] ?? 0.0);
            $period = (int) ($metadata['period_days'] ?? 30);
            $revImpact = (float) ($finding->estimated_revenue_impact ?? 0.0);
            $diag = is_array($evidence['conversion_diagnostics'] ?? null)
                ? $evidence['conversion_diagnostics']
                : (is_array($metadata['conversion_diagnostics'] ?? null) ? $metadata['conversion_diagnostics'] : []);

            $curr = $diag['current'] ?? [];
            $prev = $diag['previous'] ?? [];
            $diagType = $diag['diagnosis_type'] ?? null;

            $primaryMetricValue = number_format($diffPct, 1) . '% CVR';
            $primaryMetricLabel = "{$period}-Day GA4 CVR Shift";

            if (! empty($curr) && ! empty($prev)) {
                $weightedCurrCvr = number_format((float) ($curr['ga4_overall_cvr'] ?? $currentCvr), 3);
                $weightedPrevCvr = number_format((float) ($prev['ga4_overall_cvr'] ?? 0), 3);
                $nonDirectPrev = number_format((float) ($prev['ga4_non_direct_cvr'] ?? 0), 2);
                $nonDirectCurr = number_format((float) ($curr['ga4_non_direct_cvr'] ?? 0), 2);
                $mPrevOrd = (int) ($prev['magento_valid_orders'] ?? 0);
                $mCurrOrd = (int) ($curr['magento_valid_orders'] ?? 0);
                $mCurrRev = (float) ($curr['magento_valid_net_revenue'] ?? 0);
                $mExclOrd = (int) ($curr['magento_excluded_orders'] ?? 0);
                $dirShare = number_format((float) ($curr['ga4_direct_share_pct'] ?? 0), 1);

                $storedNote = abs((float) $currentCvr - (float) ($curr['ga4_overall_cvr'] ?? $currentCvr)) > 0.01
                    ? " (stored snapshot {$currentCvr}% vs recomputed {$weightedCurrCvr}% weighted GA4 orders/sessions)"
                    : '';

                $confirmedObservation = "GA4 overall conversion rate shifted {$weightedPrevCvr}% → {$weightedCurrCvr}%{$storedNote} while Direct traffic expanded to {$dirShare}% of sessions. Excluding Direct traffic, GA4 conversion moved {$nonDirectPrev}% → {$nonDirectCurr}%, and Magento recorded {$mCurrOrd} valid orders ($" . number_format($mCurrRev, 2) . " net) vs {$mPrevOrd} prior valid orders" . ($mExclOrd > 0 ? " ({$mExclOrd} additional orders excluded by status)." : '.');

                if ($diagType === 'traffic_denominator_dilution') {
                    $possibleImplication = "Overall GA4 conversion decline is primarily explained by Direct traffic denominator dilution rather than commercial order loss; check Direct traffic quality rather than modifying checkout code.";
                    $recommendedAction = "Task Analytics & {$targetTeamLabel} with auditing Direct traffic sources and session quality before making checkout changes.";
                } elseif ($diagType === 'mixed_dilution_and_commercial_softness') {
                    $possibleImplication = "Observed metrics combine severe Direct-session denominator dilution ({$dirShare}% share) with softer non-Direct conversion ({$nonDirectPrev}% → {$nonDirectCurr}%) and/or lower valid order count ({$mPrevOrd} → {$mCurrOrd}). Both traffic quality and order-status pipelines warrant inspection.";
                    $recommendedAction = "Task {$targetTeamLabel} with auditing Direct session attribution, reviewing non-Direct conversion channels, and checking excluded/pending Magento order statuses ({$mExclOrd} excluded).";
                } else {
                    $possibleImplication = "Conversion decline alongside commercial softness requires funnel-stage and channel verification before initiating UX/checkout code changes.";
                    $recommendedAction = "Task {$targetTeamLabel} with reviewing GA4 funnel drop-off rates, valid vs pending Magento order statuses, and channel-level conversion.";
                }

                $supportingMetrics = [
                    ['label' => 'GA4 Non-Direct CVR', 'value' => "{$nonDirectCurr}%", 'subtext' => "Prior: {$nonDirectPrev}% (Overall: {$weightedCurrCvr}%)", 'color' => ($curr['ga4_non_direct_cvr'] ?? 0) >= ($prev['ga4_non_direct_cvr'] ?? 0) ? 'emerald' : 'amber'],
                    ['label' => 'Magento Valid Orders', 'value' => number_format($mCurrOrd), 'subtext' => "Prior: {$mPrevOrd} · $" . number_format($mCurrRev, 0) . ($mExclOrd > 0 ? " ({$mExclOrd} excl.)" : ''), 'color' => $mCurrOrd >= $mPrevOrd ? 'emerald' : 'rose'],
                    ['label' => 'Direct Traffic Share', 'value' => "{$dirShare}%", 'subtext' => number_format((int) ($curr['ga4_direct_sessions'] ?? 0)) . ' Direct sessions', 'color' => 'blue'],
                    ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Diagnostic owner', 'color' => 'primary'],
                ];
            } else {
                $confirmedObservation = "Storewide GA4 conversion rate changed by " . number_format(abs($diffPct), 1) . "% over trailing {$period} days (to " . number_format($currentCvr, 2) . "%).";
                $possibleImplication = "Conversion rate shifts can reflect traffic-mix dilution, channel attribution gaps, or checkout funnel friction.";
                $recommendedAction = "Task {$targetTeamLabel} with segmenting conversion by traffic channel and validating Magento valid order volume before altering checkout.";

                $supportingMetrics = [
                    ['label' => 'Observed CVR', 'value' => number_format($currentCvr, 2) . '%', 'subtext' => "Trailing {$period}-day GA4 rate", 'color' => 'rose'],
                    ['label' => 'Rate Delta', 'value' => number_format($diffPct, 1) . '%', 'subtext' => 'Period change', 'color' => 'rose'],
                    ($revImpact > 0
                        ? ['label' => 'Modeled Impact', 'value' => '$' . number_format($revImpact, 2), 'subtext' => 'Estimated impact', 'color' => 'amber']
                        : ['label' => 'Impact Modeling', 'value' => 'Volatile Traffic', 'subtext' => 'Excluded to prevent distortion', 'color' => 'gray']),
                    ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Diagnostic owner', 'color' => 'primary'],
                ];
            }
        } elseif ($isAttributionVariance) {
            $recon = is_array($evidence['reconciliation_summary'] ?? null)
                ? $evidence['reconciliation_summary']
                : [];
            $klaviyoDiag = is_array($evidence['klaviyo_diagnostics'] ?? null)
                ? $evidence['klaviyo_diagnostics']
                : (is_array($metadata['klaviyo_diagnostics'] ?? null) ? $metadata['klaviyo_diagnostics'] : []);

            $isKlaviyo = str_contains($type, 'klaviyo') || str_contains($ruleKey, 'klaviyo') || ! empty($klaviyoDiag);

            if ($isKlaviyo) {
                $klaviyoRev = (float) ($klaviyoDiag['klaviyo_attributed_revenue'] ?? $metadata['metric_b']['value'] ?? 0.0);
                $ga4EmailRev = (float) ($klaviyoDiag['ga4_email_revenue'] ?? $metadata['metric_a']['value'] ?? 0.0);
                $diffAmount = (float) ($metadata['difference_amount'] ?? $klaviyoDiag['variance_amount'] ?? abs($klaviyoRev - $ga4EmailRev));
                $diffPct = (float) ($metadata['difference_pct'] ?? $klaviyoDiag['variance_pct'] ?? 100.0);
                $zeroClickRev = isset($klaviyoDiag['zero_click_revenue']) ? (float) $klaviyoDiag['zero_click_revenue'] : null;
                $zeroClickShare = isset($klaviyoDiag['zero_click_share_pct']) ? (float) $klaviyoDiag['zero_click_share_pct'] : null;
                $clickedRev = isset($klaviyoDiag['clicked_revenue']) ? (float) $klaviyoDiag['clicked_revenue'] : null;

                $primaryMetricValue = '$' . number_format($diffAmount, 2) . ' Variance';
                $primaryMetricLabel = 'GA4 vs Klaviyo Attribution Discrepancy';

                $zeroClickClause = ($zeroClickRev !== null && $zeroClickShare > 0)
                    ? (" $" . number_format($zeroClickRev, 2) . " ({$zeroClickShare}%) occurred on zero-click email metrics, which is consistent with Klaviyo open/attribution-window differences relative to GA4 session-click attribution (not proof of individual view-through conversions; link UTMs were not directly inspected).")
                    : ' Campaign link UTMs were not directly inspected; differences reflect cross-platform attribution models and windows.';

                $confirmedObservation = "Klaviyo attributed $" . number_format($klaviyoRev, 2) . " in email revenue vs $" . number_format($ga4EmailRev, 2) . " recorded under GA4 Email ({$diffPct}% reporting discrepancy).{$zeroClickClause}";
                $possibleImplication = "Reporting variance reflects differing attribution models and windows across Klaviyo and GA4, not lost or recoverable sales.";
                $recommendedAction = "Task {$targetTeamLabel} with documenting Klaviyo open/click attribution windows, auditing outbound campaign/flow UTMs ($" . number_format($clickedRev ?? 0.0, 2) . " on clicked dates), and aligning reporting definitions.";

                $supportingMetrics = [
                    ['label' => 'Klaviyo Attributed Rev', 'value' => '$' . number_format($klaviyoRev, 2), 'subtext' => 'Email platform attributed revenue (not ad spend)', 'color' => 'blue'],
                    ['label' => 'GA4 Email Revenue', 'value' => '$' . number_format($ga4EmailRev, 2), 'subtext' => 'Discrepancy: $' . number_format($diffAmount, 2) . " ({$diffPct}%)", 'color' => 'amber'],
                    ['label' => 'Zero-Click Share', 'value' => $zeroClickShare !== null ? "{$zeroClickShare}%" : 'Model Diff', 'subtext' => $zeroClickRev !== null ? ('$' . number_format($zeroClickRev, 2) . ' on 0-click rows') : 'Not lost revenue', 'color' => 'gray'],
                    ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Alignment owner', 'color' => 'primary'],
                ];
            } else {
                $ga4Total = (float) ($recon['ga4_tracked_revenue'] ?? $evidence['ga4_revenue'] ?? $metadata['primary_signal']['current_value'] ?? 0.0);
                $adobeTotal = (float) ($recon['adobe_net_revenue'] ?? $evidence['commerce_net_revenue'] ?? $metadata['primary_signal']['previous_value'] ?? 0.0);
                $diffAmount = (float) ($evidence['variance_amount'] ?? $metadata['difference_amount'] ?? $recon['variance_amount'] ?? ($ga4Total > 0 || $adobeTotal > 0 ? abs($ga4Total - $adobeTotal) : 0.0));
                $diffPct = (float) ($evidence['variance_pct'] ?? $metadata['difference_pct'] ?? $recon['variance_pct'] ?? 0.0);

                $matchedValid = $recon['matched_valid_count'] ?? $evidence['matched_valid_count'] ?? null;
                $matchedExcluded = $recon['matched_excluded_count'] ?? $evidence['matched_excluded_count'] ?? null;
                $matchedExcludedRev = $recon['matched_excluded_revenue'] ?? $evidence['matched_excluded_revenue'] ?? null;
                $missingInGa4 = $recon['missing_in_ga4_count'] ?? $evidence['missing_in_ga4_count'] ?? null;

                $primaryMetricValue = $diffAmount > 0
                    ? ('$' . number_format($diffAmount, 2) . ' Variance')
                    : (number_format($diffPct, 1) . '% Variance');
                $primaryMetricLabel = 'GA4 vs Commerce Revenue Variance';

                $totalsClause = ($ga4Total > 0 || $adobeTotal > 0)
                    ? ("GA4 recorded $" . number_format($ga4Total, 2) . " vs $" . number_format($adobeTotal, 2) . " in Commerce valid net revenue (delta: $" . number_format($diffAmount, 2) . " / " . number_format($diffPct, 1) . "%).")
                    : ("Reporting platforms recorded a " . number_format($diffPct, 1) . "% reporting discrepancy across systems.");
                $txClause = ($matchedValid !== null)
                    ? (" Transaction-ID matching shows {$matchedValid} matched valid orders, " . ($matchedExcluded ?? 0) . " excluded/canceled Commerce orders retained in GA4" . ($matchedExcludedRev ? " ($" . number_format((float) $matchedExcludedRev, 2) . ")" : '') . ", and " . ($missingInGa4 ?? 0) . " valid Commerce orders missing in GA4.")
                    : '';

                $confirmedObservation = $totalsClause . $txClause;
                $possibleImplication = "GA4 fires client-side on order confirmation and retains gross/tax/shipping and subsequently canceled or pending-status orders, whereas Commerce reports net settled valid orders. This is a tracking/definition alignment variance, not lost revenue.";
                $recommendedAction = "Task {$targetTeamLabel} with reconciling GA4 purchase event status/refund exclusions, checking offline/admin orders missing client-side tags, and aligning gross vs net revenue reporting definitions.";

                $supportingMetrics = [
                    ['label' => 'Platform Totals', 'value' => '$' . number_format($ga4Total, 0) . ' vs $' . number_format($adobeTotal, 0), 'subtext' => 'GA4 tracked vs Commerce valid net', 'color' => 'blue'],
                    ['label' => 'Variance Amount', 'value' => $diffAmount > 0 ? ('$' . number_format($diffAmount, 2)) : 'Unavailable', 'subtext' => 'Monetary delta between systems', 'color' => 'amber'],
                    ['label' => 'Discrepancy Rate', 'value' => number_format($diffPct, 1) . '%', 'subtext' => $matchedExcluded !== null ? "{$matchedExcluded} canceled/excluded tx in GA4" : 'Tracking definition alignment', 'color' => 'gray'],
                    ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Alignment owner', 'color' => 'primary'],
                ];
            }
        } elseif ($isRevenueDrop) {
            $diffPct = (float) ($metadata['difference_pct'] ?? 0.0);
            $diffAmount = (float) ($metadata['difference_amount'] ?? $finding->estimated_revenue_impact ?? 0.0);
            $period = (int) ($metadata['period_days'] ?? 14);

            $primaryMetricValue = '$' . number_format($diffAmount, 2) . ' Drop';
            $primaryMetricLabel = 'Observed Revenue Drop';

            $confirmedObservation = "Observed Commerce revenue dropped by $" . number_format($diffAmount, 2) . " (" . number_format(abs($diffPct), 1) . "%) over trailing {$period} days compared to the prior equivalent period.";
            $possibleImplication = "Sustained revenue decline indicates commercial order volume contraction, AOV shift, or order-status pipeline delays.";
            $recommendedAction = "Task {$targetTeamLabel} with auditing order volume, order status eligibility/exclusions, and ERP synchronization.";

            $supportingMetrics = [
                ['label' => 'Revenue Drop', 'value' => '$' . number_format($diffAmount, 2), 'subtext' => 'Observed period delta', 'color' => 'rose'],
                ['label' => 'Decline Rate', 'value' => number_format($diffPct, 1) . '%', 'subtext' => "Trailing {$period} days", 'color' => 'rose'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Operations owner', 'color' => 'primary'],
            ];
        } else {
            $impact = (float) ($finding->estimated_revenue_impact ?? 0.0);
            $primaryMetricValue = $impact > 0 ? ('$' . number_format($impact, 2)) : 'Diagnostic';
            $primaryMetricLabel = $impact > 0 ? 'Modeled Impact' : 'Diagnostic Finding';

            $confirmedObservation = $finding->description ?: "Identified {$opportunityType} on {$scopeLabel}.";
            $possibleImplication = "Operational condition requiring verification by {$targetTeamLabel}.";
            $recommendedAction = $finding->suggested_action ?: "Have {$targetTeamLabel} investigate {$scopeLabel} and verify system telemetry.";

            $supportingMetrics = [
                ['label' => 'Impact', 'value' => $impact > 0 ? ('$' . number_format($impact, 2)) : 'Non-Monetary', 'subtext' => 'Modeled value', 'color' => $impact > 0 ? 'emerald' : 'gray'],
                ['label' => 'Scope', 'value' => $scopeLabel, 'subtext' => 'Target entity', 'color' => 'blue'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Remediation owner', 'color' => 'primary'],
            ];
        }

        $evalAtFormatted = null;
        $evalSource = $freshness['evaluated_at'] ?? $lastObservedAt;
        if ($evalSource) {
            try {
                $evalAtFormatted = \Carbon\Carbon::parse($evalSource)->format('M j, Y H:i T');
            } catch (\Throwable) {
                $evalAtFormatted = (string) $evalSource;
            }
        }

        return [
            'is_account_opportunity' => false,
            'opportunity_type'       => $opportunityType,
            'title'                  => $displayTitle,
            'scope_label'            => $scopeLabel,
            'target_team'            => $targetTeam,
            'target_team_label'      => $targetTeamLabel,
            'freshness_badge'        => $freshnessBadge,
            'freshness_color'        => $freshnessColor,
            'freshness_note'         => $freshnessNote,
            'freshness_state'        => $freshness['state'] ?? ($isOutdated ? 'historical_observation' : 'current'),
            'is_outdated'            => $isOutdated,
            'requires_revalidation'  => $requiresRevalidation,
            'observation_period'     => $observationPeriodText,
            'primary_metric_label'   => $primaryMetricLabel,
            'primary_metric_value'   => $primaryMetricValue,
            'concise_explanation'    => $confirmedObservation,
            'confirmed_observation'  => $confirmedObservation,
            'possible_implication'   => $possibleImplication,
            'recommended_action'     => $recommendedAction,
            'supporting_metrics'     => $supportingMetrics,
            'outcome_presentation'   => $this->buildOutcomePresentation($finding),
            'expandable_details'     => [
                'observation_period'       => $observationPeriodText,
                'first_detected_at'        => $firstDetectedAt,
                'last_observed_at'         => $evalAtFormatted ?? 'N/A',
                'source_window_start'      => $freshness['source_window_start'] ?? null,
                'source_window_end'        => $freshness['source_window_end'] ?? null,
                'last_successful_sync_at'  => $freshness['last_successful_sync_at'] ?? null,
                'latest_source_data_date'  => $freshness['latest_source_data_date'] ?? null,
                'evaluated_at'             => $freshness['evaluated_at'] ?? null,
                'record_updated_at'        => $freshness['record_updated_at'] ?? null,
                'scope'                    => $scopeLabel,
                'target_team'              => $targetTeamLabel,
                'requires_revalidation'    => $requiresRevalidation,
                'revalidation_reason'      => $requiresRevalidation ? $freshnessNote : null,
            ],
        ];
    }

    /**
     * Fallback explanation for generic or technical findings.
     */
    protected function explainGenericOrTechnical(
        Finding $finding,
        array $evidence,
        ?CommerceCustomerPurchaseFact $fact,
        array $customerDisplay,
        array $contact,
        array $revalidation = []
    ): array {
        $customerName = $customerDisplay['primary_name'] ?? 'Account';
        $impact = (float) ($finding->estimated_revenue_impact ?? 0.0);

        return [
            'opportunity_type'     => Str::headline($finding->finding_type ?? 'Opportunity'),
            'concise_explanation' => "{$customerName} has an identified opportunity with modeled impact of $" . number_format($impact, 2) . ".",
            'supporting_metrics'   => [
                [
                    'label'   => 'Impact Potential',
                    'value'   => "$" . number_format($impact, 2),
                    'subtext' => 'Estimated commercial impact',
                    'color'   => 'emerald',
                ],
                [
                    'label'   => 'Status',
                    'value'   => $finding->status instanceof FindingStatus ? $finding->status->label() : Str::headline((string)$finding->status),
                    'subtext' => 'Lifecycle state',
                    'color'   => 'gray',
                ],
                [
                    'label'   => 'Confidence',
                    'value'   => ($finding->confidence_score ?? 0) . '%',
                    'subtext' => 'Model confidence',
                    'color'   => 'primary',
                ],
            ],
            'recommended_action'   => $contact['recommended_action'],
            'outcome_presentation' => $this->buildOutcomePresentation($finding),
            'expandable_details'   => [
                'opportunity_type'      => Str::headline($finding->finding_type ?? 'Opportunity'),
                'contact_status'        => $contact['status_label'] ?? $contact['contact_label'],
                'contact_verification'  => $contact['reason'],
                'contact_provenance'    => $contact['provenance'] ?? null,
                'requires_revalidation' => $revalidation['requires_revalidation'] ?? false,
                'revalidation_reason'   => $revalidation['revalidation_reason'] ?? null,
                'detector_version'      => $revalidation['detector_version'] ?? null,
                'evaluated_at'          => $revalidation['evaluated_at'] ?? null,
            ],
        ];
    }

    /**
     * Resolve the configured or stored post-action observation window duration in days.
     * Strictly prioritizes finding-specific stored outcome/metadata/evidence values before falling back to global config.
     */
    public function resolveObservationWindowDays(Finding $finding): int
    {
        $windowDays = null;

        if ($finding->relationLoaded('outcomes')) {
            $windowDays = $finding->outcomes->max('attribution_window_days');
        } elseif ($finding->exists) {
            $windowDays = $finding->outcomes()->max('attribution_window_days');
        }

        if (! $windowDays) {
            $metadata = is_array($finding->metadata_json)
                ? $finding->metadata_json
                : (json_decode($finding->metadata_json ?? '[]', true) ?: []);
            $windowDays = $metadata['attribution_window_days']
                ?? $metadata['observation_window_days']
                ?? $metadata['window_days']
                ?? null;
        }

        if (! $windowDays) {
            $evidence = is_array($finding->evidence_json)
                ? $finding->evidence_json
                : (json_decode($finding->evidence_json ?? '[]', true) ?: []);
            $windowDays = $evidence['attribution_window_days']
                ?? $evidence['observation_window_days']
                ?? $evidence['window_days']
                ?? null;
        }

        if (! $windowDays) {
            $assumptions = is_array($finding->impact_assumptions_json)
                ? $finding->impact_assumptions_json
                : (json_decode($finding->impact_assumptions_json ?? '[]', true) ?: []);
            $windowDays = $assumptions['attribution_window_days']
                ?? $assumptions['observation_window_days']
                ?? $assumptions['window_days']
                ?? null;
        }

        $resolved = (int) ($windowDays ?: config('intelligence.outcome_measurement.window_days', 30));

        return $resolved > 0 ? $resolved : 30;
    }

    /**
     * Build outcome presentation structure.
     * Prevents misleading '$0.00' display for unworked or actively observing opportunities.
     */
    public function buildOutcomePresentation(Finding $finding): array
    {
        $windowDays = $this->resolveObservationWindowDays($finding);

        $outcomeStatus = $finding->outcome_status instanceof FindingOutcomeStatus 
            ? $finding->outcome_status 
            : ($finding->outcome_status ? FindingOutcomeStatus::tryFrom($finding->outcome_status) : null);

        // 1. Post-action observation completed: true measured outcome
        if ($outcomeStatus === FindingOutcomeStatus::OutcomeMeasured) {
            $observedRevenue = (float) (
                $finding->attributes['observed_post_action_revenue']
                ?? $finding->observed_post_action_revenue
                ?? ($finding->relationLoaded('outcomes') ? $finding->outcomes->max('observed_revenue') : null)
                ?? (method_exists($finding, 'getObservedPostActionRevenue') && $finding->exists ? $finding->getObservedPostActionRevenue() : null)
                ?? 0.00
            );

            $hasOrders = $observedRevenue > 0;
            if (! $hasOrders && $finding->exists && method_exists($finding, 'hasObservedPostActionOrders')) {
                $hasOrders = $finding->hasObservedPostActionOrders();
            }

            if ($hasOrders && $observedRevenue > 0) {
                $lift = (float) (
                    $finding->attributes['modeled_lift_revenue']
                    ?? $finding->modeled_lift_revenue
                    ?? ($finding->relationLoaded('outcomes') ? $finding->outcomes->max('actual_incremental_impact') : null)
                    ?? (method_exists($finding, 'getModeledIncrementalLift') && $finding->exists ? $finding->getModeledIncrementalLift() : null)
                    ?? 0.00
                );
                $liftNote = $lift > 0 
                    ? (' Includes $' . number_format($lift, 2) . ' modeled incremental lift.') 
                    : '';

                return [
                    'state'            => 'measured_positive',
                    'label'            => '$' . number_format($observedRevenue, 2) . ' observed reorders',
                    'badge_color'      => 'emerald',
                    'description'      => "Verified orders placed during the {$windowDays}-day post-action window.{$liftNote}",
                    'is_measured'      => true,
                    'has_revenue'      => true,
                    'value'            => $observedRevenue,
                    'observed_revenue' => $observedRevenue,
                    'modeled_lift'     => $lift,
                    'window_days'      => $windowDays,
                ];
            }

            // Valid measured zero! (Observation window finished with 0 orders)
            return [
                'state'            => 'measured_zero',
                'label'            => '$0.00 observed (0 orders)',
                'badge_color'      => 'gray',
                'description'      => "0 post-action reorders observed during the completed {$windowDays}-day observation window.",
                'is_measured'      => true,
                'has_revenue'      => true,
                'value'            => 0.00,
                'observed_revenue' => 0.00,
                'modeled_lift'     => 0.00,
                'window_days'      => $windowDays,
            ];
        }

        // 2. Outreach completed, active observation window
        if ($outcomeStatus === FindingOutcomeStatus::AwaitingResults) {
            return [
                'state'            => 'in_observation',
                'label'            => 'In Observation',
                'badge_color'      => 'amber',
                'description'      => "Action completed; currently active in the {$windowDays}-day post-action observation window.",
                'is_measured'      => false,
                'has_revenue'      => false,
                'value'            => null,
                'observed_revenue' => null,
                'modeled_lift'     => null,
                'window_days'      => $windowDays,
            ];
        }

        // 3. Unworked opportunities
        return [
            'state'            => 'not_yet_measured',
            'label'            => 'Not yet measured',
            'badge_color'      => 'gray',
            'description'      => 'Measurement begins after outreach is executed.',
            'is_measured'      => false,
            'has_revenue'      => false,
            'value'            => null,
            'observed_revenue' => null,
            'modeled_lift'     => null,
            'window_days'      => $windowDays,
        ];
    }

    /**
     * Compute authoritative Method D dormancy threshold.
     */
    public static function calculateMethodDThreshold(int $medianInterval, ?float $cv, int $minDormancy = 180): array
    {
        $adaptiveMultiplier = 2.0;
        if ($cv !== null && $cv > 0.35) {
            $adaptiveMultiplier = min(3.2, round(2.0 + (1.2 * min(1.0, ($cv - 0.35) / 0.45)), 2));
        }

        $cadenceOverdueDays = (int) round($medianInterval * $adaptiveMultiplier);
        $effectiveThreshold = max($minDormancy, $cadenceOverdueDays);

        return [
            'multiplier'          => $adaptiveMultiplier,
            'cadence_days'        => $cadenceOverdueDays,
            'effective_threshold' => $effectiveThreshold,
            'threshold'           => $effectiveThreshold,
        ];
    }

    /**
     * Get Method D threshold in days directly.
     */
    public static function calculateMethodDThresholdDays(int $medianInterval, ?float $cv, int $minDormancy = 180): int
    {
        return static::calculateMethodDThreshold($medianInterval, $cv, $minDormancy)['effective_threshold'];
    }

    /**
     * Assess buyer contact information using the 4-tier provenance taxonomy:
     * 1. independently_verified: Explicit external/CRM verification evidence present.
     * 2. records_consistent: Recorded contact concordant with customer account records (consistency signal, not proof).
     * 3. requires_review: Discordant domain or name mismatch (consistency signal requiring review).
     * 4. not_recorded: No contact recorded in store records.
     *
     * @param  string|null  $customerName
     * @param  string|null  $customerEmail
     * @param  string|null  $companyName
     * @param  array  $contactMetadata
     * @return array
     */
    public static function assessContactConsistency(
        ?string $customerName,
        ?string $customerEmail,
        ?string $companyName,
        array $contactMetadata = []
    ): array {
        // Tier 4: Not recorded
        if (empty($customerEmail) && empty($customerName)) {
            $companyLabel = !empty($companyName) ? $companyName : 'this account';
            return [
                'status_key'                => 'not_recorded',
                'badge_text'                => 'No Contact Recorded',
                'badge_color'               => 'slate',
                'status_label'              => 'No contact recorded in Magento',
                'is_verified'               => false,
                'is_verified_buyer'         => false,
                'is_independently_verified' => false,
                'is_records_consistent'     => false,
                'is_discordant'             => false,
                'name_email_concordant'     => false,
                'email_domain_concordant'   => false,
                'is_free_email_provider'    => false,
                'is_shared_role_inbox'      => false,
                'display_contact_name'      => null,
                'recommended_action'        => "Review CRM or ERP records to identify the current purchasing contact for {$companyLabel} before initiating outreach.",
                'reason'                    => 'No contact records available or incomplete details (missing name or email)',
                'notes'                     => 'No contact records available or incomplete details (missing name or email)',
                'contact_label'             => 'No contact recorded',
                'buyer_name'                => null,
                'buyer_email'               => null,
                'provenance'                => null,
            ];
        }

        // Check for explicit independent verification evidence (Tier 1) first — only Tier 1 is truly verified
        $hasVerificationSource = !empty($contactMetadata['verification_source']);
        $hasRoleConfirmation = !empty($contactMetadata['role_confirmation']) || !empty($contactMetadata['procurement_role_confirmed']);

        $cleanName = strtolower(trim($customerName ?? ''));
        $cleanEmail = strtolower(trim($customerEmail ?? ''));
        $emailParts = explode('@', $cleanEmail);
        $localPart = preg_replace('/[^a-z0-9._\-+]/', '', $emailParts[0] ?? '');
        $localAlpha = preg_replace('/[^a-z]/', '', $localPart);
        $domainPart = $emailParts[1] ?? '';

        $freeEmailDomains = [
            'gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com',
            'yahoo.com', 'ymail.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com',
            'comcast.net', 'verizon.net', 'att.net', 'sbcglobal.net', 'cox.net', 'proton.me', 'protonmail.com',
            'libero.it', 'virgilio.it', 'alice.it', 'tin.it', 'tiscali.it',
            'gmx.de', 'gmx.at', 'gmx.ch', 'gmx.net', 'gmx.com', 'web.de', 't-online.de', 'freenet.de',
            'mail.com', 'orange.fr', 'wanadoo.fr', 'laposte.net', 'bluewin.ch',
        ];
        $sharedRoleLocalParts = [
            'accounts', 'accountspayable', 'ap', 'billing', 'finance', 'invoices', 'invoice',
            'purchasing', 'procurement', 'buyer', 'buyers', 'orders', 'order', 'sales',
            'info', 'admin', 'office', 'support', 'contact', 'hello', 'team', 'receiving', 'shipping',
            'marketing', 'supply', 'logistics', 'warehouse', 'operations', 'service', 'reception',
            'hotel', 'direktion', 'rechnung', 'rechnungen', 'buchhaltung', 'einkauf', 'vertrieb',
            'verwaltung', 'zentrale', 'technik', 'amministrazione', 'acquisti', 'contabilita',
        ];

        $domainPrefix = explode('.', $domainPart)[0] ?? '';
        $isFreeEmail = in_array($domainPart, $freeEmailDomains, true)
            || in_array($domainPrefix, ['gmail', 'googlemail', 'hotmail', 'outlook', 'live', 'msn', 'yahoo', 'ymail', 'aol', 'icloud'], true);
        $localBase = explode('+', str_replace(['.', '_', '-'], '', $localPart))[0];
        $isSharedRoleInbox = in_array($localPart, $sharedRoleLocalParts, true)
            || in_array($localBase, $sharedRoleLocalParts, true);

        // Name-to-email matching: supports full first/last token OR first-initial + last-name
        // while rejecting contradictory prefixes (e.g. Rodney Ellison vs lynleeellison@hotmail.com).
        $nameTokens = array_values(array_filter(preg_split('/[\s\._\-]+/', $cleanName)));
        $firstToken = $nameTokens[0] ?? '';
        $lastToken = count($nameTokens) > 1 ? $nameTokens[count($nameTokens) - 1] : '';

        $nameMatchesEmail = false;
        if ($localAlpha !== '' && ! empty($nameTokens)) {
            $firstMatches = strlen($firstToken) >= 3 && str_contains($localAlpha, preg_replace('/[^a-z]/', '', $firstToken));
            $lastClean = preg_replace('/[^a-z]/', '', $lastToken);
            $lastMatches = strlen($lastClean) >= 3 && str_contains($localAlpha, $lastClean);
            $initialLastMatches = strlen($firstToken) >= 1 && strlen($lastClean) >= 3
                && str_starts_with($localAlpha, substr($firstToken, 0, 1) . $lastClean);

            if ($firstMatches || $initialLastMatches) {
                $nameMatchesEmail = true;
            } elseif ($lastMatches && $firstToken !== '') {
                // Only accept last-name-only when the prefix before last name is empty or matches first initial
                $pos = strpos($localAlpha, $lastClean);
                $prefix = $pos > 0 ? substr($localAlpha, 0, $pos) : '';
                if ($prefix === '' || str_starts_with($prefix, substr($firstToken, 0, 1))) {
                    $nameMatchesEmail = true;
                }
            }
        }

        // Fixed case sensitivity bug: lowercase BEFORE preg_replace so ALL-CAPS companies like PROLIMPA are not stripped to ''!
        $companyMatchesDomain = false;
        if (! empty($companyName) && ! $isFreeEmail && ! empty($domainPart)) {
            $cleanCompany = preg_replace('/[^a-z0-9]/', '', strtolower(trim($companyName)));
            $cleanDomain = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domainPart)[0] ?? ''));
            if (strlen($cleanCompany) >= 3 && strlen($cleanDomain) >= 3) {
                if (str_contains($cleanCompany, $cleanDomain) || str_contains($cleanDomain, $cleanCompany)) {
                    $companyMatchesDomain = true;
                } else {
                    // Check distinctive non-suffix company words (e.g. "Gold Eagle Co." -> "goldeagle", "LSI" -> "lsijax")
                    $stopWords = ['inc', 'llc', 'ltd', 'corp', 'co', 'company', 'services', 'products', 'builders', 'sales', 'repair', 'solutions', 'gmbh', 'srl', 'kg', 'egbr', 'sa', 'the', 'of', 'and', 'for', 'des', 'fur'];
                    $rawWords = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower(trim($companyName)))));
                    $sigWords = array_values(array_filter($rawWords, fn ($w) => strlen($w) >= 2 && ! in_array($w, $stopWords, true)));
                    $allSignificantWords = array_values(array_filter($rawWords, fn ($w) => strlen($w) >= 2 && ! in_array($w, ['inc', 'llc', 'ltd', 'corp', 'co', 'company', 'gmbh', 'srl', 'kg', 'egbr', 'sa', 'the', 'of', 'and', 'for'], true)));

                    foreach ($sigWords as $word) {
                        if (strlen($word) >= 3 && str_contains($cleanDomain, $word)) {
                            $companyMatchesDomain = true;
                            break;
                        }
                    }

                    // Check corporate initials / acronyms & ordered abbreviations (e.g. Aircraft Ducting Repair -> acdri, Molded Magnesium Products -> mmpmg)
                    if (! $companyMatchesDomain && count($allSignificantWords) >= 2) {
                        $initials = implode('', array_map(fn ($w) => $w[0], $allSignificantWords));
                        if (strlen($initials) >= 3 && str_starts_with($cleanDomain, $initials)) {
                            $companyMatchesDomain = true;
                        } elseif (strlen($initials) >= 3 && strlen($cleanDomain) <= 7 && ($cleanDomain[0] ?? '') === ($initials[0] ?? '')) {
                            // Verify initials appear in sequence within abbreviated corporate domain (e.g., a-c-d-r-i contains a,d,r in order)
                            $pos = 0;
                            $matchedAll = true;
                            for ($idx = 0; $idx < strlen($initials); $idx++) {
                                $foundAt = strpos($cleanDomain, $initials[$idx], $pos);
                                if ($foundAt === false) {
                                    $matchedAll = false;
                                    break;
                                }
                                $pos = $foundAt + 1;
                            }
                            if ($matchedAll) {
                                $companyMatchesDomain = true;
                            }
                        }
                    }

                    // Check domain-word initials against compact company name (e.g. "S.A.M." -> "spices-and-more")
                    if (! $companyMatchesDomain && str_contains($domainPrefix, '-')) {
                        $domParts = array_values(array_filter(explode('-', strtolower($domainPrefix))));
                        if (count($domParts) >= 3) {
                            $domInitials = implode('', array_map(fn ($p) => $p[0], $domParts));
                            if ($domInitials === $cleanCompany) {
                                $companyMatchesDomain = true;
                            }
                        }
                    }

                    // Check consonant-skeleton abbreviation (e.g. "Metalcraft" -> "mtlcraft")
                    if (! $companyMatchesDomain && ! empty($sigWords) && strlen($sigWords[0]) >= 6 && strlen($cleanDomain) >= 5) {
                        $compConsonants = preg_replace('/[aeiou]/', '', $sigWords[0]);
                        $domConsonants = preg_replace('/[aeiou]/', '', $cleanDomain);
                        if (strlen($compConsonants) >= 5 && ($domConsonants === $compConsonants || str_starts_with($domConsonants, $compConsonants))) {
                            $companyMatchesDomain = true;
                        }
                    }
                }
            }
        }

        if ($hasVerificationSource && $hasRoleConfirmation) {
            $vSource = $contactMetadata['verification_source'];
            $vDate = $contactMetadata['verification_date'] ?? $contactMetadata['verified_at'] ?? 'recent date';
            $vUser = $contactMetadata['verified_by'] ?? $contactMetadata['responsible_user'] ?? 'System';
            $vRole = $contactMetadata['role_confirmation'] ?? 'Confirmed Procurement Authority';

            $reason = "Independently verified purchasing contact via {$vSource} on {$vDate} by {$vUser} (Role: {$vRole}).";
            return [
                'status_key'                => 'independently_verified',
                'badge_text'                => 'Independently Verified',
                'badge_color'               => 'emerald',
                'status_label'              => 'Independently verified current purchasing contact',
                'is_verified'               => true,
                'is_verified_buyer'         => true,
                'is_independently_verified' => true,
                'is_records_consistent'     => true,
                'is_discordant'             => false,
                'name_email_concordant'     => $nameMatchesEmail,
                'email_domain_concordant'   => $companyMatchesDomain,
                'is_free_email_provider'    => $isFreeEmail,
                'is_shared_role_inbox'      => $isSharedRoleInbox,
                'display_contact_name'      => $customerName,
                'recommended_action'        => "Initiate commercial outreach to verified purchasing contact {$customerName} ({$customerEmail}) to present re-engagement terms.",
                'reason'                    => $reason,
                'notes'                     => $reason,
                'contact_label'             => "{$customerName} ({$customerEmail})",
                'buyer_name'                => $customerName,
                'buyer_email'               => $customerEmail,
                'provenance'                => [
                    'verification_source' => $vSource,
                    'verification_date'   => $vDate,
                    'verified_by'         => $vUser,
                    'role_confirmation'   => $vRole,
                ],
            ];
        }

        // Tier 3: Requires Review — missing email, free webmail, shared inbox, person-vs-email mismatch, or full domain mismatch
        $companyLabel = ! empty($companyName) ? $companyName : 'this account';
        $requiresReviewReason = null;
        $requiresReviewAction = null;

        if (empty($customerEmail)) {
            $requiresReviewReason = "Recorded contact '{$customerName}' has no email address in store records. Confirm current buyer email before outreach.";
            $requiresReviewAction = "Confirm email and purchasing role for {$customerName} ({$companyLabel}) in CRM/ERP records before outreach.";
        } elseif ($isSharedRoleInbox) {
            $requiresReviewReason = "Recorded address '{$customerEmail}' is a shared role inbox rather than an individual buyer mailbox" . ($companyMatchesDomain ? " on company domain '{$domainPart}'." : '.') . " Treat as an unverified role address.";
            $requiresReviewAction = "Confirm the individual purchasing contact at {$companyLabel} before outreach (orders list shared inbox {$customerEmail} alongside {$customerName}).";
        } elseif ($isFreeEmail) {
            $requiresReviewReason = $nameMatchesEmail
                ? "Recorded contact '{$customerName}' uses a free webmail provider ('{$domainPart}'), which cannot establish current corporate employment or procurement authority."
                : "Recorded contact '{$customerName}' and free webmail address '{$customerEmail}' do not match and cannot verify current corporate purchasing authority for '{$companyLabel}'.";
            $requiresReviewAction = "Verify active procurement contact for {$companyLabel} before outreach (recorded contact lists {$customerName} with personal webmail {$customerEmail}).";
        } elseif ($companyMatchesDomain && ! $nameMatchesEmail) {
            $requiresReviewReason = "Email domain '{$domainPart}' aligns with '{$companyLabel}', but address '{$customerEmail}' does not match recorded contact '{$customerName}'. Individual ownership and purchasing authority require confirmation.";
            $requiresReviewAction = "Confirm whether {$customerEmail} or {$customerName} handles current purchasing at {$companyLabel} before outreach.";
        } elseif (! $nameMatchesEmail && ! $companyMatchesDomain) {
            $requiresReviewReason = "Recorded contact '{$customerName}' and email address '{$customerEmail}' are discordant with company '{$companyLabel}'. Discordant contact records require account review.";
            $requiresReviewAction = "Review account records to identify the active procurement contact (records list {$customerName} with discordant email {$customerEmail}) before outreach.";
        }

        if ($requiresReviewReason !== null) {
            return [
                'status_key'                => 'requires_review',
                'badge_text'                => 'Contact Requiring Review',
                'badge_color'               => 'amber',
                'status_label'              => 'Contact requiring review',
                'is_verified'               => false,
                'is_verified_buyer'         => false,
                'is_independently_verified' => false,
                'is_records_consistent'     => false,
                'is_discordant'             => ! $companyMatchesDomain || ! $nameMatchesEmail,
                'name_email_concordant'     => $nameMatchesEmail,
                'email_domain_concordant'   => $companyMatchesDomain,
                'is_free_email_provider'    => $isFreeEmail,
                'is_shared_role_inbox'      => $isSharedRoleInbox,
                'display_contact_name'      => null,
                'recommended_action'        => $requiresReviewAction,
                'reason'                    => $requiresReviewReason,
                'notes'                     => $requiresReviewReason,
                'contact_label'             => "{$customerName} ({$customerEmail} · requires verification)",
                'buyer_name'                => $customerName,
                'buyer_email'               => $customerEmail,
                'provenance'                => null,
            ];
        }

        // Tier 2: Consistent with Customer Records (corporate email matches named individual, consistency signal only — NOT verified proof)
        $domainNote = $companyMatchesDomain
            ? 'Contact name and corporate email domain are consistent with customer records.'
            : "Contact name matches corporate email '{$customerEmail}' (domain '{$domainPart}' is distinct from stored company name '{$companyLabel}').";

        $reason = "{$domainNote} Store-record consistency signal only — not independent proof of current employment or purchasing authority.";
        return [
            'status_key'                => 'records_consistent',
            'badge_text'                => 'Consistent with Customer Records',
            'badge_color'               => 'blue',
            'status_label'              => 'Contact information consistent with available store records',
            'is_verified'               => false,
            'is_verified_buyer'         => false,
            'is_independently_verified' => false,
            'is_records_consistent'     => true,
            'is_discordant'             => false,
            'name_email_concordant'     => $nameMatchesEmail,
            'email_domain_concordant'   => $companyMatchesDomain,
            'is_free_email_provider'    => false,
            'is_shared_role_inbox'      => false,
            'display_contact_name'      => $customerName,
            'recommended_action'        => "Reach out to recorded contact {$customerName} ({$customerEmail}) to confirm current procurement responsibility and present re-engagement terms.",
            'reason'                    => $reason,
            'notes'                     => $reason,
            'contact_label'             => "{$customerName} ({$customerEmail})",
            'buyer_name'                => $customerName,
            'buyer_email'               => $customerEmail,
            'provenance'                => null,
        ];
    }
}
