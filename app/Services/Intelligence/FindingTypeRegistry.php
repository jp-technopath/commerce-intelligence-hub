<?php

namespace App\Services\Intelligence;

use App\Enums\FindingPillar;
use App\Models\Finding;
use Illuminate\Support\Facades\Log;

/**
 * Centralized registry mapping finding types, detector keys, rule keys,
 * and signal families to authoritative FindingPillar, strategic goals, and roles.
 *
 * Unknown finding types are never silently defaulted to GrowthOpportunity;
 * they are explicitly flagged for classification.
 */
class FindingTypeRegistry
{
    /**
     * Authoritative definition table keyed by canonical finding_type / source_id / detector_key.
     *
     * @var array<string, array{pillar: FindingPillar, strategic_goal: string, target_role: string}>
     */
    private const DEFINITIONS = [
        // ─── Growth Opportunities ────────────────────────────────────────────
        'opportunity.long_term_winback' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Enterprise Winback Campaign',
            'target_role'    => 'executive_sales',
        ],
        'long_term_winback' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Enterprise Winback Campaign',
            'target_role'    => 'executive_sales',
        ],
        'opportunity.b2b_account_reactivation' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'B2B Wholesale Retention',
            'target_role'    => 'account_manager',
        ],
        'b2b_account_reactivation' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'B2B Wholesale Retention',
            'target_role'    => 'account_manager',
        ],
        'wholesale_retention' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'B2B Wholesale Retention',
            'target_role'    => 'account_manager',
        ],
        'opportunity.replenishment_cadence' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Consumable Reorder Capture',
            'target_role'    => 'growth_marketer',
        ],
        'replenishment_cadence' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Consumable Reorder Capture',
            'target_role'    => 'growth_marketer',
        ],
        'opportunity.upsell' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Account Expansion & Cross-Sell',
            'target_role'    => 'account_manager',
        ],
        'opportunity.cross_sell' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Account Expansion & Cross-Sell',
            'target_role'    => 'account_manager',
        ],
        'conversion_change_increase' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'marketing_operations',
        ],
        'revenue_change_increase' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Commercial Account Growth',
            'target_role'    => 'marketing_operations',
        ],
        'aov_change_increase' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Commercial Account Growth',
            'target_role'    => 'merchandising',
        ],
        'unified_conversion_rate_increase' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'marketing_operations',
        ],
        'funnel_overall_conversion_increase' => [
            'pillar'         => FindingPillar::GrowthOpportunity,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'marketing_operations',
        ],

        // ─── Business Risks ──────────────────────────────────────────────────
        'risk.account_contraction' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Key Account Churn Prevention',
            'target_role'    => 'account_manager',
        ],
        'account_contraction' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Key Account Churn Prevention',
            'target_role'    => 'account_manager',
        ],
        'gk_ga4_conversion_rate_decline_30d' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'conversion_rate_drop' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'conversion_change_decrease' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'unified_conversion_rate_decrease' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'funnel_view_to_cart_dropoff_increase' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'funnel_cart_to_checkout_dropoff_increase' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'funnel_checkout_to_purchase_dropoff_increase' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'funnel_overall_conversion_decrease' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'traffic_up_revenue_down_correlation' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'ux_degradation_conversion_drop' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'revenue_friction_correlation' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Checkout Conversion Optimization',
            'target_role'    => 'ecommerce_operations',
        ],
        'adobe_commerce_revenue_drop' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'ecommerce_operations',
        ],
        'gk_commerce_revenue_decline_30d' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'ecommerce_operations',
        ],
        'gk_commerce_orders_decline_30d' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'ecommerce_operations',
        ],
        'gk_commerce_aov_decline_30d' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'ecommerce_operations',
        ],
        'gk_klaviyo_attributed_revenue_decline_30d' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'marketing_operations',
        ],
        'gk_klaviyo_flow_revenue_decline_30d' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'marketing_operations',
        ],
        'revenue_change_decrease' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'ecommerce_operations',
        ],
        'aov_change_decrease' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'ecommerce_operations',
        ],
        'traffic_change_decrease' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'marketing_operations',
        ],
        'new_customer_change_decrease' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Key Account Churn Prevention',
            'target_role'    => 'marketing_operations',
        ],
        'gk_ga4_sessions_decline_30d' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Storewide Revenue Performance',
            'target_role'    => 'marketing_operations',
        ],

        // Internal delivery / margin risks
        'task_blocked' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'project_manager',
        ],
        'task_overdue' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'project_manager',
        ],
        'qa_rework' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'engineering',
        ],
        'hours_allocation_exceeded' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Margin & Allocation Protection',
            'target_role'    => 'project_manager',
        ],
        'unassigned_meeting_owner' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'account_manager',
        ],
        'meeting_prep_needed' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'account_manager',
        ],
        'meeting_prep_in_progress' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'account_manager',
        ],
        'meeting_prep_review_needed' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'account_manager',
        ],
        'meeting_followup_needed' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'account_manager',
        ],
        'meeting_followup_draft_generated' => [
            'pillar'         => FindingPillar::BusinessRisk,
            'strategic_goal' => 'Delivery & SLA Risk Governance',
            'target_role'    => 'account_manager',
        ],

        // ─── Technical & Data Health ─────────────────────────────────────────
        'technical.script_error' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'diagnostic.script_errors' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'script_errors_increase' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'error_clicks_increase' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'rage_clicks_increase' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'dead_clicks_increase' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'quick_backs_increase' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'friction_score_increase' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'revenue_script_error_correlation' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'aov_rage_clicks_correlation' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'dead_clicks_revenue_correlation' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'engineering',
        ],
        'traffic_drop_quickbacks_correlation' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Storefront & Script Health',
            'target_role'    => 'analytics',
        ],
        'attribution.platform_variance' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Revenue & Channel Attribution',
            'target_role'    => 'marketing_operations',
        ],
        'ga4_klaviyo_attribution_variance' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Revenue & Channel Attribution',
            'target_role'    => 'marketing_operations',
        ],
        'diagnostic.ga4_klaviyo_attribution_variance' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Revenue & Channel Attribution',
            'target_role'    => 'marketing_operations',
        ],
        'attribution.campaign_tagging' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Revenue & Channel Attribution',
            'target_role'    => 'marketing_operations',
        ],
        'traffic.volume_surge' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Direct Traffic & Referral Audit',
            'target_role'    => 'analytics',
        ],
        'revenue_mismatch' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Data & Technical Health Governance',
            'target_role'    => 'analytics',
        ],
        'duplicate_ga4_transactions' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Data & Technical Health Governance',
            'target_role'    => 'engineering',
        ],
        'missing_in_ga4' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Data & Technical Health Governance',
            'target_role'    => 'engineering',
        ],
        'data_pipeline_gap' => [
            'pillar'         => FindingPillar::TechnicalHealth,
            'strategic_goal' => 'Data & Technical Health Governance',
            'target_role'    => 'data_engineer',
        ],
    ];

    /**
     * Return all known definitions for auditing and testing.
     *
     * @return array<string, array{pillar: FindingPillar, strategic_goal: string, target_role: string}>
     */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * Classify an existing Finding model without silently defaulting unknown types.
     *
     * @return array{
     *   pillar: ?FindingPillar,
     *   pillar_value: ?string,
     *   strategic_goal: string,
     *   target_role: string,
     *   is_known: bool,
     *   requires_classification: bool,
     *   matched_key: ?string
     * }
     */
    public static function classify(Finding $finding): array
    {
        $meta = is_array($finding->metadata_json)
            ? $finding->metadata_json
            : (json_decode($finding->metadata_json ?? '[]', true) ?: []);
        $ev = is_array($finding->evidence_json)
            ? $finding->evidence_json
            : (json_decode($finding->evidence_json ?? '[]', true) ?: []);

        $candidates = array_filter([
            $finding->source_id,
            $finding->finding_type !== 'opportunity' ? $finding->finding_type : null,
            $meta['detector_key'] ?? null,
            $meta['rule_key'] ?? null,
            $meta['primary_signal']['family'] ?? null,
            $ev['detector_key'] ?? null,
        ]);

        foreach ($candidates as $key) {
            $norm = strtolower(trim((string) $key));
            if (isset(self::DEFINITIONS[$norm])) {
                $def = self::DEFINITIONS[$norm];
                return [
                    'pillar'                  => $def['pillar'],
                    'pillar_value'            => $def['pillar']->value,
                    'strategic_goal'          => $def['strategic_goal'],
                    'target_role'             => $def['target_role'],
                    'is_known'                => true,
                    'requires_classification' => false,
                    'matched_key'             => $norm,
                ];
            }
        }

        return self::classifyByDescriptor(
            findingType: (string) ($finding->finding_type ?? ''),
            sourceId: $finding->source_id,
            ruleKey: $meta['rule_key'] ?? null,
            metricKey: $meta['metric_a']['key'] ?? ($meta['metric'] ?? null),
            operator: $meta['operator'] ?? null,
            differencePct: isset($meta['difference_pct']) ? (float) $meta['difference_pct'] : null
        );
    }

    /**
     * Classify raw detector / rule descriptors during finding creation or evaluation.
     *
     * @return array{
     *   pillar: ?FindingPillar,
     *   pillar_value: ?string,
     *   strategic_goal: string,
     *   target_role: string,
     *   is_known: bool,
     *   requires_classification: bool,
     *   matched_key: ?string
     * }
     */
    public static function classifyByDescriptor(
        string $findingType,
        ?string $sourceId = null,
        ?string $ruleKey = null,
        ?string $metricKey = null,
        ?string $operator = null,
        ?float $differencePct = null
    ): array {
        foreach ([$sourceId, $ruleKey, $findingType] as $candidate) {
            $norm = strtolower(trim((string) $candidate));
            if ($norm !== '' && isset(self::DEFINITIONS[$norm])) {
                $def = self::DEFINITIONS[$norm];
                return [
                    'pillar'                  => $def['pillar'],
                    'pillar_value'            => $def['pillar']->value,
                    'strategic_goal'          => $def['strategic_goal'],
                    'target_role'             => $def['target_role'],
                    'is_known'                => true,
                    'requires_classification' => false,
                    'matched_key'             => $norm,
                ];
            }
        }

        $joined = strtolower(implode(' ', array_filter([$findingType, $sourceId, $ruleKey, $metricKey])));

        // Explicit prefix / pattern classification for valid dynamic custom rules
        if (str_starts_with($joined, 'upsell') || str_starts_with($joined, 'cross_sell') || str_contains($joined, 'winback') || str_contains($joined, 'reactivation') || str_contains($joined, 'replenish')) {
            $goal = str_contains($joined, 'winback') ? 'Enterprise Winback Campaign'
                : (str_contains($joined, 'replenish') ? 'Consumable Reorder Capture'
                : (str_contains($joined, 'reactivation') ? 'B2B Wholesale Retention' : 'Account Expansion & Cross-Sell'));

            return self::knownResult(FindingPillar::GrowthOpportunity, $goal, 'account_manager', $joined);
        }

        if (str_contains($joined, 'script_error') || str_starts_with($joined, 'technical.') || str_starts_with($joined, 'data_quality.')) {
            return self::knownResult(FindingPillar::TechnicalHealth, 'Storefront & Script Health', 'engineering', $joined);
        }

        if (str_starts_with($joined, 'attribution.') || str_contains($joined, 'attribution_variance') || str_contains($joined, 'platform_variance') || $operator === 'percentage_difference') {
            return self::knownResult(FindingPillar::TechnicalHealth, 'Revenue & Channel Attribution', 'marketing_operations', $joined);
        }

        if (str_starts_with($joined, 'traffic.volume_surge') || str_contains($joined, 'traffic_surge') || str_contains($joined, 'campaign_tagging')) {
            return self::knownResult(FindingPillar::TechnicalHealth, 'Direct Traffic & Referral Audit', 'analytics', $joined);
        }

        if (str_contains($joined, 'conversion_rate') && ($differencePct === null || $differencePct <= 0)) {
            return self::knownResult(FindingPillar::BusinessRisk, 'Checkout Conversion Optimization', 'ecommerce_operations', $joined);
        }

        if (str_contains($joined, 'margin') || str_contains($joined, 'contraction') || str_contains($joined, 'churn')
            || (($differencePct !== null && $differencePct < 0) && (str_contains($joined, 'revenue') || str_contains($joined, 'orders') || str_contains($joined, 'aov')))) {
            $goal = str_contains($joined, 'margin')
                ? 'Margin & Allocation Protection'
                : (str_contains($joined, 'contraction') || str_contains($joined, 'churn')
                    ? 'Key Account Churn Prevention'
                    : 'Storewide Revenue Performance');

            return self::knownResult(FindingPillar::BusinessRisk, $goal, 'ecommerce_operations', $joined);
        }

        // Unknown finding type: NEVER silently default to GrowthOpportunity.
        Log::warning('FindingTypeRegistry: Unknown finding type requires explicit classification', [
            'finding_type' => $findingType,
            'source_id'    => $sourceId,
            'rule_key'     => $ruleKey,
            'metric_key'   => $metricKey,
        ]);

        return [
            'pillar'                  => null,
            'pillar_value'            => null,
            'strategic_goal'          => 'Unclassified Intelligence Review',
            'target_role'             => 'operations',
            'is_known'                => false,
            'requires_classification' => true,
            'matched_key'             => null,
        ];
    }

    /**
     * Resolve authoritative FindingPillar value for DB persistence (or null if unknown).
     */
    public static function resolvePillarValue(
        string $findingType,
        ?string $sourceId = null,
        ?string $ruleKey = null,
        ?string $metricKey = null,
        ?string $operator = null,
        ?float $differencePct = null
    ): ?string {
        return self::classifyByDescriptor($findingType, $sourceId, $ruleKey, $metricKey, $operator, $differencePct)['pillar_value'];
    }

    private static function knownResult(FindingPillar $pillar, string $goal, string $role, string $matchedKey): array
    {
        return [
            'pillar'                  => $pillar,
            'pillar_value'            => $pillar->value,
            'strategic_goal'          => $goal,
            'target_role'             => $role,
            'is_known'                => true,
            'requires_classification' => false,
            'matched_key'             => $matchedKey,
        ];
    }
}
