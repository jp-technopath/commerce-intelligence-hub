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
    public const VERSION = '2.1.0-method-d';

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): string
    {
        return self::VERSION;
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
        $spendTierPercentile = (int) ($thresholds['spend_percentile_tier'] ?? 80);
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
        $maxDormancyDays = (int) ($thresholds['max_dormancy_days'] ?? 730);

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
            $eval = self::evaluateAccount($client, $fact->customer_identity_hash, $analysisService, $config, $referenceDate);
            if (! $eval || ! $eval['is_qualifying']) {
                continue;
            }

            // Deduplicate by canonical account identity
            $canonicalId = $eval['canonical_account_id'];
            if (isset($seenCanonicalAccounts[$canonicalId])) {
                continue;
            }
            $seenCanonicalAccounts[$canonicalId] = true;

            $identityConf = $eval['identity_confidence'];
            $confidenceScore = match ($eval['relationship_tier']) {
                'proven_recurring'         => ($identityConf === 'verified_account') ? 0.90 : 0.80,
                'provisional_two_episodes' => 0.65,
                default                    => 0.50,
            };

            $lifetimeSpend = $eval['lifetime_net_purchasing_value'];
            $episodeBenchmark = $eval['historical_episode_benchmark'];
            $daysSinceLastOrder = $eval['days_since_last_order'];
            $episodeCount = $eval['episode_count'];
            $orderCount = $eval['order_count'];

            $accountShortHash = substr($fact->customer_identity_hash, 0, 8);
            $accountLabel = $eval['is_registered'] ? "Registered Account #{$accountShortHash}" : "Wholesale Account #{$accountShortHash}";

            // Priority scoring (0-100)
            $spendFactor = min(25.0, ($lifetimeSpend / max(1.0, $eval['client_spend_p90'])) * 25.0);
            $recurringBonus = ($eval['relationship_tier'] === 'proven_recurring') ? 15.0 : 0.0;
            $ageDecay = min(10.0, ($daysSinceLastOrder / (float) $maxDormancyDays) * 10.0);

            $priority = min(100.0, round(
                50.0 // Base for qualified enterprise winback
                + $spendFactor
                + $recurringBonus
                - $ageDecay
                + ($confidenceScore * 10.0),
                1
            ));

            $severity = ($lifetimeSpend >= $eval['client_spend_p90']) ? FindingSeverity::High : FindingSeverity::Medium;

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
                    'detector_version'                => self::VERSION,
                    'evaluated_at'                    => $eval['evaluated_at'],
                    'cadence_threshold_days'          => $eval['cadence_threshold_days'],
                    'effective_min_dormancy'          => $eval['effective_min_dormancy'],
                    'days_since_last_purchase'        => $eval['days_since_last_purchase'],
                    'days_overdue'                    => $eval['days_overdue'],
                    'lifetime_net_purchasing_value'   => $lifetimeSpend,
                    'historical_episode_benchmark'    => $episodeBenchmark,
                    'historical_aov'                  => $eval['historical_aov'],
                    'modeled_incremental_opportunity' => $episodeBenchmark,
                    'episode_count'                   => $episodeCount,
                    'order_count'                     => $orderCount,
                    'order_span_days'                 => $eval['order_span_days'],
                    'days_since_last_order'           => $daysSinceLastOrder,
                    'adaptive_multiplier'             => $eval['adaptive_multiplier'],
                    'cadence_overdue_days'            => $eval['cadence_threshold_days'],
                    'dormancy_range'                  => "{$eval['effective_min_dormancy']}-{$maxDormancyDays} days",
                    'relationship_qualification'      => $eval['relationship_tier'],
                    'is_guaranteed'                   => false,
                    'is_forecast_revenue'             => false,
                    'is_incremental_pipeline'         => false,
                    'explanation'                     => "Historical purchasing-episode benchmark of \${$episodeBenchmark} models single reactivation basket size. This is not guaranteed revenue or pipeline forecast.",
                ],
                evidence: [
                    'detector_version'                => self::VERSION,
                    'evaluated_at'                    => $eval['evaluated_at'],
                    'cadence_threshold_days'          => $eval['cadence_threshold_days'],
                    'effective_min_dormancy'          => $eval['effective_min_dormancy'],
                    'days_since_last_purchase'        => $eval['days_since_last_purchase'],
                    'days_overdue'                    => $eval['days_overdue'],
                    'account_identity_hash'           => $fact->customer_identity_hash,
                    'canonical_account_id'            => $canonicalId,
                    'is_registered'                   => $eval['is_registered'],
                    'episode_count'                   => $episodeCount,
                    'order_count'                     => $orderCount,
                    'order_span_days'                 => $eval['order_span_days'],
                    'first_order_at'                  => $eval['first_order_at'],
                    'latest_order_at'                 => $eval['latest_order_at'],
                    'days_since_last_order'           => $daysSinceLastOrder,
                    'historical_episode_benchmark'    => $episodeBenchmark,
                    'historical_aov'                  => $eval['historical_aov'],
                    'lifetime_net_purchasing_value'   => $lifetimeSpend,
                    'relationship_tier'               => $eval['relationship_tier'],
                    'adaptive_multiplier'             => $eval['adaptive_multiplier'],
                    'median_interval_days'            => $eval['median_interval_days'],
                    'variance_cv'                     => $eval['variance_cv'],
                    'client_spend_p80'                => $eval['client_spend_p80'],
                    'client_spend_p90'                => $eval['client_spend_p90'],
                    'disposition'                     => $eval['disposition'],
                    'disposition_rationale'           => $eval['disposition_rationale'],
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

    /**
     * Authoritative, genuine Method D evaluation of a single customer account against current detector standards.
     *
     * @param Client $client
     * @param string $accountIdentityHash
     * @param CustomerPurchaseAnalysisService $analysisService
     * @param array<string, mixed> $config
     * @param Carbon|null $asOf
     * @return array<string, mixed>|null
     */
    public static function evaluateAccount(
        Client $client,
        string $accountIdentityHash,
        CustomerPurchaseAnalysisService $analysisService,
        array $config = [],
        ?Carbon $asOf = null
    ): ?array {
        $thresholds = $config['thresholds'] ?? [];
        $minDormancyDays = (int) ($thresholds['min_dormancy_days'] ?? 181);
        $maxDormancyDays = (int) ($thresholds['max_dormancy_days'] ?? 730);
        $minOrderCount = (int) ($config['min_order_count'] ?? 3);

        $referenceDate = $asOf ?? ($client->timezone ? Carbon::now($client->timezone) : Carbon::now());

        $profile = $analysisService->getCustomerAccountProfile($client, $accountIdentityHash, $referenceDate);
        if (! $profile) {
            return null;
        }

        $cadence = $profile['cadence'] ?? [];
        $medianInterval = (int) ($cadence['median_interval_days'] ?? 45);
        $adaptiveMultiplier = (float) ($cadence['adaptive_winback_multiplier'] ?? 2.0);
        $cadenceOverdueDays = (int) round($medianInterval * $adaptiveMultiplier);
        $effectiveMinDormancy = max($minDormancyDays, $cadenceOverdueDays);

        $daysSinceLastOrder = (int) $profile['days_since_last_order'];
        $daysSinceLastPurchase = $daysSinceLastOrder;
        $daysOverdue = max(0, $daysSinceLastPurchase - $effectiveMinDormancy);

        $orderCount = (int) $profile['order_count'];
        $episodeCount = (int) ($profile['episode_count'] ?? $orderCount);
        $lifetimeSpend = (float) $profile['lifetime_spend'];
        $episodeBenchmark = (float) ($profile['average_episode_spend'] ?? $profile['historical_aov'] ?? 0.0);
        $aov = (float) ($profile['historical_aov'] ?? ($orderCount > 0 ? round($lifetimeSpend / $orderCount, 2) : 0.0));

        $firstOrderAt = $profile['first_order_at'] ? Carbon::parse($profile['first_order_at']) : null;
        $latestOrderAt = $profile['latest_order_at'] ? Carbon::parse($profile['latest_order_at']) : null;
        $orderSpanDays = ($firstOrderAt && $latestOrderAt) ? (int) $firstOrderAt->diffInDays($latestOrderAt) : 0;

        $baselines = $analysisService->getDerivedClientBaselines($client);
        $p90Spend = (float) ($baselines['spend_percentiles']['p90'] ?? 0.0);
        $p80Spend = (float) ($baselines['spend_percentiles']['p80'] ?? 0.0);

        $relationshipTier = 'unqualified';
        $disposition = 'Ineligible';
        $rationale = '';

        if ($daysSinceLastOrder < 60) {
            $disposition = 'Suppressed: Active Buyer (<60d)';
            $rationale = "Recent valid purchase on " . ($profile['latest_order_at'] ? substr($profile['latest_order_at'], 0, 10) : 'recent') . " ({$daysSinceLastOrder}d ago). Account is actively purchasing.";
        } elseif ($daysSinceLastOrder > $maxDormancyDays) {
            $disposition = 'Suppressed: Beyond Winback Window';
            $rationale = "Lapsed {$daysSinceLastOrder}d (> {$maxDormancyDays}d). Outside standard enterprise winback window.";
        } elseif ($episodeCount < 2) {
            $disposition = 'Suppressed: Single Purchase Episode';
            $rationale = "Only 1 purchasing episode ({$orderCount} orders). Ineligible for recurring winback.";
        } elseif ($episodeCount == 2) {
            if ($orderSpanDays <= 14) {
                $disposition = 'Suppressed: Split / Project Purchase';
                $rationale = "2 episodes within {$orderSpanDays}d. Classified as split project purchase.";
            } elseif ($lifetimeSpend < $p90Spend) {
                $disposition = 'Suppressed: Insufficient Spend (<p90)';
                $rationale = "2 episodes with spend \${$lifetimeSpend} < p90 threshold (\${$p90Spend}).";
            } elseif ($daysSinceLastOrder < $effectiveMinDormancy) {
                $disposition = 'Suppressed: Cadence Not Exceeded';
                $rationale = "Dormancy ({$daysSinceLastOrder}d) has not exceeded adaptive cadence threshold ({$effectiveMinDormancy}d).";
            } else {
                $relationshipTier = 'provisional_two_episodes';
                $disposition = 'Provisional Candidate (2 Episodes)';
                $rationale = "2 independent episodes with high spend (\${$lifetimeSpend} >= p90) and span > 14d.";
            }
        } else {
            // episodeCount >= 3
            if ($daysSinceLastOrder < $effectiveMinDormancy) {
                $disposition = 'Suppressed: Cadence Not Exceeded';
                $rationale = "Dormancy ({$daysSinceLastOrder}d) has not exceeded adaptive cadence threshold ({$effectiveMinDormancy}d = {$medianInterval}d x {$adaptiveMultiplier}).";
            } else {
                $relationshipTier = 'proven_recurring';
                $disposition = 'Potential Candidate (Overdue)';
                $rationale = "Verified recurring enterprise account ({$episodeCount} episodes, {$orderCount} orders) overdue by {$daysOverdue}d (threshold: {$effectiveMinDormancy}d).";
            }
        }

        $canonicalId = $profile['canonical_account_id'] ?? $accountIdentityHash;
        $isQualifying = in_array($relationshipTier, ['proven_recurring', 'provisional_two_episodes'])
            && $daysSinceLastOrder >= $effectiveMinDormancy
            && $daysSinceLastOrder <= $maxDormancyDays;

        $evaluatedAt = $referenceDate->toIso8601String();

        return [
            'is_qualifying'                   => $isQualifying,
            'detector_version'                => self::VERSION,
            'evaluated_at'                    => $evaluatedAt,
            'cadence_threshold_days'          => $cadenceOverdueDays,
            'effective_min_dormancy'          => $effectiveMinDormancy,
            'days_since_last_purchase'        => $daysSinceLastPurchase,
            'days_since_last_order'           => $daysSinceLastOrder,
            'days_overdue'                    => $daysOverdue,
            'median_interval_days'            => $medianInterval,
            'adaptive_multiplier'             => $adaptiveMultiplier,
            'variance_cv'                     => $cadence['variance_cv'] ?? null,
            'canonical_account_id'            => $canonicalId,
            'is_registered'                   => $profile['is_registered'] ?? false,
            'episode_count'                   => $episodeCount,
            'order_count'                     => $orderCount,
            'order_span_days'                 => $orderSpanDays,
            'first_order_at'                  => $profile['first_order_at'],
            'latest_order_at'                 => $profile['latest_order_at'],
            'historical_episode_benchmark'    => $episodeBenchmark,
            'historical_aov'                  => $aov,
            'lifetime_net_purchasing_value'   => $lifetimeSpend,
            'relationship_tier'               => $relationshipTier,
            'disposition'                     => $disposition,
            'disposition_rationale'           => $rationale,
            'client_spend_p80'                => $p80Spend,
            'client_spend_p90'                => $p90Spend,
            'min_dormancy_days'               => $minDormancyDays,
            'max_dormancy_days'               => $maxDormancyDays,
            'identity_confidence'             => $profile['identity_confidence'] ?? 'provisional_contact',
            'profile'                         => $profile,
            'baselines'                       => $baselines,
        ];
    }
}
