<?php

namespace App\Services\Intelligence\Detectors;

use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Models\Client;
use App\Models\CommerceCustomerPurchaseFact;
use App\Services\Intelligence\Contracts\OpportunityDetectorInterface;
use App\Services\Intelligence\CustomerPurchaseAnalysisService;
use App\Services\Intelligence\DTOs\OpportunityFindingData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AccountContractionDetector implements OpportunityDetectorInterface
{
    public const KEY = 'risk.account_contraction';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Customer Revenue Contraction';
    }

    public function pillar(): FindingPillar
    {
        return FindingPillar::BusinessRisk;
    }

    public function category(): FindingCategory
    {
        return FindingCategory::Customer;
    }

    public function requiredDataCapabilities(): array
    {
        return ['commerce_customer_facts', 'commerce_orders'];
    }

    public function isSupportedForClient(Client $client): bool
    {
        $factCount = CommerceCustomerPurchaseFact::where('client_id', $client->id)->count();
        return $factCount >= 20;
    }

    public function detect(Client $client, array $config, CustomerPurchaseAnalysisService $analysisService): Collection
    {
        $enabled = $config['enabled'] ?? true;
        if (! $enabled || ! $this->isSupportedForClient($client)) {
            return collect();
        }

        $thresholds = $config['thresholds'] ?? [];
        $contractionThresholdPct = (float) ($thresholds['contraction_threshold_pct'] ?? 25.0);
        $spendTierPercentile = (int) ($thresholds['spend_percentile_tier'] ?? 70);
        $minOrderCount = (int) ($config['min_order_count'] ?? 3);
        $targetRole = $config['default_owner_role'] ?? 'account_manager';

        $baselines = $analysisService->getDerivedClientBaselines($client);
        if (! $baselines['has_data']) {
            return collect();
        }

        // Enforce detector-specific historical baseline depth (default 180 days)
        $minClientHistoryDays = (int) ($thresholds['min_client_history_days'] ?? 180);
        $clientHistoryDepth = (int) ($baselines['history_depth_days'] ?? 0);
        if ($clientHistoryDepth < $minClientHistoryDays) {
            return collect();
        }

        $spendThreshold = (float) ($baselines['spend_percentiles']["p{$spendTierPercentile}"] ?? $baselines['spend_percentiles']['p70']);

        // Candidates: customers with lifetime spend in top 30% and >= minOrderCount orders
        $candidateFacts = CommerceCustomerPurchaseFact::where('client_id', $client->id)
            ->where('lifetime_net_revenue', '>=', $spendThreshold)
            ->where('lifetime_valid_order_count', '>=', $minOrderCount)
            ->orderByDesc('lifetime_net_revenue')
            ->get();

        $referenceDate = $client->timezone ? Carbon::now($client->timezone) : Carbon::now();
        $findings = collect();

        foreach ($candidateFacts as $fact) {
            $profile = $analysisService->getCustomerAccountProfile($client, $fact->customer_identity_hash, $referenceDate);
            if (! $profile) {
                continue;
            }

            $contraction = $profile['contraction'];
            if (! $contraction['is_contracting'] || $contraction['contraction_pct'] < $contractionThresholdPct) {
                continue;
            }

            // Require meaningful baseline revenue (at least $250 or 50% of client AOV)
            if ($contraction['baseline_spend'] < max(250.0, $baselines['client_aov'] * 0.5)) {
                continue;
            }

            // Seasonality check: if historically off-season, do not treat as genuine business contraction
            $seasonality = $profile['seasonality'];
            if ($seasonality['is_historically_off_season']) {
                continue;
            }

            $identityConf = $profile['identity_confidence'];
            $confidenceScore = match ($identityConf) {
                'verified_account' => 0.90,
                'stable_hash'      => 0.82,
                default            => 0.45,
            };

            // Moderate confidence for accounts with irregular or project-based purchasing
            if (($profile['cadence']['regularity'] ?? '') === 'irregular_or_project_based') {
                $confidenceScore = min($confidenceScore, 0.65);
            }

            $revenueDrop = $contraction['revenue_drop'];
            $contractionPct = $contraction['contraction_pct'];
            $lifetimeSpend = $profile['lifetime_spend'];

            $accountShortHash = substr($fact->customer_identity_hash, 0, 8);
            $accountLabel = $profile['is_registered'] ? "Registered Account #{$accountShortHash}" : "Wholesale Account #{$accountShortHash}";

            $severity = ($contractionPct >= 60.0 || $revenueDrop >= 2000.0)
                ? FindingSeverity::High
                : FindingSeverity::Medium;

            $priority = min(100.0, round(
                45.0
                + ($revenueDrop >= 1500.0 ? 25.0 : 10.0)
                + ($contractionPct >= 50.0 ? 15.0 : 5.0)
                + ($confidenceScore * 15.0),
                1
            ));

            $title = "Account Spend Contraction: {$accountLabel} (-{$contractionPct}% / -\${$revenueDrop} run-rate)";
            $description = "Account {$accountLabel} exhibits an observed quarterly spending decline of {$contractionPct}% over the trailing {$contraction['eval_window_days']}-day evaluation period. Quarterly spend run-rate fell from \${$contraction['baseline_spend']} ({$contraction['baseline_orders']} orders) to \${$contraction['eval_spend']} ({$contraction['eval_orders']} orders), representing an observed spending reduction of -\${$revenueDrop}.";

            $action = "Investigate reduced purchasing volume. Contact buyer to identify lost product categories, competitor substitution, or service satisfaction issues.";

            $findings->push(new OpportunityFindingData(
                detectorKey: self::KEY,
                title: $title,
                description: $description,
                pillar: $this->pillar(),
                category: $this->category(),
                severity: $severity,
                confidenceScore: $confidenceScore,
                priorityScore: $priority,
                targetRole: $targetRole,
                accountIdentityHash: $fact->customer_identity_hash,
                accountLabel: $accountLabel,
                identityConfidence: $identityConf,
                estimatedRevenueImpact: round($revenueDrop, 2),
                impactNature: 'observed_spending_decline',
                impactAssumptions: [
                    'model_type'             => 'observed_quarterly_run_rate_decline',
                    'baseline_spend'         => $contraction['baseline_spend'],
                    'raw_baseline_spend'     => $contraction['raw_baseline_spend'] ?? $contraction['baseline_spend'],
                    'baseline_orders'        => $contraction['baseline_orders'],
                    'eval_spend'             => $contraction['eval_spend'],
                    'eval_orders'            => $contraction['eval_orders'],
                    'observed_spending_drop' => $revenueDrop,
                    'contraction_percentage' => $contractionPct,
                    'eval_window_days'       => $contraction['eval_window_days'],
                    'baseline_window_days'   => $contraction['baseline_days'],
                    'has_outlier_treatment'  => $contraction['has_outlier_normalization'] ?? false,
                    'outliers'               => $contraction['outlier_orders'] ?? [],
                    'explanation'            => "Observed run-rate decline of -\${$revenueDrop} over {$contraction['eval_window_days']} days relative to baseline spend.",
                ],
                evidence: [
                    'account_identity_hash' => $fact->customer_identity_hash,
                    'is_registered'         => $profile['is_registered'],
                    'lifetime_spend'        => $lifetimeSpend,
                    'historical_aov'        => $profile['historical_aov'],
                    'baseline_spend'        => $contraction['baseline_spend'],
                    'raw_baseline_spend'    => $contraction['raw_baseline_spend'] ?? $contraction['baseline_spend'],
                    'baseline_orders'       => $contraction['baseline_orders'],
                    'eval_spend'            => $contraction['eval_spend'],
                    'eval_orders'           => $contraction['eval_orders'],
                    'revenue_drop'          => $revenueDrop,
                    'contraction_pct'       => $contractionPct,
                    'days_since_last_order' => $profile['days_since_last_order'],
                    'has_outlier_treatment' => $contraction['has_outlier_normalization'] ?? false,
                    'outlier_orders'        => $contraction['outlier_orders'] ?? [],
                ],
                metadata: [
                    'client_id'        => $client->id,
                    'detector'         => self::KEY,
                    'effective_config' => $config,
                ],
                recommendedAction: $action,
            ));
        }

        return $findings;
    }
}
