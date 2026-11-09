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

class B2bAccountReactivationDetector implements OpportunityDetectorInterface
{
    public const KEY = 'opportunity.b2b_account_reactivation';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'High-Value Customer Reactivation';
    }

    public function pillar(): FindingPillar
    {
        return FindingPillar::GrowthOpportunity;
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
        $dormancyMultiplier = (float) ($thresholds['dormancy_multiplier'] ?? 1.5);
        $maxDormancyDays = (int) ($thresholds['max_dormancy_days'] ?? 180);
        $spendTierPercentile = (int) ($thresholds['spend_percentile_tier'] ?? 80);
        $minSpendOverride = $thresholds['min_spend_override'] ?? null;
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

        $spendThreshold = $minSpendOverride !== null
            ? (float) $minSpendOverride
            : (float) ($baselines['spend_percentiles']["p{$spendTierPercentile}"] ?? $baselines['spend_percentiles']['p80']);

        $cohortMedianInterval = (int) ($baselines['cohort_intervals']['median'] ?? 45);
        $cohortIqr = (int) ($baselines['cohort_intervals']['iqr'] ?? 30);

        // Fetch candidates: customers with spend >= spendThreshold
        $candidateFacts = CommerceCustomerPurchaseFact::where('client_id', $client->id)
            ->where('lifetime_net_revenue', '>=', $spendThreshold)
            ->where('lifetime_valid_order_count', '>=', 2) // We allow 2 orders with provisional confidence, but filter by min_order_count if strictly >= 3
            ->orderByDesc('lifetime_net_revenue')
            ->get();

        $referenceDate = $client->timezone ? Carbon::now($client->timezone) : Carbon::now();
        $findings = collect();

        foreach ($candidateFacts as $fact) {
            $profile = $analysisService->getCustomerAccountProfile($client, $fact->customer_identity_hash, $referenceDate);
            if (! $profile) {
                continue;
            }

            $orderCount = $profile['order_count'];
            $daysSinceLastOrder = $profile['days_since_last_order'];
            $cadence = $profile['cadence'];

            // Skip if cadence cannot be inferred or was classified as a split/project purchase
            if (empty($cadence['has_cadence']) || ($cadence['evidence_level'] ?? '') === 'split_or_project_purchase') {
                continue;
            }

            // Evidence & confidence checks
            $isLimitedHistory = $orderCount < $minOrderCount;
            if ($isLimitedHistory && $orderCount < 2) {
                continue; // Cannot determine dormancy
            }

            // Determine account interval threshold
            $medianInterval = $cadence['median_interval_days'] ?? $cohortMedianInterval;
            $varianceCv = $cadence['variance_cv'];
            $regularity = $cadence['regularity'] ?? 'unknown';

            // Widen threshold for irregular or project-based buyers
            $effectiveMultiplier = $dormancyMultiplier;
            if ($regularity === 'irregular_or_project_based') {
                $effectiveMultiplier = max(2.0, $dormancyMultiplier + 0.5);
            }

            $dormancyThresholdDays = (int) round($medianInterval * $effectiveMultiplier);
            $dormancyThresholdDays = max(45, min($dormancyThresholdDays, $maxDormancyDays));

            // Check if customer is dormant
            if ($daysSinceLastOrder <= $dormancyThresholdDays) {
                continue;
            }

            // Confine to recently dormant accounts (<= maxDormancyDays, default 180d).
            // Accounts dormant beyond 180 days are handled by LongTermWinbackDetector.
            if ($daysSinceLastOrder > $maxDormancyDays) {
                continue;
            }

            // Seasonality guard: if historically off-season, do not trigger high-urgency reactivation
            $seasonality = $profile['seasonality'];
            if ($seasonality['is_historically_off_season']) {
                continue; // Suppressed due to historical seasonal pattern
            }

            // Provisional classification for limited-history accounts (e.g. 2 orders)
            $isProvisional = $isLimitedHistory || ($cadence['evidence_level'] ?? '') === 'limited_two_orders';

            // Identity confidence
            $identityConf = $profile['identity_confidence'];
            $confidenceScore = match ($identityConf) {
                'verified_account' => $isProvisional ? 0.60 : 0.90,
                'stable_hash'      => $isProvisional ? 0.50 : 0.80,
                default            => 0.40,
            };

            // Grounded economic modeling:
            // Verified historical spend & AOV
            $aov = $profile['historical_aov'];
            $lifetimeSpend = $profile['lifetime_spend'];

            $estimatedImpact = round($aov, 2);

            $accountShortHash = substr($fact->customer_identity_hash, 0, 8);
            $accountLabel = $profile['is_registered'] ? "Registered Account #{$accountShortHash}" : "Wholesale Account #{$accountShortHash}";

            // Calculate priority score (0-100)
            $priority = min(100.0, round(
                35.0 // Base
                + ($lifetimeSpend >= $baselines['spend_percentiles']['p90'] ? 30.0 : 15.0)
                + ($identityConf === 'verified_account' ? 15.0 : 5.0)
                + ($confidenceScore * 20.0),
                1
            ));

            $daysOverdue = $daysSinceLastOrder - $medianInterval;

            if ($isProvisional) {
                $title = "Provisional Account Inactivity: {$accountLabel} ({$daysSinceLastOrder}d inactive)";
                $impactNature = 'directional_opportunity';
            } else {
                $title = "Recently Dormant Account Reactivation: {$accountLabel} ({$daysSinceLastOrder}d inactive)";
                $impactNature = 'historical_reorder_value';
            }

            $description = "Account {$accountLabel} has not ordered in {$daysSinceLastOrder} days, exceeding its expected cycle of {$medianInterval} days (threshold: {$dormancyThresholdDays}d). Lifetime spend: \${$lifetimeSpend} across {$orderCount} orders (AOV: \${$aov}).";

            $action = "Initiate targeted account management check-in. Review past order history and present tailored replenishment catalog or volume incentives.";

            $findings->push(new OpportunityFindingData(
                detectorKey: self::KEY,
                title: $title,
                description: $description,
                pillar: $this->pillar(),
                category: $this->category(),
                severity: $lifetimeSpend >= $baselines['spend_percentiles']['p90'] ? FindingSeverity::High : FindingSeverity::Medium,
                confidenceScore: $confidenceScore,
                priorityScore: $priority,
                targetRole: $targetRole,
                accountIdentityHash: $fact->customer_identity_hash,
                accountLabel: $accountLabel,
                identityConfidence: $identityConf,
                estimatedRevenueImpact: $estimatedImpact,
                impactNature: $impactNature,
                impactAssumptions: [
                    'model_type'                  => $impactNature,
                    'historical_aov'              => $aov,
                    'lifetime_spend'              => $lifetimeSpend,
                    'order_count'                 => $orderCount,
                    'dormancy_threshold_days'     => $dormancyThresholdDays,
                    'max_dormancy_days'           => $maxDormancyDays,
                    'days_since_last_order'       => $daysSinceLastOrder,
                    'is_guaranteed'               => false,
                    'is_incremental_pipeline'     => false,
                    'is_provisional'              => $isProvisional,
                    'explanation'                 => "Opportunity benchmark based on account's historical average order value of \${$aov}.",
                ],
                evidence: [
                    'account_identity_hash'  => $fact->customer_identity_hash,
                    'is_registered'          => $profile['is_registered'],
                    'order_count'            => $orderCount,
                    'first_order_at'         => $profile['first_order_at'],
                    'latest_order_at'        => $profile['latest_order_at'],
                    'days_since_last_order'  => $daysSinceLastOrder,
                    'median_interval_days'   => $medianInterval,
                    'variance_cv'            => $varianceCv,
                    'regularity'             => $regularity,
                    'cadence_evidence_level' => $cadence['evidence_level'],
                    'is_provisional'         => $isProvisional,
                    'seasonality_evaluated'  => true,
                    'is_off_season'          => false,
                    'client_spend_p80'       => $baselines['spend_percentiles']['p80'],
                ],
                metadata: [
                    'client_id'             => $client->id,
                    'detector'              => self::KEY,
                    'effective_config'      => $config,
                    'derived_baselines'     => $baselines['spend_percentiles'],
                    'dormancy_multiplier'   => $dormancyMultiplier,
                ],
                recommendedAction: $action,
            ));
        }

        return $findings;
    }
}
