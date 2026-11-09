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

class ReplenishmentCadenceDetector implements OpportunityDetectorInterface
{
    public const KEY = 'opportunity.replenishment_cadence';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Repeat Purchasing & Replenishment';
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
        $leadWindowDays = (int) ($thresholds['lead_window_days'] ?? 5);
        $lagWindowDays = (int) ($thresholds['lag_window_days'] ?? 15);
        $maxCv = (float) ($thresholds['max_variance_cv'] ?? 0.50);
        $minOrderCount = (int) ($config['min_order_count'] ?? 3);
        $targetRole = $config['default_owner_role'] ?? 'growth_marketer';

        $baselines = $analysisService->getDerivedClientBaselines($client);
        if (! $baselines['has_data']) {
            return collect();
        }

        // Candidates: repeat customers with order_count >= minOrderCount
        $candidateFacts = CommerceCustomerPurchaseFact::where('client_id', $client->id)
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

            $cadence = $profile['cadence'];
            if (! $cadence['has_cadence'] || $cadence['variance_cv'] === null) {
                continue;
            }

            // Regularity gate: only regular consumable purchasers qualify for precise replenishment
            if ($cadence['variance_cv'] > $maxCv) {
                continue; // Too irregular for cadence-based replenishment
            }

            $medianInterval = (int) $cadence['median_interval_days'];
            $daysSinceLastOrder = $profile['days_since_last_order'];

            // Expected cycle window: [medianInterval - leadWindowDays, medianInterval + lagWindowDays]
            $minEligibleDays = max(1, $medianInterval - $leadWindowDays);
            $maxEligibleDays = $medianInterval + $lagWindowDays;

            if ($daysSinceLastOrder < $minEligibleDays || $daysSinceLastOrder > $maxEligibleDays) {
                continue;
            }

            $daysDelta = $daysSinceLastOrder - $medianInterval;
            $statusText = $daysDelta >= 0 ? "due (+{$daysDelta}d)" : "approaching in " . abs($daysDelta) . 'd';

            $identityConf = $profile['identity_confidence'];
            $confidenceScore = match ($identityConf) {
                'verified_account' => 0.92,
                'stable_hash'      => 0.85,
                default            => 0.50,
            };

            $aov = $profile['historical_aov'];
            $lifetimeSpend = $profile['lifetime_spend'];
            $accountShortHash = substr($fact->customer_identity_hash, 0, 8);
            $accountLabel = $profile['is_registered'] ? "Registered Account #{$accountShortHash}" : "Wholesale Account #{$accountShortHash}";

            $priority = min(100.0, round(
                40.0
                + ($lifetimeSpend >= $baselines['spend_percentiles']['p80'] ? 25.0 : 10.0)
                + (abs($daysDelta) <= 3 ? 15.0 : 5.0)
                + ($confidenceScore * 20.0),
                1
            ));

            $title = "Replenishment Reorder Opportunity: {$accountLabel} ({$statusText})";
            $description = "Account {$accountLabel} is a regular repeat buyer (median interval: {$medianInterval}d, cv: {$cadence['variance_cv']}). Last ordered {$daysSinceLastOrder} days ago. Expected cycle timing indicates reorder opportunity at historical AOV of \${$aov}.";

            $action = "Trigger personalized replenishment notification, reorder email reminder, or direct sales follow-up before order goes to a competitor.";

            $findings->push(new OpportunityFindingData(
                detectorKey: self::KEY,
                title: $title,
                description: $description,
                pillar: $this->pillar(),
                category: $this->category(),
                severity: FindingSeverity::Medium,
                confidenceScore: $confidenceScore,
                priorityScore: $priority,
                targetRole: $targetRole,
                accountIdentityHash: $fact->customer_identity_hash,
                accountLabel: $accountLabel,
                identityConfidence: $identityConf,
                estimatedRevenueImpact: round($aov, 2),
                impactNature: 'historical_reorder_value',
                impactAssumptions: [
                    'model_type'                  => 'historical_reorder_value',
                    'historical_aov'              => $aov,
                    'median_interval_days'        => $medianInterval,
                    'variance_cv'                 => $cadence['variance_cv'],
                    'days_since_last_order'       => $daysSinceLastOrder,
                    'lead_window_days'            => $leadWindowDays,
                    'lag_window_days'             => $lagWindowDays,
                    'is_guaranteed'               => false,
                    'is_incremental_pipeline'     => false,
                    'explanation'                 => "Opportunity assumes timely reorder of regular consumable basket at verified historical AOV of \${$aov}.",
                ],
                evidence: [
                    'account_identity_hash' => $fact->customer_identity_hash,
                    'is_registered'         => $profile['is_registered'],
                    'order_count'           => $profile['order_count'],
                    'median_interval_days'  => $medianInterval,
                    'variance_cv'           => $cadence['variance_cv'],
                    'regularity'            => $cadence['regularity'],
                    'days_since_last_order' => $daysSinceLastOrder,
                    'days_overdue_or_lead'  => $daysDelta,
                    'historical_aov'        => $aov,
                    'lifetime_spend'        => $lifetimeSpend,
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
