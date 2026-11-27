<?php

namespace App\Services\Intelligence;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingStatus;
use App\Enums\ImpactNature;
use App\Models\Client;
use App\Models\ClientFindingConfiguration;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceMetric;
use App\Models\Finding;
use App\Models\User;
use App\Services\Intelligence\DataQualification\FindingFreshnessService;
use App\Services\Metrics\MetricsDiagnosticService;
use App\Services\Metrics\RevenueReconciler;
use App\Services\Security\ClientScopeContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InitiativeAggregationService
{
    /**
     * Cache of resolved client finding configurations.
     */
    protected array $configCache = [];

    protected FindingFreshnessService $freshnessService;

    public function __construct(
        protected ?OpportunityExplanationService $explanationService = null,
        ?FindingFreshnessService $freshnessService = null
    ) {
        $this->explanationService = $explanationService ?? app(OpportunityExplanationService::class);
        $this->freshnessService = $freshnessService ?? app(FindingFreshnessService::class);
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
            ->with(['client', 'responsibleUser', 'approvedByUser', 'pmWorkItem', 'outcomes'])
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
            if ($pillarFilter === 'unclassified') {
                $query->whereNull('finding_pillar');
            } else {
                $query->where(function ($q) use ($pillarFilter) {
                    $q->where('finding_pillar', $pillarFilter)
                      ->orWhereNull('finding_pillar');
                });
            }
        }

        $findings = $query->orderByDesc('priority_score')->orderByDesc('confidence_score')->get();

        // Constituent-finding-level pillar filtering (resolving known NULL pillars via FindingTypeRegistry without defaulting unknown types)
        if ($pillarFilter) {
            $findings = $findings->filter(function (Finding $finding) use ($pillarFilter) {
                $resolved = $this->resolveFindingPillar($finding);
                if ($pillarFilter === 'unclassified') {
                    return ! empty($resolved['requires_classification']);
                }
                return $resolved['pillar_value'] === $pillarFilter;
            })->values();
        }

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
     * Resolve authoritative FindingPillar for a finding without defaulting unknown types to GrowthOpportunity.
     *
     * @return array{pillar: ?FindingPillar, pillar_value: ?string, is_known: bool, requires_classification: bool}
     */
    protected function resolveFindingPillar(Finding $finding): array
    {
        if ($finding->finding_pillar instanceof FindingPillar) {
            return [
                'pillar'                  => $finding->finding_pillar,
                'pillar_value'            => $finding->finding_pillar->value,
                'is_known'                => true,
                'requires_classification' => false,
            ];
        }

        $raw = (string) ($finding->finding_pillar ?? '');
        if ($raw !== '' && ($enum = FindingPillar::tryFrom($raw))) {
            return [
                'pillar'                  => $enum,
                'pillar_value'            => $enum->value,
                'is_known'                => true,
                'requires_classification' => false,
            ];
        }

        $reg = FindingTypeRegistry::classify($finding);
        return [
            'pillar'                  => $reg['pillar'],
            'pillar_value'            => $reg['pillar_value'],
            'is_known'                => $reg['is_known'],
            'requires_classification' => $reg['requires_classification'],
        ];
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
        $pillarInfo = $this->resolveFindingPillar($first);
        $pillar = $pillarInfo['pillar'];
        $isUnclassifiedPillar = ! $pillarInfo['is_known'] || $pillar === null;

        // 1. Segregate Accounts / Entities & Retrieve Batched Facts and Order Summaries (No N+1 queries)
        $uniqueAccounts = $findings->map(function ($f) {
            $ev = is_array($f->evidence_json) ? $f->evidence_json : (json_decode($f->evidence_json ?? '[]', true) ?: []);
            $meta = is_array($f->metadata_json) ? $f->metadata_json : (json_decode($f->metadata_json ?? '[]', true) ?: []);
            return $f->account_identity_hash ?? ($ev['account_hash'] ?? ($meta['account_hash'] ?? null));
        })->filter()->unique();
        $accountCount = $uniqueAccounts->isNotEmpty() ? $uniqueAccounts->count() : $findings->count();

        $clientId = $client?->id ?? $first?->client_id;
        $factsByHash = collect();
        $winbackOrderSummaries = [];
        if ($clientId && $uniqueAccounts->isNotEmpty()) {
            $hashList = $uniqueAccounts->values()->all();
            $factsByHash = CommerceCustomerPurchaseFact::where('client_id', $clientId)
                ->whereIn('customer_identity_hash', $hashList)
                ->get()
                ->keyBy('customer_identity_hash');

            if (in_array($strategicGoal, ['Enterprise Winback Campaign', 'B2B Wholesale Retention'], true)) {
                $winbackOrderSummaries = app(CustomerPurchaseAnalysisService::class)
                    ->getWinbackOrderSummariesForHashes((int) $clientId, $hashList);
            }
        }

        // 2. Build Account Drill-Down Records
        $accounts = $findings->map(function (Finding $f) use ($factsByHash, $winbackOrderSummaries, $client) {
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

            // Inject batched order-header summary for Winback accounts
            if ($accHash && isset($winbackOrderSummaries[$accHash])) {
                $evidence['winback_order_summary'] = $winbackOrderSummaries[$accHash];
            }

            // Inject 6-timestamp freshness contract & authoritative diagnostics for operational findings
            $isOperationalFinding = empty($accHash) && ! in_array($f->source_id ?? $f->finding_type, ['long_term_winback', 'opportunity.long_term_winback', 'b2b_account_reactivation', 'opportunity.b2b_account_reactivation', 'replenishment_cadence', 'opportunity.replenishment_cadence', 'account_contraction', 'risk.account_contraction'], true);
            if ($isOperationalFinding) {
                $freshnessContract = $this->freshnessService->evaluateFinding($f);
                $evidence['freshness_contract'] = $freshnessContract;

                if ($client && empty($evidence['reconciliation_summary']) && $this->findingMatchesSignal($f, ['platform_variance', 'ga4_vs_commerce'])) {
                    $evidence['reconciliation_summary'] = $this->resolveOperationalReconciliationSummary($client, $freshnessContract);
                }
                if ($client && empty($evidence['conversion_diagnostics']) && empty($f->metadata_json['conversion_diagnostics']) && $this->findingMatchesSignal($f, ['conversion_rate'])) {
                    $evidence['conversion_diagnostics'] = $this->resolveOperationalConversionDiagnostics($client, $f, $freshnessContract);
                }
                if ($client && empty($evidence['klaviyo_diagnostics']) && empty($f->metadata_json['klaviyo_diagnostics']) && $this->findingMatchesSignal($f, ['klaviyo']) && $this->findingMatchesSignal($f, ['attribution_variance'])) {
                    $evidence['klaviyo_diagnostics'] = $this->resolveOperationalKlaviyoDiagnostics($client, $f, $freshnessContract);
                }
            }

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
                'freshness_state'           => $opportunityExplanation['freshness_state'] ?? null,
                'observation_period'        => $opportunityExplanation['observation_period'] ?? null,
                'last_order_date_label'     => $opportunityExplanation['last_order_date_label'] ?? null,
                'last_order_amount'         => $opportunityExplanation['last_order_amount'] ?? null,
                'evaluated_date_label'      => $opportunityExplanation['evaluated_date_label'] ?? null,
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
                'Digital Experience Engagement', 'Direct Traffic & Referral Audit' => 'Analytics Finding',
                'Checkout Conversion Optimization' => 'Conversion Finding',
                'Revenue & Channel Attribution' => 'Attribution Finding',
                'Storewide Revenue Performance' => 'Revenue Finding',
                'Storefront & Script Health', 'Data & Technical Health Governance' => 'Technical Finding',
                default => 'Operational Finding',
            };

        $entityTermPlural = $isAccountBased
            ? 'Accounts'
            : match ($strategicGoal) {
                'Digital Experience Engagement', 'Direct Traffic & Referral Audit' => 'Analytics Findings',
                'Checkout Conversion Optimization' => 'Conversion Findings',
                'Revenue & Channel Attribution' => 'Attribution Findings',
                'Storewide Revenue Performance' => 'Revenue Findings',
                'Storefront & Script Health', 'Data & Technical Health Governance' => 'Technical Findings',
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

            if ($attrVariance > 0) {
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
                    $errCount = $ev['error_count'] ?? null;
                    if ($errCount !== null) {
                        $contextMetricBadge = number_format((int) $errCount) . ' Clarity Script Errors';
                    }
                }
            } elseif ($unassignedRev > 0) {
                $hasMonetaryMetric = true;
                $primaryFinancialLabel = 'Unassigned Campaign Revenue';
                $primaryFinancialValue = $unassignedRev;
                $formattedPrimaryFinancial = '$' . number_format($unassignedRev, 2);
                $primaryFinancialNature = 'unassigned_campaign_revenue';
                $primaryFinancialColor = 'blue';
                $primaryMetricSubtext = 'Email revenue attributed in Klaviyo vs GA4 Email (attribution-window or tagging variance; not lost sales).';
                // Context metric: check for conversion decline
                $cvrFinding = $findings->first(fn($f) => str_contains($f->finding_type, 'conversion_rate') || str_contains($f->source_id ?? '', 'conversion_rate') || str_contains($f->metadata_json['rule_key'] ?? '', 'conversion_rate') || str_contains($f->metadata_json['detector_key'] ?? '', 'conversion_rate'));
                if ($cvrFinding && isset($cvrFinding->metadata_json['difference_pct'], $cvrFinding->metadata_json['metric_a']['value'])) {
                    $cvrDelta = (float) $cvrFinding->metadata_json['difference_pct'];
                    $cvrVal = (float) $cvrFinding->metadata_json['metric_a']['value'];
                    $contextMetricBadge = number_format($cvrDelta, 1) . '% 30-Day CVR (' . number_format($cvrVal, 2) . '%)';
                }
            } else {
                // Non-monetary diagnostic initiative (e.g. traffic surge or technical health)
                $hasMonetaryMetric = false;
                $primaryFinancialValue = 0.0;
                $primaryFinancialNature = 'diagnostic_metric';

                $surgeFinding = $findings->first(fn($f) => str_contains($f->finding_type, 'volume_surge') || str_contains($f->finding_type, 'traffic'));
                if ($surgeFinding) {
                    $ev = is_array($surgeFinding->evidence_json) ? $surgeFinding->evidence_json : (json_decode($surgeFinding->evidence_json ?? '[]', true) ?: []);
                    $surgePct = (float) ($ev['surge_pct'] ?? ($surgeFinding->metadata_json['primary_signal']['evidence']['surge_pct'] ?? 0.0));
                    $directShare = (float) ($ev['direct_share_pct'] ?? 0.0);
                    $currSessions = (int) ($ev['direct_sessions'] ?? $surgeFinding->metadata_json['primary_signal']['current_value'] ?? 0);

                    $primaryFinancialLabel = 'Direct Traffic Anomaly';
                    $formattedPrimaryFinancial = '+' . number_format($surgePct, 1) . '% Surge';
                    $primaryFinancialColor = 'amber';
                    $primaryMetricSubtext = 'Unassigned Direct traffic surge requiring referrer and log inspection.';
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

        $awaitingReviewCount = 0;
        $underReviewCount = 0;
        $approvedOrAssignedCount = 0;
        $workInProgressCount = 0;
        $completedCount = 0;
        $inObservationCount = 0;

        foreach ($findings as $f) {
            $statusVal = $f->status instanceof FindingStatus ? $f->status->value : (string) ($f->status ?? 'new');
            $execVal = $f->execution_status instanceof FindingExecutionStatus ? $f->execution_status->value : (string) ($f->execution_status ?? '');
            $outcomeVal = $f->outcome_status instanceof FindingOutcomeStatus ? $f->outcome_status->value : (string) ($f->outcome_status ?? '');

            if ($outcomeVal === FindingOutcomeStatus::AwaitingResults->value) {
                $inObservationCount++;
            }

            if (in_array($execVal, [FindingExecutionStatus::ActionCompleted->value, FindingExecutionStatus::Closed->value], true)
                || $statusVal === FindingStatus::Resolved->value) {
                $completedCount++;
            } elseif ($execVal === FindingExecutionStatus::WorkInProgress->value) {
                $workInProgressCount++;
            } elseif (in_array($statusVal, [FindingStatus::Approved->value, FindingStatus::Assigned->value, FindingStatus::Accepted->value], true)) {
                $approvedOrAssignedCount++;
            } elseif (in_array($statusVal, [FindingStatus::UnderReview->value, FindingStatus::Investigating->value], true)) {
                $underReviewCount++;
            } else {
                $awaitingReviewCount++;
            }
        }

        $provenEligibleCount = $batchActionAllowed
            ? $findings->filter(function (Finding $f) {
                $statusVal = $f->status instanceof FindingStatus ? $f->status->value : (string) ($f->status ?? 'new');
                return in_array($statusVal, [FindingStatus::New->value, FindingStatus::UnderReview->value, FindingStatus::Investigating->value], true)
                    && ! $this->isProvisionalFinding($f);
            })->count()
            : 0;

        $reviewProgress = [
            'awaiting_review'      => $awaitingReviewCount,
            'under_review'         => $underReviewCount,
            'approved_or_assigned' => $approvedOrAssignedCount,
            'work_in_progress'     => $workInProgressCount,
            'in_progress'          => $workInProgressCount,
            'completed'            => $completedCount,
            'in_observation'       => $inObservationCount,
            'proven_eligible_count'=> $provenEligibleCount,
            'total'                => $findings->count(),
        ];

        $measuredFindings = $findings->filter(fn($f) => ($f->outcome_status instanceof FindingOutcomeStatus ? $f->outcome_status->value : (string)$f->outcome_status) === FindingOutcomeStatus::OutcomeMeasured->value);
        $inObservationFindings = $findings->filter(fn($f) => ($f->outcome_status instanceof FindingOutcomeStatus ? $f->outcome_status->value : (string)$f->outcome_status) === FindingOutcomeStatus::AwaitingResults->value);
        $noOrdersFindings = $findings->filter(fn($f) => ($f->outcome_status instanceof FindingOutcomeStatus ? $f->outcome_status->value : (string)$f->outcome_status) === FindingOutcomeStatus::NoOrdersObserved->value);

        $hasMeasured = $measuredFindings->isNotEmpty();
        $measuredOrdersRevenue = (float) $measuredFindings->sum(fn($f) => $f->getObservedPostActionRevenue());

        $windowSourceFindings = $inObservationFindings->isNotEmpty()
            ? $inObservationFindings
            : ($measuredFindings->isNotEmpty() ? $measuredFindings : $findings);
        $distinctObservationWindows = $windowSourceFindings
            ->map(fn($f) => $this->explanationService->resolveObservationWindowDays($f))
            ->unique()
            ->values();
        $windowDays = $distinctObservationWindows->count() === 1
            ? (int) $distinctObservationWindows->first()
            : null;
        $isOutcomeRelevant = $hasMeasured || $inObservationFindings->isNotEmpty() || $noOrdersFindings->isNotEmpty();

        $cardBadgeLabel = null;
        $cardBadgeColor = 'gray';
        if ($hasMeasured) {
            $cardBadgeLabel = $measuredOrdersRevenue > 0
                ? ($this->formatExecutiveCurrency($measuredOrdersRevenue) . ' observed')
                : '$0 observed';
            $cardBadgeColor = $measuredOrdersRevenue > 0 ? 'emerald' : 'gray';
        } elseif ($noOrdersFindings->isNotEmpty()) {
            $cardBadgeLabel = '0 orders observed';
            $cardBadgeColor = 'gray';
        } elseif ($inObservationFindings->isNotEmpty()) {
            $cardBadgeLabel = $windowDays !== null
                ? ($inObservationFindings->count() . " in {$windowDays}d observation")
                : ($inObservationFindings->count() . ' in observation');
            $cardBadgeColor = 'amber';
        }

        $measuredOutcomeSummary = [
            'is_measured'          => $hasMeasured,
            'is_relevant'          => $isOutcomeRelevant,
            'label'                => $hasMeasured ? ('$' . number_format($measuredOrdersRevenue, 2) . ' observed orders') : 'Not yet measured',
            'badge_color'          => $hasMeasured ? ($measuredOrdersRevenue > 0 ? 'emerald' : 'gray') : 'gray',
            'card_badge_label'     => $cardBadgeLabel,
            'card_badge_color'     => $cardBadgeColor,
            'value'                => $hasMeasured ? $measuredOrdersRevenue : null,
            'measured_count'       => $measuredFindings->count(),
            'in_observation_count' => $inObservationFindings->count(),
            'no_orders_count'      => $noOrdersFindings->count(),
            'window_days'          => $windowDays ?? (int) config('intelligence.outcome_measurement.window_days', 30),
            'has_mixed_windows'    => $distinctObservationWindows->count() > 1,
            'subtext'              => $hasMeasured ? ($measuredFindings->count() . ' account(s) measured') : 'Measurement begins after outreach',
        ];

        // 8. Generate Individual Finding Summaries & Card Presentation
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

        $staleFindingsCount = $accounts->where('is_outdated', true)->count();
        $revalidationFindingsCount = $accounts->where('requires_revalidation', true)->count();

        $warningParts = [];
        if ($provisionalAccountsCount > 0) {
            $warningParts[] = "{$provisionalAccountsCount} provisional " . Str::plural('account', $provisionalAccountsCount) . ' ' . ($provisionalAccountsCount === 1 ? 'requires' : 'require') . ' manual review';
        }
        if ($revalidationFindingsCount > 0) {
            if ($isAccountBased) {
                $warningParts[] = "{$revalidationFindingsCount} " . Str::plural('account', $revalidationFindingsCount) . ' ' . ($revalidationFindingsCount === 1 ? 'requires' : 'require') . ' detector revalidation';
            } elseif ($staleFindingsCount === 0) {
                $warningParts[] = "{$revalidationFindingsCount} " . Str::plural('finding', $revalidationFindingsCount) . ' ' . ($revalidationFindingsCount === 1 ? 'requires' : 'require') . ' revalidation';
            }
        }
        if ($staleFindingsCount > 0) {
            $warningParts[] = $findings->count() === 1
                ? 'Historical telemetry (>7 days old) — verify against current logs'
                : "{$staleFindingsCount} of {$findings->count()} findings reflect historical telemetry (>7 days old)";
        }
        if ($isUnclassifiedPillar) {
            $warningParts[] = 'Pillar classification required for unknown finding type';
        }
        $cardWarningText = ! empty($warningParts) ? implode(' · ', $warningParts) : null;

        $awaitingDecisionTotal = $awaitingReviewCount + $underReviewCount;
        $statusParts = [];
        if ($awaitingDecisionTotal > 0) {
            $statusParts[] = "{$awaitingDecisionTotal} awaiting review";
        }
        if ($approvedOrAssignedCount > 0) {
            $statusParts[] = "{$approvedOrAssignedCount} approved";
        }
        if ($workInProgressCount > 0) {
            $statusParts[] = "{$workInProgressCount} in progress";
        }
        if ($completedCount > 0) {
            $statusParts[] = "{$completedCount} completed";
        }
        $compactStatusText = ! empty($statusParts) ? implode(' · ', $statusParts) : '0 awaiting review';

        $cardPresentation = $this->buildCardPresentation(
            strategicGoal: $strategicGoal,
            pillar: $pillar,
            findings: $findings,
            accounts: $accounts,
            isAccountBased: $isAccountBased,
            accountCount: $accountCount,
            provenAccountsCount: $provenAccountsCount,
            provisionalAccountsCount: $provisionalAccountsCount,
            hasRelationshipValue: $hasRelationshipValue,
            historicalRelationshipValue: $historicalRelationshipValue,
            observedSpendingDecline: $observedSpendingDecline,
            verifiedFinancialLoss: $verifiedFinancialLoss,
            historicalPurchasingEpisodeBenchmark: $historicalPurchasingEpisodeBenchmark,
            historicalReorderValue: $historicalReorderValue,
            directRevenueImpact: $directRevenueImpact
        );

        return [
            'id'                         => 'init_' . ($client?->id ?? '0') . '_' . Str::slug($strategicGoal),
            'client_id'                  => $client?->id,
            'client_name'                => $client?->name ?? 'Enterprise Portfolio',
            'strategic_goal'             => $strategicGoal,
            'display_title'              => $cardPresentation['display_title'],
            'initiative_title'           => "{$client?->name}: {$strategicGoal}",
            'pillar'                     => $pillar?->value ?? 'unclassified',
            'pillar_label'               => $pillar?->label() ?? 'Classification Required',
            'pillar_color'               => $pillar?->color() ?? 'warning',
            'pillar_icon'                => $pillar?->icon() ?? 'heroicon-o-question-mark-circle',
            'is_unclassified_pillar'     => $isUnclassifiedPillar,
            'is_account_based'           => $isAccountBased,
            'entity_term_singular'       => $entityTermSingular,
            'entity_term_plural'         => $entityTermPlural,
            'drilldown_button_label'     => $drilldownButtonLabel,
            'primary_action_label'       => $isAccountBased ? 'Review Accounts' : 'Review Findings',
            'batch_action_allowed'       => $batchActionAllowed,
            'qualifying_accounts_count'  => $accountCount,
            'proven_accounts_count'      => $provenAccountsCount,
            'action_ready_accounts_count'=> $provenAccountsCount,
            'provisional_accounts_count' => $provisionalAccountsCount,
            'provisional_spend_total'    => round($provisionalSpendTotal, 2),
            'has_provisional_accounts'   => $provisionalAccountsCount > 0,
            'provisional_notice'         => $provisionalAccountsCount > 0 ? "{$provisionalAccountsCount} provisional account(s) require manual review" : null,
            'stale_findings_count'       => $staleFindingsCount,
            'revalidation_findings_count'=> $revalidationFindingsCount,
            'card_warning_text'          => $cardWarningText,
            'compact_status_text'        => $compactStatusText,
            'card_metric_value'          => $cardPresentation['card_metric_value'],
            'card_metric_label'          => $cardPresentation['card_metric_label'],
            'card_summary'               => $cardPresentation['card_summary'],
            'card_next_step'             => $cardPresentation['card_next_step'],
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

        // Preserve strategic goals independently from pillar classification via FindingTypeRegistry
        $classification = FindingTypeRegistry::classify($finding);
        if ($classification['is_known']) {
            return $classification['strategic_goal'];
        }

        $category = (string) ($finding->finding_category?->value ?? $finding->finding_category);

        return match ($category) {
            'Conversion', 'Checkout' => 'Checkout Conversion Optimization',
            'Search', 'Merchandising' => 'Product Discovery & Catalog Uplift',
            'Behavioral' => 'Direct Traffic & Referral Audit',
            default => $classification['strategic_goal'],
        };
    }

    /**
     * Request-scoped analytical helpers for operational findings lacking pre-computed enrichment.
     */
    protected function resolveOperationalReconciliationSummary(Client $client, array $freshness): array
    {
        try {
            $end = ! empty($freshness['source_window_end'])
                ? Carbon::parse($freshness['source_window_end'])->endOfDay()
                : Carbon::yesterday()->endOfDay();
            $start = ! empty($freshness['source_window_start'])
                ? Carbon::parse($freshness['source_window_start'])->startOfDay()
                : $end->copy()->subDays(29)->startOfDay();

            return (new RevenueReconciler())->summarizeReadOnly($client, $start, $end);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function resolveOperationalConversionDiagnostics(Client $client, Finding $finding, array $freshness): array
    {
        try {
            $periodDays = (int) ($finding->metadata_json['period_days'] ?? 30);
            $end = ! empty($freshness['source_window_end'])
                ? Carbon::parse($freshness['source_window_end'])->endOfDay()
                : Carbon::yesterday()->endOfDay();
            $start = ! empty($freshness['source_window_start'])
                ? Carbon::parse($freshness['source_window_start'])->startOfDay()
                : $end->copy()->subDays(max(0, $periodDays - 1))->startOfDay();
            $prevEnd = $start->copy()->subDay()->endOfDay();
            $prevStart = $prevEnd->copy()->subDays(max(0, $periodDays - 1))->startOfDay();

            return (new MetricsDiagnosticService())->buildConversionWindowDiagnostics($client, $start, $end, $prevStart, $prevEnd);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function resolveOperationalKlaviyoDiagnostics(Client $client, Finding $finding, array $freshness): array
    {
        try {
            $periodDays = (int) ($finding->metadata_json['period_days'] ?? 30);
            $end = ! empty($freshness['source_window_end'])
                ? Carbon::parse($freshness['source_window_end'])->endOfDay()
                : Carbon::yesterday()->endOfDay();
            $start = ! empty($freshness['source_window_start'])
                ? Carbon::parse($freshness['source_window_start'])->startOfDay()
                : $end->copy()->subDays(max(0, $periodDays - 1))->startOfDay();

            return (new MetricsDiagnosticService())->buildKlaviyoAttributionDiagnostics($client, $start, $end);
        } catch (\Throwable) {
            return [];
        }
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
     * Check whether a finding matches any semantic signal key or needle.
     */
    protected function findingMatchesSignal(Finding $f, array $needles): bool
    {
        $meta = is_array($f->metadata_json) ? $f->metadata_json : (json_decode($f->metadata_json ?? '[]', true) ?: []);
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($f->finding_type ?? ''),
            (string) ($f->source_id ?? ''),
            (string) ($f->detector_key ?? ''),
            (string) ($meta['rule_key'] ?? ''),
            (string) ($meta['detector_key'] ?? ''),
            (string) ($meta['scope_target'] ?? ''),
            (string) ($meta['primary_signal']['family'] ?? ''),
        ])));

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format currency for executive cards (whole dollars for >= $1,000, cents below $1,000).
     */
    protected function formatExecutiveCurrency(float $val): string
    {
        return abs($val) >= 1000
            ? ('$' . number_format(round($val), 0))
            : ('$' . number_format($val, 2));
    }

    /**
     * Format compact currency for card prose (e.g. $155K when >= $10,000).
     */
    protected function formatCompactCurrency(float $val): string
    {
        if (abs($val) >= 10000) {
            return '$' . number_format(round($val / 1000), 0) . 'K';
        }

        return $this->formatExecutiveCurrency($val);
    }

    /**
     * Describe verified GA4 vs Commerce discrepancy direction strictly from stored telemetry.
     */
    protected function describePlatformVarianceDirection(Finding $f): string
    {
        $meta = is_array($f->metadata_json) ? $f->metadata_json : (json_decode($f->metadata_json ?? '[]', true) ?: []);
        $ev = is_array($f->evidence_json) ? $f->evidence_json : (json_decode($f->evidence_json ?? '[]', true) ?: []);

        $varPct = (float) ($ev['variance_pct'] ?? $meta['primary_signal']['evidence']['variance_pct'] ?? $meta['difference_pct'] ?? 0.0);
        $ga4Val = $meta['primary_signal']['current_value'] ?? null;
        $commerceVal = $meta['primary_signal']['previous_value'] ?? null;
        $pctText = number_format($varPct, 1) . '%';

        if ($ga4Val !== null && $commerceVal !== null) {
            if ((float) $ga4Val > (float) $commerceVal) {
                return "GA4 exceeds Commerce revenue by {$pctText}";
            }
            if ((float) $ga4Val < (float) $commerceVal) {
                return "GA4 trails Commerce revenue by {$pctText}";
            }
        }

        return "GA4 and Commerce revenue differ by {$pctText}";
    }

    /**
     * Build presentation-only executive card fields (title, domain-appropriate primary metric,
     * 15-25 word summary, and single-sentence next step) without altering underlying calculations.
     */
    protected function buildCardPresentation(
        string $strategicGoal,
        ?FindingPillar $pillar,
        Collection $findings,
        Collection $accounts,
        bool $isAccountBased,
        int $accountCount,
        int $provenAccountsCount,
        int $provisionalAccountsCount,
        bool $hasRelationshipValue,
        float $historicalRelationshipValue,
        float $observedSpendingDecline,
        float $verifiedFinancialLoss,
        float $historicalPurchasingEpisodeBenchmark,
        float $historicalReorderValue,
        float $directRevenueImpact
    ): array {
        if ($isAccountBased) {
            if ($observedSpendingDecline > 0 && $pillar === FindingPillar::BusinessRisk) {
                $metricValue = $this->formatExecutiveCurrency($observedSpendingDecline);
                $metricLabel = 'observed spending decline';
            } elseif ($verifiedFinancialLoss > 0) {
                $metricValue = $this->formatExecutiveCurrency($verifiedFinancialLoss);
                $metricLabel = 'verified financial loss';
            } elseif ($historicalPurchasingEpisodeBenchmark > 0) {
                $metricValue = $this->formatExecutiveCurrency($historicalPurchasingEpisodeBenchmark);
                $metricLabel = 'historical reorder benchmark';
            } elseif ($historicalReorderValue > 0) {
                $metricValue = $this->formatExecutiveCurrency($historicalReorderValue);
                $metricLabel = 'historical reorder benchmark';
            } elseif ($directRevenueImpact > 0 && $provenAccountsCount > 0) {
                $metricValue = $this->formatExecutiveCurrency($directRevenueImpact);
                $metricLabel = 'modeled opportunity';
            } else {
                $metricValue = "{$accountCount} " . Str::plural('account', $accountCount);
                $metricLabel = 'provisional cadence review';
            }

            $relClause = $hasRelationshipValue
                ? (' across ' . $this->formatCompactCurrency($historicalRelationshipValue) . ' in historical spend')
                : '';

            if ($strategicGoal === 'Enterprise Winback Campaign') {
                if ($provenAccountsCount > 0 && $provisionalAccountsCount > 0) {
                    $summary = "{$provenAccountsCount} proven recurring " . Str::plural('account', $provenAccountsCount) . " and {$provisionalAccountsCount} provisional " . Str::plural('account', $provisionalAccountsCount) . " exceed calibrated dormancy thresholds{$relClause}.";
                } elseif ($provenAccountsCount > 0) {
                    $summary = "{$provenAccountsCount} proven recurring enterprise " . Str::plural('account', $provenAccountsCount) . " have exceeded calibrated dormancy thresholds without reordering{$relClause}.";
                } else {
                    $summary = "{$provisionalAccountsCount} provisional enterprise " . Str::plural('account', $provisionalAccountsCount) . " show extended inactivity{$relClause} and require manual cadence verification.";
                }
                $nextStep = 'Confirm active buyer contacts and initiate sales outreach to eligible accounts.';
            } elseif ($strategicGoal === 'B2B Wholesale Retention') {
                if ($provenAccountsCount > 0 && $provisionalAccountsCount > 0) {
                    $summary = "{$provenAccountsCount} proven wholesale " . Str::plural('account', $provenAccountsCount) . " and {$provisionalAccountsCount} provisional " . Str::plural('account', $provisionalAccountsCount) . " are overdue against historical reorder cadence{$relClause}.";
                } else {
                    $summary = "{$accountCount} wholesale " . Str::plural('account', $accountCount) . " have exceeded their established repeat purchasing cadence without reordering{$relClause}.";
                }
                $nextStep = 'Verify buyer contact records and send tailored reorder quotes to eligible accounts.';
            } elseif ($strategicGoal === 'Key Account Churn Prevention') {
                $summary = "{$accountCount} commercial " . Str::plural('account', $accountCount) . " recorded significant trailing spending declines compared to prior baseline run rates{$relClause}.";
                $nextStep = 'Schedule account health reviews with primary buyers to address spending contraction.';
            } elseif ($strategicGoal === 'Consumable Reorder Capture') {
                $summary = "{$accountCount} recurring consumable " . Str::plural('account', $accountCount) . " have passed their regular replenishment cycle intervals without reordering{$relClause}.";
                $nextStep = 'Confirm buyer contacts and send timely replenishment reminders based on past baskets.';
            } else {
                $summary = "{$accountCount} commercial " . Str::plural('account', $accountCount) . " require account review under {$strategicGoal}{$relClause}.";
                $nextStep = 'Confirm active buyer contacts and review individual account histories before outreach.';
            }

            return [
                'display_title'     => $strategicGoal,
                'card_metric_value' => $metricValue,
                'card_metric_label' => $metricLabel,
                'card_summary'      => $summary,
                'card_next_step'    => $nextStep,
            ];
        }

        // Non-account operational, funnel, analytics, and technical initiatives
        $cvrFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['conversion_rate']));
        $campaignTaggingFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['campaign_tagging']));
        $klaviyoAttrFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['klaviyo']) && $this->findingMatchesSignal($f, ['attribution_variance']));
        $platformVarFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['platform_variance', 'ga4_vs_commerce']));
        $scriptErrorFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['script_error']));
        $revenueDropFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['revenue_drop']));
        $trafficSurgeFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['volume_surge', 'traffic']));

        $attrVariance = (float) $findings->map(function (Finding $f) {
            $meta = is_array($f->metadata_json) ? $f->metadata_json : (json_decode($f->metadata_json ?? '[]', true) ?: []);
            $ev = is_array($f->evidence_json) ? $f->evidence_json : (json_decode($f->evidence_json ?? '[]', true) ?: []);
            return $meta['difference_amount'] ?? ($ev['variance_amount'] ?? ($ev['difference_amount'] ?? 0.0));
        })->sum();

        // 1. Checkout & Funnel Conversion
        if ($strategicGoal === 'Checkout Conversion Optimization') {
            if ($cvrFinding) {
                $cMeta = is_array($cvrFinding->metadata_json) ? $cvrFinding->metadata_json : (json_decode($cvrFinding->metadata_json ?? '[]', true) ?: []);
                $diffPct = abs((float) ($cMeta['difference_pct'] ?? 0.0));
                $currCvr = (float) ($cMeta['metric_a']['value'] ?? 0.0);
                $period = (int) ($cMeta['period_days'] ?? 30);
                $pctFormatted = (abs($diffPct - round($diffPct)) < 0.05 ? number_format(round($diffPct), 0) : number_format($diffPct, 1)) . '%';

                $cvrAccount = $accounts->firstWhere('finding_id', $cvrFinding->id);
                $convDiag = $cvrAccount['opportunity_explanation']['expandable_details']['conversion_diagnostics']
                    ?? $cMeta['conversion_diagnostics']
                    ?? ($cvrFinding->evidence_json['conversion_diagnostics'] ?? []);
                $diagType = $convDiag['diagnosis_type'] ?? null;

                if ($campaignTaggingFinding) {
                    $tEv = is_array($campaignTaggingFinding->evidence_json) ? $campaignTaggingFinding->evidence_json : (json_decode($campaignTaggingFinding->evidence_json ?? '[]', true) ?: []);
                    $tMeta = is_array($campaignTaggingFinding->metadata_json) ? $campaignTaggingFinding->metadata_json : (json_decode($campaignTaggingFinding->metadata_json ?? '[]', true) ?: []);
                    $klaviyoRev = (float) ($tEv['klaviyo_revenue'] ?? $tMeta['primary_signal']['evidence']['klaviyo_revenue'] ?? 0.0);
                    $summary = "Storewide {$period}-day conversion fell to " . number_format($currCvr, 2) . "%, while " . $this->formatExecutiveCurrency($klaviyoRev) . " in Klaviyo email revenue went unassigned in GA4.";
                    $nextStep = 'Segment conversion by channel against Direct traffic spikes and reconcile Klaviyo attribution.';
                } elseif ($diagType === 'traffic_denominator_dilution') {
                    $nonDirPrev = number_format((float) ($convDiag['previous']['ga4_non_direct_cvr'] ?? 0), 2);
                    $nonDirCurr = number_format((float) ($convDiag['current']['ga4_non_direct_cvr'] ?? 0), 2);
                    $summary = "Overall {$period}-day GA4 CVR fell {$pctFormatted} to " . number_format($currCvr, 2) . "% due to Direct-session dilution (excluding Direct: {$nonDirPrev}% → {$nonDirCurr}%).";
                    $nextStep = 'Audit Direct traffic quality with Analytics rather than modifying checkout flows.';
                } elseif ($diagType === 'mixed_dilution_and_commercial_softness') {
                    $nonDirCurr = number_format((float) ($convDiag['current']['ga4_non_direct_cvr'] ?? 0), 2);
                    $mCurrOrd = (int) ($convDiag['current']['magento_valid_orders'] ?? 0);
                    $summary = "Overall {$period}-day GA4 CVR dropped {$pctFormatted} amid Direct traffic surge alongside {$nonDirCurr}% non-Direct CVR and {$mCurrOrd} valid orders.";
                    $nextStep = 'Review Direct session attribution, non-Direct channels, and pending/excluded Magento order statuses.';
                } else {
                    $summary = "Storewide {$period}-day GA4 conversion rate declined by {$pctFormatted} to " . number_format($currCvr, 2) . "% compared to the prior period.";
                    $nextStep = 'Segment conversion by channel and verify Magento valid order volume before altering checkout.';
                }

                return [
                    'display_title'     => 'Checkout Conversion Optimization',
                    'card_metric_value' => $pctFormatted,
                    'card_metric_label' => 'conversion rate decline',
                    'card_summary'      => $summary,
                    'card_next_step'    => $nextStep,
                ];
            }

            if ($campaignTaggingFinding) {
                $tEv = is_array($campaignTaggingFinding->evidence_json) ? $campaignTaggingFinding->evidence_json : (json_decode($campaignTaggingFinding->evidence_json ?? '[]', true) ?: []);
                $tMeta = is_array($campaignTaggingFinding->metadata_json) ? $campaignTaggingFinding->metadata_json : (json_decode($campaignTaggingFinding->metadata_json ?? '[]', true) ?: []);
                $klaviyoRev = (float) ($tEv['klaviyo_revenue'] ?? $tMeta['primary_signal']['evidence']['klaviyo_revenue'] ?? 0.0);

                return [
                    'display_title'     => 'Email Campaign Attribution',
                    'card_metric_value' => $this->formatExecutiveCurrency($klaviyoRev),
                    'card_metric_label' => 'Klaviyo attributed revenue',
                    'card_summary'      => "Klaviyo attributed " . $this->formatExecutiveCurrency($klaviyoRev) . " in email revenue while GA4 Email reported $0 (model variance, not lost sales).",
                    'card_next_step'    => 'Audit Klaviyo attribution windows and outbound campaign/flow UTM tagging in Marketing Operations.',
                ];
            }
        }

        // 2. Direct Traffic & Referral Audit
        if ($strategicGoal === 'Digital Experience Engagement' || $strategicGoal === 'Direct Traffic & Referral Audit' || $trafficSurgeFinding) {
            $tEv = $trafficSurgeFinding ? (is_array($trafficSurgeFinding->evidence_json) ? $trafficSurgeFinding->evidence_json : (json_decode($trafficSurgeFinding->evidence_json ?? '[]', true) ?: [])) : [];
            $tMeta = $trafficSurgeFinding ? (is_array($trafficSurgeFinding->metadata_json) ? $trafficSurgeFinding->metadata_json : (json_decode($trafficSurgeFinding->metadata_json ?? '[]', true) ?: [])) : [];
            $surgePct = (float) ($tEv['surge_pct'] ?? $tMeta['primary_signal']['evidence']['surge_pct'] ?? 0.0);
            $directShare = (float) ($tEv['direct_share_pct'] ?? $tMeta['primary_signal']['evidence']['direct_share_pct'] ?? 0.0);
            $currSessions = (int) ($tEv['direct_sessions'] ?? $tMeta['primary_signal']['current_value'] ?? 0);

            return [
                'display_title'     => 'Direct Traffic & Referral Audit',
                'card_metric_value' => '+' . number_format($surgePct, 1) . '%',
                'card_metric_label' => 'Direct traffic surge',
                'card_summary'      => "Direct traffic rose +" . number_format($surgePct, 1) . "% to " . number_format($currSessions) . " sessions (" . number_format($directShare, 1) . "% of site traffic) without a proportional order lift.",
                'card_next_step'    => 'Inspect landing page referrers, user-agent distributions, and GA4 channel rules to isolate traffic origin.',
            ];
        }

        // 3. Storewide Observed Revenue Decline
        if ($revenueDropFinding || $strategicGoal === 'Storewide Revenue Performance') {
            $revFinding = $revenueDropFinding ?? $findings->first();
            $rMeta = $revFinding ? (is_array($revFinding->metadata_json) ? $revFinding->metadata_json : (json_decode($revFinding->metadata_json ?? '[]', true) ?: [])) : [];
            $dropAmt = (float) ($rMeta['difference_amount'] ?? $revFinding?->estimated_revenue_impact ?? $directRevenueImpact);
            $dropPct = abs((float) ($rMeta['difference_pct'] ?? 0.0));
            $dropPeriod = (int) ($rMeta['period_days'] ?? 30);

            return [
                'display_title'     => 'Storewide Revenue Performance',
                'card_metric_value' => $this->formatExecutiveCurrency($dropAmt),
                'card_metric_label' => 'observed revenue decline',
                'card_summary'      => "Adobe Commerce revenue fell " . number_format($dropPct, 1) . "% (" . $this->formatExecutiveCurrency($dropAmt) . ") over the trailing {$dropPeriod} days compared to the prior period.",
                'card_next_step'    => 'Review storewide order volume trends, order status exclusions, and ERP order sync.',
            ];
        }

        // 4. Attribution Discrepancies & Storefront Script Health
        $pEv = $platformVarFinding ? (is_array($platformVarFinding->evidence_json) ? $platformVarFinding->evidence_json : (json_decode($platformVarFinding->evidence_json ?? '[]', true) ?: [])) : [];
        $pMeta = $platformVarFinding ? (is_array($platformVarFinding->metadata_json) ? $platformVarFinding->metadata_json : (json_decode($platformVarFinding->metadata_json ?? '[]', true) ?: [])) : [];
        $varPct = (float) ($pEv['variance_pct'] ?? $pMeta['primary_signal']['evidence']['variance_pct'] ?? $pMeta['difference_pct'] ?? 0.0);

        if (($attrVariance > 0 || $platformVarFinding) && $scriptErrorFinding) {
            $sEv = is_array($scriptErrorFinding->evidence_json) ? $scriptErrorFinding->evidence_json : (json_decode($scriptErrorFinding->evidence_json ?? '[]', true) ?: []);
            $sMeta = is_array($scriptErrorFinding->metadata_json) ? $scriptErrorFinding->metadata_json : (json_decode($scriptErrorFinding->metadata_json ?? '[]', true) ?: []);
            $errCount = (int) ($sEv['error_count'] ?? $sMeta['primary_signal']['evidence']['error_count'] ?? 0);
            $metricVal = $attrVariance > 0 ? $this->formatExecutiveCurrency($attrVariance) : (number_format($varPct, 1) . '%');

            if ($klaviyoAttrFinding && $platformVarFinding) {
                $ga4Clause = $this->describePlatformVarianceDirection($platformVarFinding);
                $summary = "Klaviyo and GA4 differ by " . $this->formatExecutiveCurrency($attrVariance) . ", {$ga4Clause}, alongside " . number_format($errCount) . " storewide Clarity script errors.";
            } else {
                $summary = "Cross-platform attribution differs by {$metricVal} alongside " . number_format($errCount) . " front-end JavaScript exceptions requiring technical review.";
            }

            return [
                'display_title'     => 'Revenue Attribution & Cart Health',
                'card_metric_value' => $metricVal,
                'card_metric_label' => 'attribution discrepancy',
                'card_summary'      => $summary,
                'card_next_step'    => 'Review front-end browser script exceptions in Engineering and reconcile cross-platform attribution.',
            ];
        }

        if ($attrVariance > 0 || $platformVarFinding || $strategicGoal === 'Revenue & Channel Attribution') {
            $metricVal = $attrVariance > 0 ? $this->formatExecutiveCurrency($attrVariance) : (number_format($varPct, 1) . '%');
            if ($platformVarFinding && $klaviyoAttrFinding) {
                $ga4Clause = $this->describePlatformVarianceDirection($platformVarFinding);
                $summary = "Klaviyo vs GA4 differs by " . $this->formatExecutiveCurrency($attrVariance) . ", and {$ga4Clause} (reporting alignment variances, not lost sales).";
            } elseif ($platformVarFinding) {
                $summary = $this->describePlatformVarianceDirection($platformVarFinding) . ' over the monitored window, reflecting cross-platform tracking and order-status alignment differences.';
            } else {
                $summary = "Klaviyo attributed " . $this->formatExecutiveCurrency($attrVariance) . " in email revenue vs $0 in GA4 Email across differing attribution models.";
            }

            return [
                'display_title'     => 'Revenue & Channel Attribution',
                'card_metric_value' => $metricVal,
                'card_metric_label' => 'attribution discrepancy',
                'card_summary'      => $summary,
                'card_next_step'    => 'Reconcile GA4 purchase status filtering, Klaviyo attribution windows, and UTM parameters.',
            ];
        }

        if ($scriptErrorFinding || $strategicGoal === 'Storefront & Script Health') {
            $sFinding = $scriptErrorFinding ?? $findings->first();
            $sEv = $sFinding ? (is_array($sFinding->evidence_json) ? $sFinding->evidence_json : (json_decode($sFinding->evidence_json ?? '[]', true) ?: [])) : [];
            $sMeta = $sFinding ? (is_array($sFinding->metadata_json) ? $sFinding->metadata_json : (json_decode($sFinding->metadata_json ?? '[]', true) ?: [])) : [];
            $errCount = (int) ($sEv['error_count'] ?? $sMeta['primary_signal']['evidence']['error_count'] ?? 0);
            $sessions = (int) ($sEv['monitored_sessions'] ?? $sEv['clarity_sessions'] ?? $sMeta['primary_signal']['evidence']['clarity_sessions'] ?? 0);

            return [
                'display_title'     => 'Storefront & Script Health',
                'card_metric_value' => number_format($errCount),
                'card_metric_label' => 'Clarity script errors',
                'card_summary'      => number_format($errCount) . ' storewide JavaScript exceptions logged in Clarity' . ($sessions > 0 ? (' across ' . number_format($sessions) . ' monitored sessions') : '') . '.',
                'card_next_step'    => 'Inspect front-end browser console logs and Clarity telemetry in Engineering to isolate the failing script.',
            ];
        }

        // 5. Fallback Operational / Technical Health
        $count = $findings->count();
        return [
            'display_title'     => $strategicGoal,
            'card_metric_value' => $count . ' ' . Str::plural('issue', $count),
            'card_metric_label' => $pillar === FindingPillar::TechnicalHealth ? 'technical health review' : 'operational review',
            'card_summary'      => "{$count} technical or operational " . Str::plural('finding', $count) . ' detected across store integrations requiring team verification.',
            'card_next_step'    => 'Review diagnostic evidence and validate integration telemetry with the responsible team.',
        ];
    }

    /**
     * Synthesize "What business opportunity exists?" (Question 1)
     */
    protected function synthesizeBusinessOpportunity(string $goal, ?FindingPillar $pillar, int $accountCount, ?string $clientName, ?Collection $findings = null, bool $isAccountBased = true): string
    {
        if (! $isAccountBased) {
            $count = $findings ? $findings->count() : $accountCount;
            if ($findings) {
                $revenueDropFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['revenue_drop']));
                if ($revenueDropFinding && $findings->count() === 1) {
                    return "{$count} storewide revenue decline finding detected requiring commercial review.";
                }
                $cvrFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['conversion_rate']));
                $campaignTaggingFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['campaign_tagging']));
                if ($goal === 'Checkout Conversion Optimization' && ! $cvrFinding && $campaignTaggingFinding) {
                    return "{$count} email attribution discrepancy finding identified across Klaviyo and GA4.";
                }
                $platformVarFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['platform_variance', 'ga4_vs_commerce']));
                $scriptErrorFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['script_error']));
                $klaviyoAttrFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['klaviyo']) && $this->findingMatchesSignal($f, ['attribution_variance']));
                if ($goal === 'Commercial Account Growth' && $platformVarFinding && ! $scriptErrorFinding && ! $klaviyoAttrFinding) {
                    return "{$count} cross-platform revenue attribution discrepancy detected between GA4 and Commerce.";
                }
            }

            return match ($goal) {
                'Commercial Account Growth' => "{$count} operational and attribution findings identified across storewide data and tracking pipelines.",
                'Revenue & Channel Attribution' => "{$count} cross-platform attribution discrepancies identified across Commerce, GA4, and Klaviyo.",
                'Digital Experience Engagement', 'Direct Traffic & Referral Audit' => "{$count} analytics telemetry finding identified involving unassigned direct traffic surges.",
                'Checkout Conversion Optimization' => "{$count} conversion findings requiring traffic segmentation and funnel verification.",
                'Storefront & Script Health', 'Data & Technical Health Governance' => "{$count} technical or data pipeline anomalies have been detected requiring engineering validation.",
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
            if ($findings) {
                $revenueDropFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['revenue_drop']));
                if ($revenueDropFinding && $findings->count() === 1) {
                    return "Evaluates to {$formattedValue} in observed period-over-period revenue decline against the prior period baseline.";
                }
                $cvrFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['conversion_rate']));
                $campaignTaggingFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['campaign_tagging']));
                if ($goal === 'Checkout Conversion Optimization' && $cvrFinding && ! $campaignTaggingFinding) {
                    return "Evaluates to {$formattedValue} in modeled conversion impact from the observed GA4 conversion rate drop.";
                }
                if ($goal === 'Checkout Conversion Optimization' && ! $cvrFinding && $campaignTaggingFinding) {
                    return "Evaluates to {$formattedValue} in Klaviyo attributed revenue uncredited in GA4 Email (model variance, not lost sales).";
                }
                $platformVarFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['platform_variance', 'ga4_vs_commerce']));
                $scriptErrorFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['script_error']));
                $klaviyoAttrFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['klaviyo']) && $this->findingMatchesSignal($f, ['attribution_variance']));
                if ($goal === 'Commercial Account Growth' && $platformVarFinding && ! $scriptErrorFinding && ! $klaviyoAttrFinding) {
                    return $this->describePlatformVarianceDirection($platformVarFinding) . ' over the monitored window, reflecting cross-platform tracking alignment differences rather than lost orders.';
                }
            }

            return match ($goal) {
                'Commercial Account Growth' => "Evaluates to {$formattedValue} across cross-platform attribution variances and storefront exceptions. Error counts represent technical friction and should not be treated as lost sales without order log corroboration.",
                'Revenue & Channel Attribution' => "Evaluates to {$formattedValue} in cross-platform attribution discrepancies between email/analytics/commerce systems (reporting differences, not lost sales).",
                'Digital Experience Engagement', 'Direct Traffic & Referral Audit' => "Evaluates to {$formattedValue} in direct session volume requiring referrer and log inspection to determine campaign tracking or crawler artifacts.",
                'Checkout Conversion Optimization' => "Conversion shifts require channel segmentation against Direct traffic volume and Magento order logs before attributing loss.",
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
            if ($findings) {
                $revenueDropFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['revenue_drop']));
                if ($revenueDropFinding && $findings->count() === 1) {
                    return 'E-commerce Operations to review order volume trends, catalog availability, and ERP synchronization.';
                }
                $cvrFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['conversion_rate']));
                $campaignTaggingFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['campaign_tagging']));
                if ($goal === 'Checkout Conversion Optimization' && $cvrFinding && ! $campaignTaggingFinding) {
                    return 'E-commerce team to audit checkout step drop-offs and analyze conversion across devices and traffic channels.';
                }
                if ($goal === 'Checkout Conversion Optimization' && ! $cvrFinding && $campaignTaggingFinding) {
                    return 'Marketing Operations to audit Klaviyo attribution windows and outbound campaign/flow UTM tagging.';
                }
                $platformVarFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['platform_variance', 'ga4_vs_commerce']));
                $scriptErrorFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['script_error']));
                $klaviyoAttrFinding = $findings->first(fn(Finding $f) => $this->findingMatchesSignal($f, ['klaviyo']) && $this->findingMatchesSignal($f, ['attribution_variance']));
                if ($goal === 'Commercial Account Growth' && $platformVarFinding && ! $scriptErrorFinding && ! $klaviyoAttrFinding) {
                    return 'Marketing Operations to reconcile GA4 ecommerce tracking, attribution windows, and cross-channel UTM parameters.';
                }
            }

            return match ($goal) {
                'Commercial Account Growth' => "Engineering and Marketing Operations to inspect front-end console error telemetry and reconcile GA4 vs Klaviyo attribution models.",
                'Revenue & Channel Attribution' => "Marketing Operations and Analytics to reconcile GA4 purchase status exclusions, Klaviyo attribution windows, and UTM tagging.",
                'Digital Experience Engagement', 'Direct Traffic & Referral Audit' => "Analytics team to audit direct traffic telemetry, verify IP exclusion filters, and inspect landing page referrers.",
                'Checkout Conversion Optimization' => "Analytics and E-commerce Operations to segment conversion excluding Direct traffic and review valid vs excluded Magento orders.",
                'Storefront & Script Health' => "Engineering to inspect browser console logs and Clarity telemetry for storewide JavaScript exceptions.",
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
