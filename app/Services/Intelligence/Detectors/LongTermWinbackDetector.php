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

class LongTermWinbackDetector implements OpportunityDetectorInterface
{
    public const KEY = 'opportunity.long_term_winback';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Long-Term Account Winback';
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
        $minDormancyDays = (int) ($thresholds['min_dormancy_days'] ?? 181);
        $maxDormancyDays = (int) ($thresholds['max_dormancy_days'] ?? 730);
        $spendTierPercentile = (int) ($thresholds['spend_percentile_tier'] ?? 80);
        $minOrderCount = (int) ($config['min_order_count'] ?? 3);
        $targetRole = $config['default_owner_role'] ?? 'executive_sales';

        $baselines = $analysisService->getDerivedClientBaselines($client);
        if (! $baselines['has_data']) {
            return collect();
        }

        // Enforce detector-specific historical baseline depth (default 365 days for long-term winback)
        $minClientHistoryDays = (int) ($thresholds['min_client_history_days'] ?? 365);
        $clientHistoryDepth = (int) ($baselines['history_depth_days'] ?? 0);
        if ($clientHistoryDepth < $minClientHistoryDays) {
            return collect();
        }

        $spendThreshold = (float) ($baselines['spend_percentiles']["p{$spendTierPercentile}"] ?? $baselines['spend_percentiles']['p80']);
        $p90Spend = (float) ($baselines['spend_percentiles']['p90'] ?? $spendThreshold);

        // Fetch high-value candidates
        $candidateFacts = CommerceCustomerPurchaseFact::where('client_id', $client->id)
            ->where('lifetime_net_revenue', '>=', $spendThreshold)
            ->where('lifetime_valid_order_count', '>=', 2)
            ->orderByDesc('lifetime_net_revenue')
            ->get();

        $referenceDate = $client->timezone ? Carbon::now($client->timezone) : Carbon::now();
        $findings = collect();
        $seenCanonicalAccounts = [];

        foreach ($candidateFacts as $fact) {
            $profile = $analysisService->getCustomerAccountProfile($client, $fact->customer_identity_hash, $referenceDate);
            if (! $profile) {
                continue;
            }

            // Deduplicate by canonical account identity
            $canonicalId = $profile['canonical_account_id'] ?? $fact->canonical_account_id ?? $fact->customer_identity_hash;
            if (isset($seenCanonicalAccounts[$canonicalId])) {
                continue;
            }
            $seenCanonicalAccounts[$canonicalId] = true;

            $orderCount = $profile['order_count'];
            $episodeCount = $profile['episode_count'] ?? $orderCount;
            $daysSinceLastOrder = $profile['days_since_last_order'];
            $cadence = $profile['cadence'] ?? [];

            // Adaptive winback dormancy: scale required inactivity by account purchasing cadence and cv
            $medianInterval = $cadence['median_interval_days'] ?? 45;
            $adaptiveMultiplier = $cadence['adaptive_winback_multiplier'] ?? 2.0;
            $cadenceOverdueDays = (int) round($medianInterval * $adaptiveMultiplier);
            $effectiveMinDormancy = max($minDormancyDays, $cadenceOverdueDays);

            // Dormancy window
            if ($daysSinceLastOrder < $effectiveMinDormancy || $daysSinceLastOrder > $maxDormancyDays) {
                continue;
            }

            // Relationship qualification based on independent purchasing episodes:
            // 1. If episode_count >= minOrderCount (default 3): Proven recurring account.
            // 2. If episode_count == 2: Require order span > 14 days AND lifetime spend >= p90 to filter out split/project purchases.
            // 3. If episode_count <= 1: Single episode / one-time purchaser -> exclude.
            $relationshipTier = 'unqualified';
            $firstOrderAt = Carbon::parse($profile['first_order_at']);
            $latestOrderAt = Carbon::parse($profile['latest_order_at']);
            $orderSpanDays = (int) $firstOrderAt->diffInDays($latestOrderAt);

            if ($episodeCount >= $minOrderCount) {
                $relationshipTier = 'proven_recurring';
            } elseif ($episodeCount === 2) {
                if ($orderSpanDays > 14 && $profile['lifetime_spend'] >= $p90Spend) {
                    $relationshipTier = 'provisional_two_episodes';
                } else {
                    // One-time split order or insufficient span: exclude from enterprise winback
                    continue;
                }
            } else {
                continue;
            }

            // Identity confidence
            $identityConf = $profile['identity_confidence'];
            $confidenceScore = match ($relationshipTier) {
                'proven_recurring'         => ($identityConf === 'verified_account') ? 0.90 : 0.80,
                'provisional_two_episodes' => 0.65,
                default                    => 0.50,
            };

            $lifetimeSpend = $profile['lifetime_spend'];
            $aov = $profile['historical_aov'];
            $episodeBenchmark = $profile['average_episode_spend'] ?? $aov;

            $accountShortHash = substr($fact->customer_identity_hash, 0, 8);
            $accountLabel = $profile['is_registered'] ? "Registered Account #{$accountShortHash}" : "Wholesale Account #{$accountShortHash}";

            // Priority scoring (0-100)
            // Emphasizes high LTV and proven recurring status, with slight decay for extreme age
            $spendFactor = min(25.0, ($lifetimeSpend / max(1.0, $p90Spend)) * 25.0);
            $recurringBonus = ($relationshipTier === 'proven_recurring') ? 15.0 : 0.0;
            $ageDecay = min(10.0, ($daysSinceLastOrder / (float) $maxDormancyDays) * 10.0);

            $priority = min(100.0, round(
                50.0 // Base for qualified enterprise winback
                + $spendFactor
                + $recurringBonus
                - $ageDecay
                + ($confidenceScore * 10.0),
                1
            ));

            $severity = ($lifetimeSpend >= $p90Spend) ? FindingSeverity::High : FindingSeverity::Medium;

            $title = "Long-Term Winback Opportunity: {$accountLabel} ({$daysSinceLastOrder}d inactive)";
            $description = "Account {$accountLabel} has a verified Lifetime Net Purchasing Value of \${$lifetimeSpend} across {$episodeCount} purchasing episodes ({$orderCount} orders, Historical Purchasing-Episode Benchmark: \${$episodeBenchmark}) but has lapsed for {$daysSinceLastOrder} days. Modeled Incremental Opportunity: \${$episodeBenchmark}.";

            $action = "Executive outreach by Head of Sales or Commercial VP. Re-engage account leadership to discuss past service, updated enterprise product lines, and custom contractual pricing.";

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
                estimatedRevenueImpact: round($episodeBenchmark, 2),
                impactNature: 'historical_purchasing_episode_benchmark',
                impactAssumptions: [
                    'model_type'                      => 'historical_purchasing_episode_benchmark',
                    'financial_presentation_label'    => 'Historical Purchasing-Episode Benchmark',
                    'lifetime_net_purchasing_value'   => $lifetimeSpend,
                    'historical_episode_benchmark'    => $episodeBenchmark,
                    'historical_aov'                  => $aov,
                    'modeled_incremental_opportunity' => $episodeBenchmark,
                    'episode_count'                   => $episodeCount,
                    'order_count'                     => $orderCount,
                    'order_span_days'                 => $orderSpanDays,
                    'days_since_last_order'           => $daysSinceLastOrder,
                    'adaptive_multiplier'             => $adaptiveMultiplier,
                    'cadence_overdue_days'            => $cadenceOverdueDays,
                    'dormancy_range'                  => "{$effectiveMinDormancy}-{$maxDormancyDays} days",
                    'relationship_qualification'      => $relationshipTier,
                    'is_guaranteed'                   => false,
                    'is_forecast_revenue'             => false,
                    'is_incremental_pipeline'         => false,
                    'explanation'                     => "Historical purchasing-episode benchmark of \${$episodeBenchmark} models single reactivation basket size. This is not guaranteed revenue or pipeline forecast.",
                ],
                evidence: [
                    'account_identity_hash'           => $fact->customer_identity_hash,
                    'canonical_account_id'            => $canonicalId,
                    'is_registered'                   => $profile['is_registered'],
                    'episode_count'                   => $episodeCount,
                    'order_count'                     => $orderCount,
                    'order_span_days'                 => $orderSpanDays,
                    'first_order_at'                  => $profile['first_order_at'],
                    'latest_order_at'                 => $profile['latest_order_at'],
                    'days_since_last_order'           => $daysSinceLastOrder,
                    'historical_episode_benchmark'    => $episodeBenchmark,
                    'historical_aov'                  => $aov,
                    'lifetime_net_purchasing_value'   => $lifetimeSpend,
                    'relationship_tier'               => $relationshipTier,
                    'adaptive_multiplier'             => $adaptiveMultiplier,
                    'client_spend_p80'                => $baselines['spend_percentiles']['p80'],
                    'client_spend_p90'                => $p90Spend,
                ],
                metadata: [
                    'client_id'                       => $client->id,
                    'detector'                        => self::KEY,
                    'effective_config'                => $config,
                    'derived_baselines'               => $baselines['spend_percentiles'],
                ],
                recommendedAction: $action,
            ));
        }

        return $findings;
    }
}
