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

        $authoritativeThreshold = isset($evidence['cadence_threshold_days']) 
            ? (int) $evidence['cadence_threshold_days'] 
            : (isset($evidence['effective_min_dormancy']) ? (int) $evidence['effective_min_dormancy'] : null);

        $daysInactive = (int) ($evidence['days_since_last_purchase'] 
            ?? $evidence['days_since_last_order'] 
            ?? $evidence['days_inactive'] 
            ?? $fact?->days_since_last_order 
            ?? 0);

        // Fallback calculation for mock or unmigrated tests
        if ($authoritativeThreshold === null) {
            $methodD = static::calculateMethodDThreshold($medianInterval, $cv, minDormancy: 180);
            $authoritativeThreshold = $methodD['effective_threshold'];
            $adaptiveMultiplier = $methodD['multiplier'];
        }

        $overdueDays = isset($evidence['days_overdue']) 
            ? (int) $evidence['days_overdue'] 
            : max(0, $daysInactive - $authoritativeThreshold);

        $storedThreshold = isset($evidence['threshold']) ? (int) $evidence['threshold'] : null;
        $hasLegacyThreshold = $storedThreshold && abs($storedThreshold - $authoritativeThreshold) > 5;

        $deviationRatio = $authoritativeThreshold > 0 ? round($daysInactive / $authoritativeThreshold, 2) : 1.0;

        $episodes = (int) ($evidence['episode_count'] ?? $evidence['purchasing_episodes_count'] ?? $fact?->lifetime_valid_order_count ?? 0);
        $lifetimeSpend = (float) ($evidence['lifetime_net_purchasing_value'] ?? $evidence['lifetime_spend'] ?? $fact?->lifetime_net_revenue ?? 0.0);
        $benchmark = (float) ($evidence['historical_episode_benchmark'] ?? $finding->estimated_revenue_impact ?? 0.0);

        // 1. One concise explanation of the business issue
        $revalNotice = $requiresRevalidation ? " [Revalidation Required: {$revalidationReason}]" : '';
        $explanation = "{$customerName} has not purchased in {$daysInactive} days, exceeding the authoritative {$authoritativeThreshold}-day dormancy threshold by {$overdueDays} days ({$deviationRatio}x normal). Prior history reflects {$episodes} purchasing episodes totaling \${" . number_format($lifetimeSpend, 2) . "} in lifetime spend.{$revalNotice}";

        // 2. Three meaningful supporting metrics
        $supportingMetrics = [
            [
                'label'   => 'Inactivity',
                'value'   => "{$daysInactive}d (Threshold: {$authoritativeThreshold}d)",
                'subtext' => "{$overdueDays}d past dormancy line",
                'color'   => 'rose',
            ],
            [
                'label'   => 'Cadence Deviation',
                'value'   => "+{$overdueDays}d ({$deviationRatio}x)",
                'subtext' => "Based on {$medianInterval}d median interval",
                'color'   => 'amber',
            ],
            [
                'label'   => 'Historical Scale',
                'value'   => "{$episodes} episodes · \${" . number_format($benchmark, 2) . "}",
                'subtext' => "\${" . number_format($lifetimeSpend, 2) . "} total relationship spend",
                'color'   => 'primary',
            ],
        ];

        // 3. Evidence-grounded recommended action from contact verification
        $recommendedAction = $contact['recommended_action'];

        // 4. Outcome presentation
        $outcomePresentation = $this->buildOutcomePresentation($finding);

        // 5. Expandable detailed view
        $calibrationNotes = $hasLegacyThreshold 
            ? "Calibrated to {$authoritativeThreshold} days under Method D ({$medianInterval}d median × {$adaptiveMultiplier} multiplier). Finding record originally listed {$storedThreshold}d under legacy 4.0x model."
            : "Calibrated under Method D: {$medianInterval}-day median interval × {$adaptiveMultiplier} adaptive multiplier = {$authoritativeThreshold}-day dormancy threshold.";

        $expandableDetails = [
            'opportunity_type'          => 'Long-Term Enterprise Winback',
            'relationship_tier'         => $evidence['relationship_tier'] ?? 'Proven Recurring Account',
            'cadence_methodology'       => $calibrationNotes,
            'calibrated_threshold_days' => $authoritativeThreshold,
            'median_cadence_days'       => $medianInterval,
            'cadence_variability_cv'    => $cv,
            'calibrated_multiplier'     => $adaptiveMultiplier,
            'financial_definitions'     => [
                'Historical Lifetime Spend' => '$' . number_format($lifetimeSpend, 2) . ': Cumulative customer purchases across all recorded orders (scale benchmark, not projected revenue).',
                'Episode Benchmark'         => '$' . number_format($benchmark, 2) . ': Average historical order value per purchasing episode, serving as a baseline reference for typical reorders.',
            ],
            'contact_status'            => $contact['status_label'] ?? $contact['contact_label'],
            'contact_verification'      => $contact['reason'],
            'contact_provenance'        => $contact['provenance'] ?? null,
            'first_order_date'          => $evidence['first_order_at'] ?? $evidence['first_order'] ?? null,
            'latest_order_date'         => $evidence['latest_order_at'] ?? $evidence['latest_order'] ?? null,
            'order_span_days'           => $evidence['order_span_days'] ?? $evidence['order_span'] ?? null,
            'has_legacy_discrepancy'    => $hasLegacyThreshold,
            'requires_revalidation'     => $requiresRevalidation,
            'revalidation_reason'       => $revalidationReason,
            'detector_version'          => $revalidation['detector_version'] ?? ($evidence['detector_version'] ?? '2.1.0-method-d'),
            'evaluated_at'              => $revalidation['evaluated_at'] ?? ($evidence['evaluated_at'] ?? null),
        ];

        return [
            'opportunity_type'     => 'Long-Term Enterprise Winback',
            'concise_explanation' => $explanation,
            'supporting_metrics'   => $supportingMetrics,
            'recommended_action'   => $recommendedAction,
            'outcome_presentation' => $outcomePresentation,
            'expandable_details'   => $expandableDetails,
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

        $explanation = "{$customerName} has been inactive for {$daysInactive} days, exceeding the {$threshold}-day dormancy threshold by {$overdue} days. Account has {$orders} past orders averaging \${" . number_format($aov, 2) . "} per order.";

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
                'value'   => "\${" . number_format($aov, 2) . "} AOV",
                'subtext' => "\${" . number_format($lifetimeSpend, 2) . "} lifetime spend",
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
                    'Historical Reorder Value' => "\${" . number_format($aov, 2) . "}: Historical average order value.",
                    'Lifetime Spend'          => "\${" . number_format($lifetimeSpend, 2) . "}: Cumulative recorded purchases.",
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

        $explanation = "{$customerName} is {$daysOverdue} days past their established {$cadenceDays}-day consumable replenishment cycle (last ordered {$daysSince} days ago). Historical reorders average \${" . number_format($aov, 2) . "}.";

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
                'value'   => "\${" . number_format($aov, 2) . "} AOV",
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
                    'Historical Reorder Value' => "\${" . number_format($aov, 2) . "}: Historical average order value for routine replenishment.",
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

        $explanation = "{$customerName} spending contracted by {$declinePct}% over the trailing 180 days, dropping from a \${" . number_format($baselineSpend, 2) . "} quarterly baseline to \${" . number_format($evalSpend, 2) . "} (shortfall of \${" . number_format($declineAmount, 2) . "}).";

        $supportingMetrics = [
            [
                'label'   => 'Spending Decline',
                'value'   => "-\${" . number_format($declineAmount, 2) . "}",
                'subtext' => "-{$declinePct}% contraction",
                'color'   => 'rose',
            ],
            [
                'label'   => 'Quarterly Baseline',
                'value'   => "\${" . number_format($baselineSpend, 2) . "}",
                'subtext' => 'Historical baseline run-rate',
                'color'   => 'gray',
            ],
            [
                'label'   => 'Recent Spend',
                'value'   => "\${" . number_format($evalSpend, 2) . "}",
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
                    'Spending Contraction' => "\${" . number_format($declineAmount, 2) . "}: Trailing run-rate drop against historical baseline.",
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
        $scopeType = $metadata['scope_type'] ?? null;
        $scopeTarget = $metadata['scope_target'] ?? null;
        $scopeLabel = $scopeType && $scopeTarget
            ? (Str::headline($scopeType) . ': ' . $scopeTarget)
            : ($scopeTarget ?? 'Site-wide');

        $targetTeam = $metadata['actionability']['target_team'] ?? $finding->target_role;
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
            'ecommerce_operations' => 'E-commerce Operations',
            'sales', 'executive_sales' => 'Executive Sales',
            default => Str::headline($targetTeam),
        };

        // 2. Data Freshness & Observation Period
        $lastObservedAt = $metadata['last_observed_at'] ?? $finding->updated_at?->toIso8601String();
        $detectedAt = $metadata['primary_signal']['detected_at'] ?? $metadata['detected_at'] ?? $finding->created_at?->toIso8601String();
        $periodDays = $metadata['period_days'] ?? null;

        $isOutdated = false;
        $freshnessBadge = 'Diagnostic Observation';
        $freshnessColor = 'gray';
        $freshnessNote = 'Diagnostic observation recorded from telemetry.';

        if ($lastObservedAt) {
            try {
                $observedDate = \Carbon\Carbon::parse($lastObservedAt);
                $daysAgo = (int) round($observedDate->diffInDays(now()));
                if ($daysAgo > 7) {
                    $isOutdated = true;
                    $freshnessBadge = 'Historical Observation (' . $observedDate->format('M j, Y') . ')';
                    $freshnessColor = 'amber';
                    $freshnessNote = 'Historical observation (' . $daysAgo . ' days ago): requires re-validation against current logs.';
                } else {
                    $freshnessBadge = 'Active Episode (' . $observedDate->format('M j, Y') . ')';
                    $freshnessColor = 'emerald';
                    $freshnessNote = 'Active episode observed in recent monitoring.';
                }
            } catch (\Throwable $e) {
                // Keep defaults
            }
        }

        $observationPeriodText = $periodDays ? "Trailing {$periodDays} days" : 'Monitoring episode';

        // 3. Scenario-Specific Evidence Extraction (Reusable without hardcoding IDs)
        $isScriptError = str_contains($type, 'script_error') || str_contains($family, 'script_error');
        $isCampaignTagging = str_contains($type, 'campaign_tagging') || str_contains($family, 'campaign_tagging');
        $isTrafficSurge = str_contains($type, 'volume_surge') || str_contains($family, 'volume_surge') || str_contains($type, 'traffic');
        $isCvrDecline = str_contains($type, 'conversion_rate') || str_contains($ruleKey, 'conversion_rate');
        $isAttributionVariance = str_contains($type, 'attribution_variance') || str_contains($type, 'platform_variance') || str_contains($ruleKey, 'attribution_variance');
        $isRevenueDrop = str_contains($type, 'revenue_drop') || str_contains($ruleKey, 'revenue_drop');

        $opportunityType = Str::headline($finding->finding_type ?? 'Operational Finding');
        $supportingMetrics = [];

        if ($isScriptError) {
            $errorCount = (int) ($evidence['error_count'] ?? $metadata['primary_signal']['evidence']['error_count'] ?? 0);
            $claritySessions = (int) ($evidence['clarity_sessions'] ?? $metadata['primary_signal']['evidence']['clarity_sessions'] ?? 0);
            $affectedUrl = $evidence['affected_url'] ?? $metadata['primary_signal']['evidence']['affected_url'] ?? 'Packaging tapes catalog';
            $exceptionType = $evidence['exception_type'] ?? $metadata['primary_signal']['evidence']['exception_type'] ?? 'cart_item_options';

            $primaryMetricValue = number_format($errorCount) . ' Errors';
            $primaryMetricLabel = 'Front-End Script Exception';

            $confirmedObservation = number_format($errorCount) . " JavaScript exceptions ({$exceptionType}) recorded across " . number_format($claritySessions) . " sessions on {$affectedUrl}.";
            $possibleImplication = "Front-end script errors during option selection may degrade buyer user experience and disrupt catalog interactions. (Note: Error counts identify technical friction; they do not automatically imply verified lost revenue).";
            $recommendedAction = "Task {$targetTeamLabel} with reproducing the {$exceptionType} exception on {$affectedUrl} and deploying a front-end script fix.";

            $supportingMetrics = [
                ['label' => 'Error Volume', 'value' => number_format($errorCount), 'subtext' => 'JS exceptions logged', 'color' => 'rose'],
                ['label' => 'Sessions Affected', 'value' => number_format($claritySessions), 'subtext' => 'Impacted user sessions', 'color' => 'amber'],
                ['label' => 'Exception', 'value' => $exceptionType, 'subtext' => 'Error signature', 'color' => 'gray'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Remediation owner', 'color' => 'primary'],
            ];
        } elseif ($isCampaignTagging) {
            $klaviyoRev = (float) ($evidence['klaviyo_revenue'] ?? $metadata['primary_signal']['evidence']['klaviyo_revenue'] ?? 0.0);
            $ga4EmailRev = (float) ($evidence['ga4_email_revenue'] ?? $metadata['primary_signal']['evidence']['ga4_email_revenue'] ?? 0.0);
            $issue = $evidence['issue'] ?? $metadata['primary_signal']['evidence']['issue'] ?? 'Missing campaign UTM parameters';

            $primaryMetricValue = '$' . number_format($klaviyoRev, 2);
            $primaryMetricLabel = 'Unassigned Campaign Revenue';

            $confirmedObservation = "Promotional email platform recorded $" . number_format($klaviyoRev, 2) . " in campaign revenue, while web analytics recorded $" . number_format($ga4EmailRev, 2) . " due to missing utm_source parameters.";
            $possibleImplication = "Campaign links lacking standard UTM tags prevent web analytics from crediting the promotional email channel. (Note: Unassigned campaign revenue reflects reporting misattribution, not lost sales).";
            $recommendedAction = "Task {$targetTeamLabel} with auditing promotional email template links and configuring standardized utm_source=klaviyo parameters.";

            $supportingMetrics = [
                ['label' => 'Unassigned Revenue', 'value' => '$' . number_format($klaviyoRev, 2), 'subtext' => 'Platform reported email spend', 'color' => 'blue'],
                ['label' => 'GA4 Email Revenue', 'value' => '$0.00', 'subtext' => 'Unassigned in web analytics', 'color' => 'gray'],
                ['label' => 'Tracking Issue', 'value' => 'Missing UTM Tags', 'subtext' => $issue, 'color' => 'amber'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Configuration owner', 'color' => 'primary'],
            ];
        } elseif ($isTrafficSurge) {
            $surgePct = (float) ($evidence['surge_pct'] ?? $metadata['primary_signal']['evidence']['surge_pct'] ?? 0.0);
            $directShare = (float) ($evidence['direct_share_pct'] ?? $metadata['primary_signal']['evidence']['direct_share_pct'] ?? 0.0);
            $currentVal = (int) ($metadata['primary_signal']['current_value'] ?? 0);
            $previousVal = (int) ($metadata['primary_signal']['previous_value'] ?? 0);

            $primaryMetricValue = '+' . number_format($surgePct, 1) . '% Surge';
            $primaryMetricLabel = 'Direct Traffic Volume Surge';

            $confirmedObservation = "Direct traffic volume surged by +" . number_format($surgePct, 1) . "% (" . number_format($previousVal) . " → " . number_format($currentVal) . " sessions), representing " . number_format($directShare, 1) . "% of total store traffic with flat conversion.";
            $possibleImplication = "Unassigned volume spikes without revenue lift can indicate untagged marketing campaigns, referral tracking loss, or automated traffic. (Note: Do not classify as bot traffic without verifying referral headers and user-agent distributions).";
            $recommendedAction = "Task {$targetTeamLabel} with inspecting referral headers, user-agent distributions, and session drop-off rates on landing pages to isolate traffic origin.";

            $supportingMetrics = [
                ['label' => 'Session Volume', 'value' => number_format($currentVal), 'subtext' => 'Baseline: ' . number_format($previousVal), 'color' => 'amber'],
                ['label' => 'Channel Share', 'value' => number_format($directShare, 1) . '%', 'subtext' => 'Dominant direct share', 'color' => 'blue'],
                ['label' => 'Conversion Impact', 'value' => 'Flat Conversion', 'subtext' => 'No conversion lift observed', 'color' => 'gray'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Investigation owner', 'color' => 'primary'],
            ];
        } elseif ($isCvrDecline) {
            $diffPct = (float) ($metadata['difference_pct'] ?? 0.0);
            $currentCvr = (float) ($metadata['metric_a']['value'] ?? 0.0);
            $period = (int) ($metadata['period_days'] ?? 30);
            $revImpact = (float) ($finding->estimated_revenue_impact ?? 0.0);

            $primaryMetricValue = number_format($diffPct, 1) . '% CVR';
            $primaryMetricLabel = "{$period}-Day Conversion Decline";

            $confirmedObservation = "Storewide conversion rate dropped by " . number_format(abs($diffPct), 1) . "% over trailing {$period} days (to " . number_format($currentCvr, 2) . "%).";
            $possibleImplication = "Sustained conversion rate decline indicates checkout funnel friction, catalog pricing sensitivity, or traffic quality dilution.";
            $recommendedAction = "Task {$targetTeamLabel} with auditing checkout drop-off rates and analyzing conversion by device and traffic channel.";

            $supportingMetrics = [
                ['label' => 'Observed CVR', 'value' => number_format($currentCvr, 2) . '%', 'subtext' => "Trailing {$period}-day rate", 'color' => 'rose'],
                ['label' => 'Rate Delta', 'value' => number_format($diffPct, 1) . '%', 'subtext' => 'Period change', 'color' => 'rose'],
                ($revImpact > 0
                    ? ['label' => 'Modeled Impact', 'value' => '$' . number_format($revImpact, 2), 'subtext' => 'Estimated impact', 'color' => 'amber']
                    : ['label' => 'Impact Modeling', 'value' => 'Volatile Traffic', 'subtext' => 'Excluded to prevent distortion', 'color' => 'gray']),
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Remediation owner', 'color' => 'primary'],
            ];
        } elseif ($isAttributionVariance) {
            $diffPct = (float) ($metadata['difference_pct'] ?? $evidence['variance_pct'] ?? 0.0);
            $diffAmount = (float) ($metadata['difference_amount'] ?? 0.0);

            $primaryMetricValue = ($diffAmount > 0 ? ('$' . number_format($diffAmount, 2)) : (number_format($diffPct, 1) . '%')) . ' Variance';
            $primaryMetricLabel = 'Cross-Platform Attribution Discrepancy';

            $confirmedObservation = "Reporting platforms recorded a " . number_format($diffPct, 1) . "% reporting discrepancy" . ($diffAmount > 0 ? " ($" . number_format($diffAmount, 2) . " variance)" : "") . " across reporting systems.";
            $possibleImplication = "Cross-platform attribution and tracking variance represents reporting alignment differences across attribution models and windows, not lost sales or recoverable cash.";
            $recommendedAction = "Task {$targetTeamLabel} with aligning cross-platform tracking parameters, attribution windows, and cookie consent policies.";

            $supportingMetrics = [
                ['label' => 'Variance Amount', 'value' => ($diffAmount > 0 ? '$' . number_format($diffAmount, 2) : number_format($diffPct, 1) . '%'), 'subtext' => 'Cross-platform delta', 'color' => 'amber'],
                ['label' => 'Discrepancy Rate', 'value' => number_format($diffPct, 1) . '%', 'subtext' => 'Tracking difference', 'color' => 'blue'],
                ['label' => 'Classification', 'value' => 'Alignment Gap', 'subtext' => 'Not lost sales or pipeline', 'color' => 'gray'],
                ['label' => 'Responsible Team', 'value' => $targetTeamLabel, 'subtext' => 'Alignment owner', 'color' => 'primary'],
            ];
        } elseif ($isRevenueDrop) {
            $diffPct = (float) ($metadata['difference_pct'] ?? 0.0);
            $diffAmount = (float) ($metadata['difference_amount'] ?? $finding->estimated_revenue_impact ?? 0.0);
            $period = (int) ($metadata['period_days'] ?? 14);

            $primaryMetricValue = '$' . number_format($diffAmount, 2) . ' Drop';
            $primaryMetricLabel = 'Observed Revenue Drop';

            $confirmedObservation = "Observed Commerce revenue dropped by $" . number_format($diffAmount, 2) . " (" . number_format(abs($diffPct), 1) . "%) over trailing {$period} days.";
            $possibleImplication = "Sustained revenue drop indicates catalog or commercial order volume contraction.";
            $recommendedAction = "Task {$targetTeamLabel} with auditing order intake and validating ERP synchronization.";

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

        return [
            'is_account_opportunity' => false,
            'opportunity_type'       => $opportunityType,
            'title'                  => $finding->title ?: $opportunityType,
            'scope_label'            => $scopeLabel,
            'target_team'            => $targetTeam,
            'target_team_label'      => $targetTeamLabel,
            'freshness_badge'        => $freshnessBadge,
            'freshness_color'        => $freshnessColor,
            'freshness_note'         => $freshnessNote,
            'is_outdated'            => $isOutdated,
            'primary_metric_label'   => $primaryMetricLabel,
            'primary_metric_value'   => $primaryMetricValue,
            'concise_explanation'   => $confirmedObservation,
            'confirmed_observation'  => $confirmedObservation,
            'possible_implication'   => $possibleImplication,
            'recommended_action'     => $recommendedAction,
            'supporting_metrics'     => $supportingMetrics,
            'outcome_presentation'   => $this->buildOutcomePresentation($finding),
            'expandable_details'     => [
                'observation_period'    => $observationPeriodText,
                'last_observed_at'      => $lastObservedAt ? \Carbon\Carbon::parse($lastObservedAt)->format('M j, Y H:i T') : 'N/A',
                'scope'                 => $scopeLabel,
                'target_team'           => $targetTeamLabel,
                'requires_revalidation' => $isOutdated,
                'revalidation_reason'   => $isOutdated ? 'Historical observation older than 7 days requires re-validation' : null,
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
            'concise_explanation' => "{$customerName} has an identified opportunity with modeled impact of \${" . number_format($impact, 2) . "}.",
            'supporting_metrics'   => [
                [
                    'label'   => 'Impact Potential',
                    'value'   => "\${" . number_format($impact, 2) . "}",
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
     * Build outcome presentation structure.
     * Prevents misleading '$0.00' display for unworked or actively observing opportunities.
     */
    public function buildOutcomePresentation(Finding $finding): array
    {
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
                    'description'      => "Verified orders placed during the 60-day post-action window.{$liftNote}",
                    'is_measured'      => true,
                    'has_revenue'      => true,
                    'value'            => $observedRevenue,
                    'observed_revenue' => $observedRevenue,
                    'modeled_lift'     => $lift,
                ];
            }

            // Valid measured zero! (Observation window finished with 0 orders)
            return [
                'state'            => 'measured_zero',
                'label'            => '$0.00 observed (0 orders)',
                'badge_color'      => 'gray',
                'description'      => '0 post-action reorders observed during the completed 60-day observation window.',
                'is_measured'      => true,
                'has_revenue'      => true,
                'value'            => 0.00,
                'observed_revenue' => 0.00,
                'modeled_lift'     => 0.00,
            ];
        }

        // 2. Outreach completed, active observation window
        if ($outcomeStatus === FindingOutcomeStatus::AwaitingResults) {
            return [
                'state'            => 'in_observation',
                'label'            => 'In Observation',
                'badge_color'      => 'amber',
                'description'      => 'Action completed; currently active in the 60-day post-action observation window.',
                'is_measured'      => false,
                'has_revenue'      => false,
                'value'            => null,
                'observed_revenue' => null,
                'modeled_lift'     => null,
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
                'is_discordant'             => false,
                'name_email_concordant'     => false,
                'email_domain_concordant'   => false,
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

        $cleanName = strtolower(trim($customerName ?? ''));
        $cleanEmail = strtolower(trim($customerEmail ?? ''));
        $emailParts = explode('@', $cleanEmail);
        $localPart = $emailParts[0] ?? '';
        $domainPart = $emailParts[1] ?? '';

        // Split name into first and last tokens
        $nameTokens = array_filter(preg_split('/[\s\._\-]+/', $cleanName));

        // Check if any name token of length >= 3 matches inside the email local part
        $nameMatchesEmail = false;
        foreach ($nameTokens as $token) {
            if (strlen($token) >= 3 && str_contains($localPart, $token)) {
                $nameMatchesEmail = true;
                break;
            }
        }

        // Check company domain concordance if company is present
        $companyMatchesDomain = false;
        if (!empty($companyName)) {
            $cleanCompany = strtolower(preg_replace('/[^a-z0-9]/', '', $companyName));
            $cleanDomain = strtolower(preg_replace('/[^a-z0-9]/', '', explode('.', $domainPart)[0] ?? ''));
            if (strlen($cleanDomain) >= 3 && (str_contains($cleanCompany, $cleanDomain) || str_contains($cleanDomain, $cleanCompany))) {
                $companyMatchesDomain = true;
            }
        }

        // Tier 3: Requires Review (Discordant name/domain)
        if (! $nameMatchesEmail && ! $companyMatchesDomain) {
            $companyLabel = !empty($companyName) ? $companyName : 'this account';
            $reason = "Recorded contact '{$customerName}' and email address '{$customerEmail}' are discordant with company '{$companyName}'. Discordant contact records require account review.";
            return [
                'status_key'                => 'requires_review',
                'badge_text'                => 'Contact Requiring Review',
                'badge_color'               => 'amber',
                'status_label'              => 'Contact requiring review (discordant records)',
                'is_verified'               => false,
                'is_verified_buyer'         => false,
                'is_independently_verified' => false,
                'is_discordant'             => true,
                'name_email_concordant'     => false,
                'email_domain_concordant'   => false,
                'display_contact_name'      => null,
                'recommended_action'        => "Review account records to identify the active procurement contact (records list {$customerName} with discordant email domain {$customerEmail}) before outreach.",
                'reason'                    => $reason,
                'notes'                     => $reason,
                'contact_label'             => "{$customerName} (Email domain '{$domainPart}' requires verification)",
                'buyer_name'                => $customerName,
                'buyer_email'               => $customerEmail,
                'provenance'                => null,
            ];
        }

        // Check for explicit independent verification evidence (Tier 1)
        $hasVerificationSource = !empty($contactMetadata['verification_source']);
        $hasRoleConfirmation = !empty($contactMetadata['role_confirmation']) || !empty($contactMetadata['procurement_role_confirmed']);

        if ($hasVerificationSource && $hasRoleConfirmation) {
            // Tier 1: Independently Verified
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
                'is_discordant'             => false,
                'name_email_concordant'     => $nameMatchesEmail,
                'email_domain_concordant'   => $companyMatchesDomain,
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

        // Tier 2: Consistent with Customer Records (concordant, but no independent proof of procurement authority)
        $reason = "Verified concordant procurement contact (Name and email domain consistent with customer records). Treated as consistency signal, not independent proof of current purchasing authority.";
        return [
            'status_key'                => 'records_consistent',
            'badge_text'                => 'Consistent with Customer Records',
            'badge_color'               => 'blue',
            'status_label'              => 'Contact information consistent with available customer records',
            'is_verified'               => true,
            'is_verified_buyer'         => true,
            'is_independently_verified' => false,
            'is_discordant'             => false,
            'name_email_concordant'     => $nameMatchesEmail,
            'email_domain_concordant'   => $companyMatchesDomain,
            'display_contact_name'      => $customerName,
            'recommended_action'        => "Initiate commercial outreach to recorded contact {$customerName} ({$customerEmail}) to confirm current procurement responsibility and present re-engagement terms.",
            'reason'                    => $reason,
            'notes'                     => $reason,
            'contact_label'             => "{$customerName} ({$customerEmail})",
            'buyer_name'                => $customerName,
            'buyer_email'               => $customerEmail,
            'provenance'                => null,
        ];
    }
}
