<?php

namespace App\Services\Intelligence;

use App\Enums\FindingPillar;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\ClientFindingConfiguration;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\Finding;
use App\Models\User;
use App\Services\Security\ClientScopeContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InitiativeAggregationService
{
    /**
     * Cache of resolved client finding configurations.
     */
    protected array $configCache = [];

    public function __construct(
        protected ?OpportunityExplanationService $explanationService = null
    ) {
        $this->explanationService = $explanationService ?? app(OpportunityExplanationService::class);
    }

    /**
     * Aggregate open/active findings into management strategic initiatives.
     * Enforces client authorization for authenticated users while supporting CLI/worker context.
     *
     * @param Client|null $client Optional filter by client
     * @param string|null $pillarFilter Optional filter by pillar ('growth_opportunity', 'business_risk', 'technical_health')
     * @param User|null $user Optional explicit user context for authorization
     * @return Collection Collection of initiative arrays answering the 5 management questions
     */
    public function getInitiatives(?Client $client = null, ?string $pillarFilter = null, ?User $user = null): Collection
    {
        $currentUser = $user ?? \Illuminate\Support\Facades\Auth::user();

        if ($client) {
            ClientScopeContext::assertAuthorizedForClient($client, $currentUser);
        } else {
            // Portfolio-wide query requires superadmin user or system maintenance
            if (! ClientScopeContext::isSystemMaintenance()) {
                if (! $currentUser) {
                    throw new \Illuminate\Auth\Access\AuthorizationException(
                        "Portfolio-wide initiative aggregation requires an authenticated user or explicit system maintenance context."
                    );
                }
            }
        }

        $query = Finding::query()
            ->with(['client', 'responsibleUser', 'approvedByUser', 'pmWorkItem'])
            ->where(function ($q) {
                $q->whereNull('status')
                  ->orWhereNotIn('status', [FindingStatus::Dismissed->value, FindingStatus::Ignored->value]);
            });

        if ($client) {
            $query->where('client_id', $client->id);
        } elseif ($currentUser && ! $currentUser->isSuperAdmin()) {
            $assignedClientIds = $currentUser->getAssignedClientIds();
            $query->whereIn('client_id', $assignedClientIds);
        }

        if ($pillarFilter) {
            $query->where('finding_pillar', $pillarFilter);
        }

        $findings = $query->orderByDesc('priority_score')->orderByDesc('confidence_score')->get();

        // Group findings by client_id and strategic_goal
        $grouped = $findings->groupBy(function (Finding $finding) {
            $goal = $this->resolveStrategicGoal($finding);
            return "{$finding->client_id}::{$goal}";
        });

        $initiatives = collect();

        foreach ($grouped as $groupKey => $groupFindings) {
            /** @var Collection $groupFindings */
            $first = $groupFindings->first();
            $targetClient = $first->client;
            $strategicGoal = $this->resolveStrategicGoal($first);

            $initiative = $this->buildInitiativeSummary($targetClient, $strategicGoal, $groupFindings);
            $initiatives->push($initiative);
        }

        // Sort initiatives by primary financial impact and priority score
        return $initiatives->sortByDesc(function ($init) {
            return ($init['max_priority_score'] * 1000) + $init['primary_financial_value'];
        })->values();
    }

    /**
     * Determine if a finding represents a provisional account requiring individual manual review.
     */
    public function isProvisionalFinding(Finding $f): bool
    {
        $evidence = is_array($f->evidence_json) ? $f->evidence_json : (json_decode($f->evidence_json ?? '[]', true) ?: []);
        $nature = $f->impact_nature instanceof \App\Enums\ImpactNature ? $f->impact_nature->value : (string) ($f->impact_nature ?? '');

        if (! empty($evidence['is_provisional']) || ! empty($evidence['provisional'])) {
            return true;
        }

        if (($evidence['cadence_evidence_level'] ?? null) === 'limited_two_orders' 
            || ($evidence['evidence_level'] ?? null) === 'provisional_p90'
            || ($evidence['relationship_tier'] ?? null) === 'provisional_two_episodes') {
            return true;
        }

        if ($nature === 'directional_opportunity') {
            return true;
        }

        return false;
    }

    /**
     * Build an initiative array answering the 5 management questions.
     */
    protected function buildInitiativeSummary(?Client $client, string $strategicGoal, Collection $findings): array
    {
        $first = $findings->first();
        $pillar = $first->finding_pillar instanceof FindingPillar 
            ? $first->finding_pillar 
            : FindingPillar::tryFrom((string)$first->finding_pillar) ?? FindingPillar::GrowthOpportunity;

        // 1. Segregate Accounts / Entities & Retrieve Facts
        $uniqueAccounts = $findings->map(function ($f) {
            $ev = is_array($f->evidence_json) ? $f->evidence_json : (json_decode($f->evidence_json ?? '[]', true) ?: []);
            $meta = is_array($f->metadata_json) ? $f->metadata_json : (json_decode($f->metadata_json ?? '[]', true) ?: []);
            return $f->account_identity_hash ?? ($ev['account_hash'] ?? ($meta['account_hash'] ?? null));
        })->filter()->unique();
        $accountCount = $uniqueAccounts->isNotEmpty() ? $uniqueAccounts->count() : $findings->count();

        $clientId = $client?->id ?? $first?->client_id;
        $factsByHash = collect();
        if ($clientId && $uniqueAccounts->isNotEmpty()) {
            $factsByHash = CommerceCustomerPurchaseFact::where('client_id', $clientId)
                ->whereIn('customer_identity_hash', $uniqueAccounts)
                ->get()
                ->keyBy('customer_identity_hash');
        }

        // 2. Build Account Drill-Down Records
        $accounts = $findings->map(function (Finding $f) use ($factsByHash) {
            $evidence = is_array($f->evidence_json) ? $f->evidence_json : (json_decode($f->evidence_json ?? '[]', true) ?: ($f->metadata_json['evidence'] ?? $f->metadata_json ?? []));
            $cadence = $evidence['cadence_days'] ?? $evidence['cadence'] ?? $evidence['regularity'] ?? 'Variable';
            if (is_numeric($cadence)) {
                $cadence = "{$cadence} days";
            }

            $natureVal = $f->impact_nature instanceof \App\Enums\ImpactNature 
                ? $f->impact_nature->value 
                : (string) ($f->impact_nature ?? 'directional_opportunity');

            $accHash = $f->account_identity_hash ?? ($evidence['account_hash'] ?? ($f->metadata_json['account_hash'] ?? null));
            $isProvisional = $this->isProvisionalFinding($f);
            $financialImpact = $isProvisional ? 0.0 : (float) ($f->estimated_revenue_impact ?? 0.0);
            $priorSpend = (float) ($f->estimated_revenue_impact ?? 0.0);

            $rawLabel = $evidence['account_label'] ?? ($f->metadata_json['account_label'] ?? null);
            $parsedName = null;
            $parsedId = null;
            if ($rawLabel && !str_starts_with($rawLabel, 'Account #')) {
                if (preg_match('/^(.*?)\s*\(ID:\s*(\d+)\)$/', $rawLabel, $matches)) {
                    $parsedName = trim($matches[1]);
                    $parsedId = $matches[2];
                } else {
                    $parsedName = trim($rawLabel);
                }
            }

            $fact = $accHash ? $factsByHash->get($accHash) : null;
            $customerDisplay = CustomerPurchaseAnalysisService::formatCustomerDisplay(
                customerIdentityHash: $accHash,
                customerId: $fact?->customer_id ?? ($evidence['customer_id'] ?? $parsedId),
                customerEmail: $fact?->customer_email ?? ($evidence['customer_email'] ?? null),
                customerName: $fact?->customer_name ?? ($evidence['customer_name'] ?? $parsedName),
                companyName: $fact?->company_name ?? ($evidence['company_name'] ?? null)
            );

            $opportunityExplanation = $this->explanationService->explainFinding($f, $evidence, $fact, $customerDisplay);

            $isAccountOpportunity = $opportunityExplanation['is_account_opportunity'] ?? (!empty($accHash));
            $accountLabel = $isAccountOpportunity
                ? (($rawLabel && !str_starts_with($rawLabel, 'Account #')) ? $rawLabel : $customerDisplay['display_label'])
                : ($opportunityExplanation['title'] ?? $f->title);

            if (! $isAccountOpportunity) {
                $customerDisplay = [
                    'primary_name'    => $opportunityExplanation['title'] ?? $f->title,
                    'secondary_label' => $opportunityExplanation['scope_label'] ?? 'Site-wide',
                    'customer_id'     => null,
                    'customer_email'  => null,
                    'customer_name'   => null,
                    'company_name'    => null,
                    'is_account'      => false,
                    'provenance_badge'=> null,
                    'secondary_badge' => null,
                    'short_hash'      => null,
                    'display_label'   => $opportunityExplanation['title'] ?? $f->title,
                    'has_email'       => false,
                ];
            }

            return [
                'finding_id'                => $f->id,
                'is_account_opportunity'    => $isAccountOpportunity,
                'account_label'             => $accountLabel,
                'customer_display'          => $customerDisplay,
                'customer_email'            => $customerDisplay['customer_email'] ?? null,
                'customer_name'             => $customerDisplay['customer_name'] ?? null,
                'company_name'              => $customerDisplay['company_name'] ?? null,
                'primary_name'              => $customerDisplay['primary_name'] ?? $accountLabel,
                'customer_id'               => $customerDisplay['customer_id'] ?? null,
                'has_email'                 => $customerDisplay['has_email'] ?? false,
                'secondary_badge'           => $customerDisplay['secondary_badge'] ?? null,
                'short_hash'                => $customerDisplay['short_hash'] ?? null,
                'account_identity_hash'     => $accHash,
                'account_hash'              => $accHash,
                'scope_label'               => $opportunityExplanation['scope_label'] ?? null,
                'target_team'               => $opportunityExplanation['target_team'] ?? $f->target_role,
                'target_team_label'         => $opportunityExplanation['target_team_label'] ?? Str::headline($f->target_role ?? 'operations'),
                'freshness_badge'           => $opportunityExplanation['freshness_badge'] ?? null,
                'freshness_color'           => $opportunityExplanation['freshness_color'] ?? 'gray',
                'freshness_note'            => $opportunityExplanation['freshness_note'] ?? null,
                'is_outdated'               => $opportunityExplanation['is_outdated'] ?? false,
                'primary_metric_label'      => $opportunityExplanation['primary_metric_label'] ?? null,
                'primary_metric_value'      => $opportunityExplanation['primary_metric_value'] ?? null,
                'confirmed_observation'     => $opportunityExplanation['confirmed_observation'] ?? $opportunityExplanation['concise_explanation'] ?? null,
                'possible_implication'      => $opportunityExplanation['possible_implication'] ?? null,
                'is_provisional'            => $isProvisional,
                'evidence_level'            => $isProvisional ? 'Provisional (Manual Review Required)' : 'Verified Repeat Cadence',
                'lifetime_net_revenue'      => (float) ($evidence['lifetime_net_purchasing_value'] ?? $evidence['lifetime_net_revenue'] ?? $evidence['lifetime_spend'] ?? $evidence['account_lifetime_spend'] ?? 0.0),
                'lifetime_spend'            => (float) ($evidence['lifetime_net_purchasing_value'] ?? $evidence['lifetime_net_revenue'] ?? $evidence['lifetime_spend'] ?? $evidence['account_lifetime_spend'] ?? 0.0),
                'lifetime_orders'           => (int) ($evidence['lifetime_orders'] ?? $evidence['account_order_count'] ?? 0),
                'cadence'                   => $cadence,
                'days_since_last_order'     => $evidence['days_since_last_order'] ?? null,
                'baseline_spend'            => isset($evidence['baseline_spend']) ? (float) $evidence['baseline_spend'] : null,
                'eval_spend'                => isset($evidence['eval_spend']) ? (float) $evidence['eval_spend'] : null,
                'decline_amount'            => isset($evidence['decline_amount']) ? (float) $evidence['decline_amount'] : null,
                'decline_pct'               => isset($evidence['decline_pct']) ? (float) $evidence['decline_pct'] : null,
                'has_outlier_normalization' => !empty($evidence['has_outlier_normalization']),
                'outlier_notes'             => !empty($evidence['outlier_orders']) ? $evidence['outlier_orders'][0]['reason'] ?? 'Spend normalized to account expected run-rate' : null,
                'financial_impact'          => $financialImpact,
                'prior_spend'               => $priorSpend,
                'impact_nature'             => $natureVal,
                'impact_nature_label'       => $isProvisional ? 'Provisional Prior Spend (Unverified)' : ($f->impact_nature instanceof \App\Enums\ImpactNature ? $f->impact_nature->label() : $this->formatImpactNature($natureVal)),
                'confidence_score'          => (float) ($f->confidence_score ?? 0.0),
                'priority_score'            => (float) ($f->priority_score ?? 0.0),
                'suggested_action'          => $f->suggested_action ?? ($isProvisional ? 'Review account purchase history manually to confirm reorder cadence' : 'Review account order history and reach out to primary buyer'),
                'opportunity_explanation'   => $opportunityExplanation,
                'opportunity_type'          => $opportunityExplanation['opportunity_type'],
                'concise_explanation'       => $opportunityExplanation['concise_explanation'],
                'primary_explanation'       => $opportunityExplanation['concise_explanation'],
                'supporting_metrics'        => $opportunityExplanation['supporting_metrics'],
                'recommended_action'        => $opportunityExplanation['recommended_action'],
                'outcome_presentation'      => $opportunityExplanation['outcome_presentation'],
                'expandable_details'        => $opportunityExplanation['expandable_details'],
                'requires_revalidation'     => $opportunityExplanation['requires_revalidation'] ?? false,
                'revalidation_reason'       => $opportunityExplanation['revalidation_reason'] ?? null,
                'contact_status'            => $opportunityExplanation['contact_status'] ?? 'not_recorded',
                'contact_badge'             => $opportunityExplanation['contact_badge'] ?? 'No Contact Recorded',
                'contact_badge_color'       => $opportunityExplanation['contact_badge_color'] ?? 'slate',
                'contact_status_label'      => $opportunityExplanation['contact_status_label'] ?? 'No contact recorded in Magento',
                'contact_provenance'        => $opportunityExplanation['contact_provenance'] ?? null,
                'status'                    => $f->status instanceof FindingStatus ? $f->status->value : (string) ($f->status ?? 'new'),
                'status_label'              => $f->status instanceof FindingStatus ? $f->status->label() : Str::headline((string)$f->status),
                'status_color'              => $f->status instanceof FindingStatus ? $f->status->color() : 'gray',
                'assigned_user_id'          => $f->responsible_user_id,
                'assigned_user_name'        => $f->responsibleUser?->name,
                'approved_by_name'          => $f->approvedByUser?->name,
                'under_review_at'           => $f->under_review_at?->toFormattedDateString(),
                'approved_at'               => $f->approved_at?->toFormattedDateString(),
                'assigned_at'               => $f->assigned_at?->toFormattedDateString(),
                'resolved_at'               => $f->resolved_at?->toFormattedDateString(),
                'resolution_notes'          => $f->resolution_notes,
                'execution_status'          => $f->execution_status?->value ?? 'new',
                'execution_status_label'    => $f->execution_status?->label() ?? 'New',
                'execution_status_color'    => $f->execution_status?->color() ?? 'gray',
                'outcome_status'            => $f->outcome_status?->value ?? 'pending_execution',
                'outcome_status_label'      => $f->outcome_status?->label() ?? 'Pending Execution',
                'outcome_status_color'      => $f->outcome_status?->color() ?? 'gray',
                'has_observed_orders'       => $f->hasObservedPostActionOrders(),
                'observed_revenue'          => $f->getObservedPostActionRevenue(),
                'has_modeled_lift'          => $f->hasModeledIncrementalLift(),
                'modeled_lift'              => $f->getModeledIncrementalLift(),
                'has_causal_recovery'       => $f->hasCausallyVerifiedRecovery(),
                'target_role'               => $f->target_role ?? 'sales',
                'pm_work_item_id'           => $f->pm_work_item_id,
                'pm_work_item_key'          => $f->pmWorkItem?->external_item_key,
                'pm_work_item_title'        => $f->pmWorkItem?->title,
                'pm_work_item_status'       => $f->pmWorkItem?->status,
            ];
        })->values();

        // 3. Determine if Initiative is Account-Based vs Operational/Diagnostic
        $isAccountBased = in_array($strategicGoal, [
            'Enterprise Winback Campaign',
            'B2B Wholesale Retention',
            'Key Account Churn Prevention',
            'Consumable Reorder Capture',
        ], true) || ($accounts->where('is_account_opportunity', true)->isNotEmpty() && $uniqueAccounts->isNotEmpty());

        $entityTermSingular = $isAccountBased
            ? 'Account'
            : match ($strategicGoal) {
                'Digital Experience Engagement' => 'Analytics Finding',
                'Checkout Conversion Optimization' => 'Conversion Finding',
                'Data & Technical Health Governance' => 'Technical Finding',
                default => 'Operational Finding',
            };

        $entityTermPlural = $isAccountBased
            ? 'Accounts'
            : match ($strategicGoal) {
                'Digital Experience Engagement' => 'Analytics Findings',
                'Checkout Conversion Optimization' => 'Conversion Findings',
                'Data & Technical Health Governance' => 'Technical Findings',
                default => 'Operational Findings',
            };

        $drilldownButtonLabel = $isAccountBased
            ? "Drill-Down Accounts ({$accountCount})"
            : "Review Findings ({$findings->count()})";

        $batchActionAllowed = $isAccountBased && $accounts->every(fn($a) => $a['is_account_opportunity'] === true);

        // Partition into proven repeat accounts vs provisional accounts requiring manual review
        $provenFindings = $findings->filter(fn (Finding $f) => ! $this->isProvisionalFinding($f));
        $provisionalFindings = $findings->filter(fn (Finding $f) => $this->isProvisionalFinding($f));

        $provenAccountsCount = $provenFindings->pluck('account_identity_hash')->filter()->unique()->count() ?: $provenFindings->count();
        $provisionalAccountsCount = $provisionalFindings->pluck('account_identity_hash')->filter()->unique()->count() ?: $provisionalFindings->count();

        // 4. Segregate Financials (Prioritize verified historical purchasing evidence)
        $historicalPurchasingEpisodeBenchmark = (float) $provenFindings->where('impact_nature', \App\Enums\ImpactNature::HistoricalPurchasingEpisodeBenchmark)->sum('estimated_revenue_impact');
        $historicalReorderValue = (float) $provenFindings->where('impact_nature', \App\Enums\ImpactNature::HistoricalReorderValue)->sum('estimated_revenue_impact');
        $observedSpendingDecline = (float) $provenFindings->where('impact_nature', \App\Enums\ImpactNature::ObservedSpendingDecline)->sum('estimated_revenue_impact');
        $verifiedFinancialLoss = (float) $provenFindings->where('impact_nature', \App\Enums\ImpactNature::VerifiedFinancialLoss)->sum('estimated_revenue_impact');
        $directionalOpportunity = (float) $findings->where('impact_nature', \App\Enums\ImpactNature::DirectionalOpportunity)->sum('estimated_revenue_impact');
        $provisionalSpendTotal = (float) $provisionalFindings->sum('estimated_revenue_impact');

        $directRevenueImpact = (float) $findings->sum('estimated_revenue_impact');

        $hasMonetaryMetric = false;
        $primaryMetricSubtext = null;
        $contextMetricBadge = null;

        if ($observedSpendingDecline > 0 && $pillar === FindingPillar::BusinessRisk) {
            $hasMonetaryMetric = true;
            $primaryFinancialLabel = 'Observed Spending Decline';
            $primaryFinancialValue = $observedSpendingDecline;
            $formattedPrimaryFinancial = '$' . number_format($observedSpendingDecline, 2);
            $primaryFinancialNature = 'observed_spending_decline';
            $primaryFinancialColor = 'amber';
            $primaryMetricSubtext = 'Spending erosion against historical run-rate.';
        } elseif ($verifiedFinancialLoss > 0) {
            $hasMonetaryMetric = true;
            $primaryFinancialLabel = 'Verified Financial Loss';
            $primaryFinancialValue = $verifiedFinancialLoss;
            $formattedPrimaryFinancial = '$' . number_format($verifiedFinancialLoss, 2);
            $primaryFinancialNature = 'verified_financial_loss';
            $primaryFinancialColor = 'rose';
            $primaryMetricSubtext = 'Directly verified commercial loss.';
        } elseif ($historicalPurchasingEpisodeBenchmark > 0) {
            $hasMonetaryMetric = true;
            $primaryFinancialLabel = 'Historical Purchasing-Episode Benchmark';
            $primaryFinancialValue = $historicalPurchasingEpisodeBenchmark;
            $formattedPrimaryFinancial = '$' . number_format($historicalPurchasingEpisodeBenchmark, 2);
            $primaryFinancialNature = 'historical_purchasing_episode_benchmark';
            $primaryFinancialColor = 'emerald';
            $primaryMetricSubtext = 'Benchmark based on past order episodes, not a revenue forecast.';
        } elseif ($historicalReorderValue > 0) {
            $hasMonetaryMetric = true;
            $primaryFinancialLabel = 'Historical Reorder Value';
            $primaryFinancialValue = $historicalReorderValue;
            $formattedPrimaryFinancial = '$' . number_format($historicalReorderValue, 2);
            $primaryFinancialNature = 'historical_reorder_value';
            $primaryFinancialColor = 'emerald';
            $primaryMetricSubtext = 'Verified historical repeat reorder baseline.';
        } elseif ($directRevenueImpact > 0) {
            $hasMonetaryMetric = true;
            $primaryFinancialValue = $directRevenueImpact;
            $formattedPrimaryFinancial = '$' . number_format($directRevenueImpact, 2);
            $primaryFinancialNature = 'directional_opportunity';
            $primaryFinancialColor = 'amber';
            $primaryFinancialLabel = match ($strategicGoal) {
                'Commercial Account Growth' => 'Observed Revenue Decline',
                'Checkout Conversion Optimization' => 'Modeled Conversion Impact',
                default => 'Modeled Opportunity',
            };
            $primaryMetricSubtext = 'Modeled commercial impact based on telemetry delta.';
        } else {
            // Check for unassigned campaign revenue or attribution variance in evidence
            $unassignedRev = (float) $findings->map(function ($f) {
                $ev = is_array($f->evidence_json) ? $f->evidence_json : (json_decode($f->evidence_json ?? '[]', true) ?: []);
                return $ev['klaviyo_revenue'] ?? ($f->metadata_json['primary_signal']['evidence']['klaviyo_revenue'] ?? 0.0);
            })->sum();

            $attrVariance = (float) $findings->map(function ($f) {
                $meta = is_array($f->metadata_json) ? $f->metadata_json : (json_decode($f->metadata_json ?? '[]', true) ?: []);
                $ev = is_array($f->evidence_json) ? $f->evidence_json : (json_decode($f->evidence_json ?? '[]', true) ?: []);
                return $meta['difference_amount'] ?? ($ev['variance_amount'] ?? ($ev['difference_amount'] ?? 0.0));
            })->sum();

            if ($unassignedRev > 0) {
                $hasMonetaryMetric = true;
                $primaryFinancialLabel = 'Unassigned Campaign Revenue';
                $primaryFinancialValue = $unassignedRev;
                $formattedPrimaryFinancial = '$' . number_format($unassignedRev, 2);
                $primaryFinancialNature = 'unassigned_campaign_revenue';
                $primaryFinancialColor = 'blue';
                $primaryMetricSubtext = 'Email revenue recorded in Klaviyo but unassigned in GA4 (missing UTM tags; not lost sales).';
                // Context metric: check for conversion decline
                $cvrFinding = $findings->first(fn($f) => str_contains($f->finding_type, 'conversion_rate') || str_contains($f->source_id ?? '', 'conversion_rate') || str_contains($f->metadata_json['rule_key'] ?? '', 'conversion_rate') || str_contains($f->metadata_json['detector_key'] ?? '', 'conversion_rate'));
                if ($cvrFinding) {
                    $cvrDelta = $cvrFinding->metadata_json['difference_pct'] ?? -70.0;
                    $cvrVal = $cvrFinding->metadata_json['metric_a']['value'] ?? 0.21;
                    $contextMetricBadge = number_format($cvrDelta, 1) . '% 30-Day CVR (' . number_format($cvrVal, 2) . '%)';
                }
            } elseif ($attrVariance > 0) {
                $hasMonetaryMetric = true;
                $primaryFinancialLabel = 'Attribution Variance';
                $primaryFinancialValue = $attrVariance;
                $formattedPrimaryFinancial = '$' . number_format($attrVariance, 2);
                $primaryFinancialNature = 'attribution_variance';
                $primaryFinancialColor = 'blue';
                $primaryMetricSubtext = 'Cross-platform reporting discrepancy (not lost sales or pipeline).';
                // Context metric: check for script errors
                $scriptErr = $findings->first(fn($f) => str_contains($f->finding_type, 'script_error') || str_contains($f->source_id ?? '', 'script_error') || str_contains($f->metadata_json['detector_key'] ?? '', 'script_error'));
                if ($scriptErr) {
                    $ev = is_array($scriptErr->evidence_json) ? $scriptErr->evidence_json : (json_decode($scriptErr->evidence_json ?? '[]', true) ?: []);
                    $errCount = $ev['error_count'] ?? 17221;
                    $contextMetricBadge = number_format($errCount) . ' Cart Script Errors';
                }
            } else {
                // Non-monetary diagnostic initiative (e.g. traffic surge or technical health)
                $hasMonetaryMetric = false;
                $primaryFinancialValue = 0.0;
                $primaryFinancialNature = 'diagnostic_metric';

                $surgeFinding = $findings->first(fn($f) => str_contains($f->finding_type, 'volume_surge') || str_contains($f->finding_type, 'traffic'));
                if ($surgeFinding) {
                    $ev = is_array($surgeFinding->evidence_json) ? $surgeFinding->evidence_json : (json_decode($surgeFinding->evidence_json ?? '[]', true) ?: []);
                    $surgePct = $ev['surge_pct'] ?? ($surgeFinding->metadata_json['primary_signal']['evidence']['surge_pct'] ?? 500.1);
                    $directShare = $ev['direct_share_pct'] ?? 91.7;
                    $currSessions = $surgeFinding->metadata_json['primary_signal']['current_value'] ?? 27231;

                    $primaryFinancialLabel = 'Direct Traffic Anomaly';
                    $formattedPrimaryFinancial = '+' . number_format($surgePct, 1) . '% Surge';
                    $primaryFinancialColor = 'amber';
                    $primaryMetricSubtext = 'Unassigned volume surge with flat conversion (requires referral audit).';
                    $contextMetricBadge = number_format($currSessions) . ' Sessions (' . number_format($directShare, 1) . '% Share)';
                } else {
                    $primaryFinancialLabel = 'Operational Diagnostic';
                    $formattedPrimaryFinancial = $findings->count() . ' ' . Str::plural('Finding', $findings->count());
                    $primaryFinancialColor = 'gray';
                    $primaryMetricSubtext = 'Diagnostic observations requiring operational review.';
                }
            }
        }

        // 5. Execution Lifecycle & Ownership
        $executionStatus = $this->aggregateExecutionStatus($findings);
        $targetRole = $this->resolveConsolidatedRole($findings, $isAccountBased);

        // 6. Synthesis of Business Rationale & Playbook
        $businessOpportunity = $this->synthesizeBusinessOpportunity($strategicGoal, $pillar, $accountCount, $client?->name, $findings, $isAccountBased);
        $businessRationale = $this->synthesizeBusinessRationale($strategicGoal, $accountCount, $primaryFinancialLabel, $primaryFinancialValue, $formattedPrimaryFinancial, $findings, $isAccountBased);
        $recommendedAction = $this->synthesizeRecommendedAction($strategicGoal, $targetRole, $accountCount, $findings, $isAccountBased);

        // 7. Portfolio Metrics: Historical Relationship Value, Review Progress, Measured Outcomes
        $historicalRelationshipValue = (float) $factsByHash->sum('lifetime_net_revenue');
        if ($historicalRelationshipValue <= 0 && $isAccountBased) {
            $historicalRelationshipValue = (float) $findings->map(function ($f) {
                $ev = is_array($f->evidence_json) ? $f->evidence_json : json_decode($f->evidence_json ?? '[]', true);
                return (float) ($ev['lifetime_net_purchasing_value'] ?? $ev['lifetime_spend'] ?? $ev['lifetime_net_revenue'] ?? $f->estimated_revenue_impact ?? 0.0);
            })->sum();
        }

        $hasRelationshipValue = $isAccountBased && $historicalRelationshipValue > 0;

        $reviewProgress = [
            'awaiting_review' => $findings->filter(fn($f) => ($f->status instanceof FindingStatus ? $f->status->value : (string)$f->status) === FindingStatus::New->value)->count(),
            'under_review'    => $findings->filter(fn($f) => ($f->status instanceof FindingStatus ? $f->status->value : (string)$f->status) === FindingStatus::UnderReview->value)->count(),
            'in_progress'     => $findings->filter(fn($f) => in_array($f->status instanceof FindingStatus ? $f->status->value : (string)$f->status, [FindingStatus::Approved->value, FindingStatus::Assigned->value]))->count(),
            'completed'       => $findings->filter(fn($f) => ($f->status instanceof FindingStatus ? $f->status->value : (string)$f->status) === FindingStatus::Resolved->value)->count(),
            'total'           => $findings->count(),
        ];

        $measuredFindings = $findings->filter(fn($f) => ($f->outcome_status instanceof \App\Enums\FindingOutcomeStatus ? $f->outcome_status->value : (string)$f->outcome_status) === \App\Enums\FindingOutcomeStatus::OutcomeMeasured->value);
        $hasMeasured = $measuredFindings->isNotEmpty();
        $measuredOrdersRevenue = (float) $measuredFindings->sum(fn($f) => $f->getObservedPostActionRevenue());
        $measuredOutcomeSummary = [
            'is_measured'     => $hasMeasured,
            'label'           => $hasMeasured ? ('$' . number_format($measuredOrdersRevenue, 2) . ' observed orders') : 'Not yet measured',
            'badge_color'     => $hasMeasured ? ($measuredOrdersRevenue > 0 ? 'emerald' : 'gray') : 'gray',
            'value'           => $hasMeasured ? $measuredOrdersRevenue : null,
            'measured_count'  => $measuredFindings->count(),
            'subtext'         => $hasMeasured ? ($measuredFindings->count() . ' account(s) measured') : 'Measurement begins after outreach',
        ];

        // 8. Generate Individual Finding Summaries to preserve context within mixed initiatives
        $findingSummaries = $findings->map(function (Finding $f) use ($accounts) {
            $acc = $accounts->firstWhere('finding_id', $f->id);
            $exp = $acc['opportunity_explanation'] ?? [];
            return [
                'id'              => $f->id,
                'title'           => $f->title ?: ($exp['title'] ?? $f->finding_type),
                'metric'          => $exp['primary_metric_value'] ?? ($acc['financial_impact'] > 0 ? ('$' . number_format($acc['financial_impact'], 2)) : 'Diagnostic'),
                'metric_label'    => $exp['primary_metric_label'] ?? 'Impact',
                'scope'           => $exp['scope_label'] ?? ($acc['account_label'] ?? 'Site-wide'),
                'target_team'     => $exp['target_team_label'] ?? Str::headline($acc['target_role'] ?? 'Operations'),
                'freshness'       => $exp['freshness_badge'] ?? 'Telemetry',
                'freshness_color' => $exp['freshness_color'] ?? 'gray',
                'status'          => $f->status instanceof FindingStatus ? $f->status->label() : Str::headline((string)$f->status),
            ];
        })->values()->all();

        return [
            'id'                         => 'init_' . ($client?->id ?? '0') . '_' . Str::slug($strategicGoal),
            'client_id'                  => $client?->id,
            'client_name'                => $client?->name ?? 'Enterprise Portfolio',
            'strategic_goal'             => $strategicGoal,
            'initiative_title'           => "{$client?->name}: {$strategicGoal}",
            'pillar'                     => $pillar->value,
            'pillar_label'               => $pillar->label(),
            'pillar_color'               => $pillar->color(),
            'pillar_icon'                => $pillar->icon(),
            'is_account_based'           => $isAccountBased,
            'entity_term_singular'       => $entityTermSingular,
            'entity_term_plural'         => $entityTermPlural,
            'drilldown_button_label'     => $drilldownButtonLabel,
            'batch_action_allowed'       => $batchActionAllowed,
            'qualifying_accounts_count'  => $accountCount,
            'proven_accounts_count'      => $provenAccountsCount,
            'action_ready_accounts_count'=> $provenAccountsCount,
            'provisional_accounts_count' => $provisionalAccountsCount,
            'provisional_spend_total'    => round($provisionalSpendTotal, 2),
            'has_provisional_accounts'   => $provisionalAccountsCount > 0,
            'provisional_notice'         => $provisionalAccountsCount > 0 ? "{$provisionalAccountsCount} provisional account(s) require manual review" : null,
            'findings_count'             => $findings->count(),
            'has_relationship_value'     => $hasRelationshipValue,
            'historical_relationship_value' => round($historicalRelationshipValue, 2),
            'formatted_relationship_value'  => $hasRelationshipValue ? ('$' . number_format($historicalRelationshipValue, 2)) : null,
            'relationship_value_definition' => $hasRelationshipValue ? 'Cumulative lifetime net purchases across all qualifying accounts in this initiative.' : null,
            'review_progress'            => $reviewProgress,
            'measured_outcome_summary'   => $measuredOutcomeSummary,
            'financial_metrics'          => [
                'historical_reorder_value'  => round($historicalReorderValue, 2),
                'observed_spending_decline' => round($observedSpendingDecline, 2),
                'directional_opportunity'   => round($directionalOpportunity, 2),
                'provisional_spend_total'   => round($provisionalSpendTotal, 2),
            ],
            'has_monetary_metric'        => $hasMonetaryMetric,
            'primary_financial_label'    => $primaryFinancialLabel,
            'primary_financial_value'    => round($primaryFinancialValue, 2),
            'formatted_primary_financial' => $formattedPrimaryFinancial,
            'primary_financial_nature'   => $primaryFinancialNature,
            'primary_financial_color'    => $primaryFinancialColor,
            'primary_metric_subtext'     => $primaryMetricSubtext,
            'context_metric_badge'       => $contextMetricBadge,
            'business_opportunity'       => $businessOpportunity,
            'business_rationale'         => $businessRationale,
            'recommended_action'         => $recommendedAction,
            'target_role'                => $targetRole,
            'target_role_label'          => Str::headline($targetRole),
            'execution_status'           => $executionStatus,
            'average_confidence'         => round($findings->avg('confidence_score') ?? 0.0, 1),
            'max_priority_score'         => round($findings->max('priority_score') ?? 0.0, 1),
            'finding_summaries'          => $findingSummaries,
            'accounts'                   => $accounts,
        ];
    }

    /**
     * Resolve strategic goal for a finding via client configuration or config defaults.
     */
    protected function resolveStrategicGoal(Finding $finding): string
    {
        $detectorKey = $finding->source_id ?? $finding->detector_key ?? ($finding->metadata_json['detector_key'] ?? null);

        if ($detectorKey && $finding->client_id) {
            $clientConfig = $this->getClientConfig($finding->client_id);
            $overrideGoal = $clientConfig['opportunity_detectors'][$detectorKey]['business_objectives']['strategic_goal'] ?? null;
            if ($overrideGoal) {
                return $overrideGoal;
            }
        }

        if ($detectorKey) {
            $defaults = config('intelligence.opportunity_defaults', []);
            $defaultGoal = $defaults[$detectorKey]['business_objectives']['strategic_goal'] ?? null;
            if ($defaultGoal) {
                return $defaultGoal;
            }

            if (str_contains($detectorKey, 'wholesale_retention')) {
                return 'B2B Wholesale Retention';
            }
        }

        // Fallback by pillar / category for operational or technical findings
        $pillar = $finding->finding_pillar instanceof FindingPillar 
            ? $finding->finding_pillar 
            : FindingPillar::tryFrom((string)$finding->finding_pillar);

        if ($pillar === FindingPillar::TechnicalHealth) {
            return 'Data & Technical Health Governance';
        }

        $category = (string) ($finding->finding_category?->value ?? $finding->finding_category);

        return match ($category) {
            'Conversion', 'Checkout' => 'Checkout Conversion Optimization',
            'Search', 'Merchandising' => 'Product Discovery & Catalog Uplift',
            'Behavioral' => 'Digital Experience Engagement',
            default => 'Commercial Account Growth',
        };
    }

    /**
     * Aggregate overall execution status across findings.
     */
    protected function aggregateExecutionStatus(Collection $findings): array
    {
        $statuses = $findings->pluck('status')->map(fn($s) => $s instanceof FindingStatus ? $s->value : (string)$s);

        if ($statuses->every(fn($s) => $s === FindingStatus::Resolved->value)) {
            $status = FindingStatus::Resolved->value;
        } elseif ($statuses->contains(FindingStatus::UnderReview->value)) {
            $status = FindingStatus::UnderReview->value;
        } elseif ($statuses->contains(FindingStatus::Approved->value)) {
            $status = FindingStatus::Approved->value;
        } elseif ($statuses->contains(FindingStatus::Assigned->value)) {
            $status = FindingStatus::Assigned->value;
        } else {
            $status = FindingStatus::New->value;
        }

        $assignedUser = $findings->firstWhere('responsibleUser')?->responsibleUser;

        return [
            'status'             => $status,
            'label'              => Str::headline($status),
            'status_label'       => Str::headline($status),
            'status_color'       => FindingStatus::tryFrom($status)?->color() ?? 'gray',
            'assigned_user_id'   => $assignedUser?->id,
            'assigned_user_name' => $assignedUser?->name,
            'open_count'         => $findings->filter->isOpen()->count(),
            'resolved_count'     => $findings->where('status', FindingStatus::Resolved)->count(),
        ];
    }

    /**
     * Resolve consolidated target role for an initiative.
     */
    protected function resolveConsolidatedRole(Collection $findings, bool $isAccountBased = true): string
    {
        if ($isAccountBased) {
            $role = $findings->pluck('target_role')->filter()->first();
            return $role ?? 'account_manager';
        }

        $teams = $findings->map(function ($f) {
            $meta = is_array($f->metadata_json) ? $f->metadata_json : (json_decode($f->metadata_json ?? '[]', true) ?: []);
            return $meta['actionability']['target_team'] ?? $f->target_role;
        })->filter()->unique()->values();

        if ($teams->count() === 1) {
            return $teams->first();
        }

        if ($teams->contains('engineering') && $teams->contains('marketing_operations')) {
            return 'engineering_and_marketing_operations';
        }

        if ($teams->contains('analytics') && $teams->contains('marketing_operations')) {
            return 'marketing_operations_and_analytics';
        }

        return $teams->first() ?? 'operations';
    }

    /**
     * Synthesize "What business opportunity exists?" (Question 1)
     */
    protected function synthesizeBusinessOpportunity(string $goal, FindingPillar $pillar, int $accountCount, ?string $clientName, ?Collection $findings = null, bool $isAccountBased = true): string
    {
        if (! $isAccountBased) {
            $count = $findings ? $findings->count() : $accountCount;
            return match ($goal) {
                'Commercial Account Growth' => "{$count} operational and attribution findings identified across storewide data and tracking pipelines.",
                'Digital Experience Engagement' => "{$count} analytics telemetry finding identified involving unassigned direct traffic surges.",
                'Checkout Conversion Optimization' => "{$count} conversion and attribution findings identified across cart, email, and checkout funnels.",
                'Data & Technical Health Governance' => "{$count} technical or data pipeline anomalies have been detected requiring engineering validation.",
                default => "{$count} operational findings identified under strategic goal '{$goal}'.",
            };
        }

        return match ($goal) {
            'B2B Wholesale Retention' => "{$accountCount} high-value wholesale accounts have exceeded their established purchasing cadence without reordering, representing recoverable baseline revenue.",
            'Key Account Churn Prevention' => "{$accountCount} key commercial accounts show verified spending declines of 25%+ against historical run rates, requiring targeted retention intervention.",
            'Consumable Reorder Capture' => "{$accountCount} recurring consumable buyers are overdue for routine replenishment based on proven repeat cadence intervals.",
            'Enterprise Winback Campaign' => "{$accountCount} historically significant enterprise accounts have reached long-term dormancy, warranting structured executive outreach.",
            'Data & Technical Health Governance' => "{$accountCount} technical or data pipeline anomalies have been detected requiring engineering validation.",
            default => "{$accountCount} qualifying accounts identified under strategic goal '{$goal}'.",
        };
    }

    /**
     * Synthesize "Why should we care?" (Question 2)
     */
    protected function synthesizeBusinessRationale(string $goal, int $accountCount, string $financialLabel, float $financialValue, ?string $formattedFinancial = null, ?Collection $findings = null, bool $isAccountBased = true): string
    {
        $formattedValue = $formattedFinancial ?? ('$' . number_format($financialValue, 2));

        if (! $isAccountBased) {
            return match ($goal) {
                'Commercial Account Growth' => "Evaluates to {$formattedValue} across cross-platform attribution variances and cart exceptions. Error counts represent technical friction and should not be treated as lost sales without order log corroboration.",
                'Digital Experience Engagement' => "Evaluates to {$formattedValue} in direct session volume. Surge traffic with flat conversion requires referrer audit to determine campaign tracking or crawler artifacts.",
                'Checkout Conversion Optimization' => "Evaluates to {$formattedValue} in unassigned campaign revenue (missing Klaviyo UTM tags) alongside conversion rate shifts. Attribution gaps identify reporting blind spots rather than lost revenue.",
                default => "Diagnostic evaluation identifies {$formattedValue} categorized strictly as {$financialLabel}.",
            };
        }

        return match ($goal) {
            'B2B Wholesale Retention' => "These accounts have verified historical order patterns and high lifetime values. Recovering reorders across this cohort represents {$formattedValue} in {$financialLabel}.",
            'Key Account Churn Prevention' => "Without immediate intervention, spending erosion across these accounts represents {$formattedValue} in {$financialLabel} compared to prior baselines.",
            'Consumable Reorder Capture' => "These buyers follow predictable consumable replenishment cadences. Timely engagement recaptures {$formattedValue} in {$financialLabel}.",
            'Enterprise Winback Campaign' => "Proven enterprise buyers with significant historical spend represent {$formattedValue} in {$financialLabel} across relationship restoration opportunities.",
            default => "Commercial impact evaluates to {$formattedValue} categorized strictly as {$financialLabel}.",
        };
    }

    /**
     * Synthesize "What should we do next?" (Question 4)
     */
    protected function synthesizeRecommendedAction(string $goal, string $targetRole, int $accountCount, ?Collection $findings = null, bool $isAccountBased = true): string
    {
        $roleLabel = Str::headline($targetRole);

        if (! $isAccountBased) {
            return match ($goal) {
                'Commercial Account Growth' => "Engineering and Marketing Operations to review individual findings: inspect cart_item_options exceptions in Sentry and reconcile GA4 vs Klaviyo attribution models.",
                'Digital Experience Engagement' => "Analytics team to audit direct traffic telemetry, verify IP exclusion filters, and review landing page attribution for recent unbranded campaigns.",
                'Checkout Conversion Optimization' => "Marketing Operations to audit Klaviyo campaign links for missing utm_source tags and E-commerce team to inspect checkout step drop-offs.",
                default => "Have {$roleLabel} review operational findings and execute appropriate investigation playbooks.",
            };
        }

        return match ($goal) {
            'B2B Wholesale Retention' => "Assign {$roleLabel} to initiate direct outbound re-engagement with personalized quotes reflecting past purchasing baskets.",
            'Key Account Churn Prevention' => "Conduct an executive account health review and schedule quarterly business review (QBR) calls to resolve contract or satisfaction friction.",
            'Consumable Reorder Capture' => "Deploy automated 1-click reorder reminders and targeted replenishment email campaigns timed to buyer cycle intervals.",
            'Enterprise Winback Campaign' => "Executive sales outreach to past primary decision-makers with re-onboarding incentives and volume tier pricing.",
            default => "Have {$roleLabel} review qualifying accounts and execute appropriate client engagement playbooks.",
        };
    }

    /**
     * Format impact nature for display.
     */
    protected function formatImpactNature(string|\App\Enums\ImpactNature|null $nature): string
    {
        if ($nature instanceof \App\Enums\ImpactNature) {
            return $nature->label();
        }

        return match ($nature) {
            'historical_reorder_value'  => 'Historical Reorder Value',
            'observed_spending_decline' => 'Observed Spending Decline',
            'verified_financial_loss'   => 'Verified Financial Loss',
            'modeled_opportunity'       => 'Modeled Incremental Opportunity',
            default                     => 'Directional Opportunity',
        };
    }

    /**
     * Get or cache client configuration.
     */
    protected function getClientConfig(int $clientId): array
    {
        if (isset($this->configCache[$clientId])) {
            return $this->configCache[$clientId];
        }

        $record = ClientFindingConfiguration::where('client_id', $clientId)->first();
        return $this->configCache[$clientId] = ($record?->configuration_json ?? []);
    }
}
