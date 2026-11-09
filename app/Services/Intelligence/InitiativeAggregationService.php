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

        if (($evidence['cadence_evidence_level'] ?? null) === 'limited_two_orders' || ($evidence['evidence_level'] ?? null) === 'provisional_p90') {
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

        // Partition into proven repeat accounts vs provisional accounts requiring manual review
        $provenFindings = $findings->filter(fn (Finding $f) => ! $this->isProvisionalFinding($f));
        $provisionalFindings = $findings->filter(fn (Finding $f) => $this->isProvisionalFinding($f));

        $provenAccountsCount = $provenFindings->pluck('account_identity_hash')->filter()->unique()->count() ?: $provenFindings->count();
        $provisionalAccountsCount = $provisionalFindings->pluck('account_identity_hash')->filter()->unique()->count() ?: $provisionalFindings->count();

        // 1. Segregate Financials (Prioritize verified historical purchasing evidence)
        $historicalReorderValue = (float) $provenFindings->where('impact_nature', \App\Enums\ImpactNature::HistoricalReorderValue)->sum('estimated_revenue_impact');
        $observedSpendingDecline = (float) $provenFindings->where('impact_nature', \App\Enums\ImpactNature::ObservedSpendingDecline)->sum('estimated_revenue_impact');
        $verifiedFinancialLoss = (float) $provenFindings->where('impact_nature', \App\Enums\ImpactNature::VerifiedFinancialLoss)->sum('estimated_revenue_impact');
        $directionalOpportunity = (float) $findings->where('impact_nature', \App\Enums\ImpactNature::DirectionalOpportunity)->sum('estimated_revenue_impact');
        $provisionalSpendTotal = (float) $provisionalFindings->sum('estimated_revenue_impact');

        // Determine primary financial classification: verified historical evidence ALWAYS takes precedence
        if ($observedSpendingDecline > 0 && $pillar === FindingPillar::BusinessRisk) {
            $primaryFinancialLabel = 'Observed Spending Decline';
            $primaryFinancialValue = $observedSpendingDecline;
            $primaryFinancialNature = 'observed_spending_decline';
            $primaryFinancialColor = 'amber';
        } elseif ($verifiedFinancialLoss > 0) {
            $primaryFinancialLabel = 'Verified Financial Loss';
            $primaryFinancialValue = $verifiedFinancialLoss;
            $primaryFinancialNature = 'verified_financial_loss';
            $primaryFinancialColor = 'rose';
        } elseif ($historicalReorderValue > 0) {
            $primaryFinancialLabel = 'Historical Reorder Value';
            $primaryFinancialValue = $historicalReorderValue;
            $primaryFinancialNature = 'historical_reorder_value';
            $primaryFinancialColor = 'emerald';
        } else {
            $primaryFinancialLabel = 'Directional Pipeline (Unverified)';
            $primaryFinancialValue = 0.0;
            $primaryFinancialNature = 'directional_opportunity';
            $primaryFinancialColor = 'gray';
        }

        // 2. Aggregate Qualifying Accounts / Entities
        $uniqueAccounts = $findings->pluck('account_identity_hash')->filter()->unique();
        $accountCount = $uniqueAccounts->isNotEmpty() ? $uniqueAccounts->count() : $findings->count();

        $clientId = $client?->id ?? $first?->client_id;
        $factsByHash = collect();
        if ($clientId && $uniqueAccounts->isNotEmpty()) {
            $factsByHash = CommerceCustomerPurchaseFact::where('client_id', $clientId)
                ->whereIn('customer_identity_hash', $uniqueAccounts)
                ->get()
                ->keyBy('customer_identity_hash');
        }

        // 3. Build Account Drill-Down Records
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

            $accountLabel = ($rawLabel && !str_starts_with($rawLabel, 'Account #'))
                ? $rawLabel
                : $customerDisplay['display_label'];

            return [
                'finding_id'                => $f->id,
                'account_label'             => $accountLabel,
                'customer_display'          => $customerDisplay,
                'customer_email'            => $customerDisplay['customer_email'],
                'customer_name'             => $customerDisplay['customer_name'],
                'company_name'              => $customerDisplay['company_name'],
                'primary_name'              => $customerDisplay['primary_name'],
                'customer_id'               => $customerDisplay['customer_id'],
                'has_email'                 => $customerDisplay['has_email'],
                'secondary_badge'           => $customerDisplay['secondary_badge'],
                'short_hash'                => $customerDisplay['short_hash'],
                'account_identity_hash'     => $accHash,
                'account_hash'              => $accHash,
                'is_provisional'            => $isProvisional,
                'evidence_level'            => $isProvisional ? 'Provisional (Manual Review Required)' : 'Verified Repeat Cadence',
                'lifetime_net_revenue'      => (float) ($evidence['lifetime_net_revenue'] ?? $evidence['lifetime_spend'] ?? $evidence['account_lifetime_spend'] ?? 0.0),
                'lifetime_spend'            => (float) ($evidence['lifetime_net_revenue'] ?? $evidence['lifetime_spend'] ?? $evidence['account_lifetime_spend'] ?? 0.0),
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

        // 4. Execution Lifecycle & Ownership
        $executionStatus = $this->aggregateExecutionStatus($findings);
        $targetRole = $this->resolveConsolidatedRole($findings);

        // 5. Synthesis of Business Rationale & Playbook
        $businessOpportunity = $this->synthesizeBusinessOpportunity($strategicGoal, $pillar, $accountCount, $client?->name);
        $businessRationale = $this->synthesizeBusinessRationale($strategicGoal, $accountCount, $primaryFinancialLabel, $primaryFinancialValue);
        $recommendedAction = $this->synthesizeRecommendedAction($strategicGoal, $targetRole, $accountCount);

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
            'qualifying_accounts_count'  => $accountCount,
            'proven_accounts_count'      => $provenAccountsCount,
            'provisional_accounts_count' => $provisionalAccountsCount,
            'provisional_spend_total'    => round($provisionalSpendTotal, 2),
            'has_provisional_accounts'   => $provisionalAccountsCount > 0,
            'provisional_notice'         => $provisionalAccountsCount > 0 ? "{$provisionalAccountsCount} provisional account(s) require manual review" : null,
            'findings_count'             => $findings->count(),
            'financial_metrics'          => [
                'historical_reorder_value'  => round($historicalReorderValue, 2),
                'observed_spending_decline' => round($observedSpendingDecline, 2),
                'directional_opportunity'   => round($directionalOpportunity, 2),
                'provisional_spend_total'   => round($provisionalSpendTotal, 2),
            ],
            'primary_financial_label'    => $primaryFinancialLabel,
            'primary_financial_value'    => round($primaryFinancialValue, 2),
            'formatted_primary_financial' => '$' . number_format($primaryFinancialValue, 2),
            'primary_financial_nature'   => $primaryFinancialNature,
            'primary_financial_color'    => $primaryFinancialColor,
            'business_opportunity'       => $businessOpportunity,
            'business_rationale'         => $businessRationale,
            'recommended_action'         => $recommendedAction,
            'target_role'                => $targetRole,
            'target_role_label'          => Str::headline($targetRole),
            'execution_status'           => $executionStatus,
            'average_confidence'         => round($findings->avg('confidence_score') ?? 0.0, 1),
            'max_priority_score'         => round($findings->max('priority_score') ?? 0.0, 1),
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
    protected function resolveConsolidatedRole(Collection $findings): string
    {
        $role = $findings->pluck('target_role')->filter()->first();
        return $role ?? 'account_manager';
    }

    /**
     * Synthesize "What business opportunity exists?" (Question 1)
     */
    protected function synthesizeBusinessOpportunity(string $goal, FindingPillar $pillar, int $accountCount, ?string $clientName): string
    {
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
    protected function synthesizeBusinessRationale(string $goal, int $accountCount, string $financialLabel, float $financialValue): string
    {
        $formattedValue = '$' . number_format($financialValue, 2);

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
    protected function synthesizeRecommendedAction(string $goal, string $targetRole, int $accountCount): string
    {
        $roleLabel = Str::headline($targetRole);

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
