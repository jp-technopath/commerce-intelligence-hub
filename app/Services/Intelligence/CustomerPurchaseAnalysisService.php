<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\CommerceCustomerIdentityMapping;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerPurchaseAnalysisService
{
    protected array $baselinesCache = [];
    protected array $accountProfileCache = [];

    /**
     * Compute data-derived client baselines from historical customer facts and orders.
     */
    public function getDerivedClientBaselines(Client $client): array
    {
        if (isset($this->baselinesCache[$client->id])) {
            return $this->baselinesCache[$client->id];
        }

        try {
            $facts = CommerceCustomerPurchaseFact::where('client_id', $client->id)
                ->where('lifetime_valid_order_count', '>', 0)
                ->get();
        } catch (\Throwable $e) {
            $facts = collect();
        }

        if ($facts->isEmpty()) {
            $this->baselinesCache[$client->id] = [
                'has_data'               => false,
                'total_customers'        => 0,
                'repeat_customers'       => 0,
                'repeat_rate_pct'        => 0.0,
                'spend_percentiles'      => ['p50' => 0, 'p70' => 0, 'p80' => 0, 'p90' => 0, 'max' => 0],
                'cohort_intervals'       => ['median' => 0, 'q1' => 0, 'q3' => 0, 'iqr' => 0],
                'client_aov'             => 0.0,
            ];
            return $this->baselinesCache[$client->id];
        }

        $spends = $facts->pluck('lifetime_net_revenue')->map(fn ($val) => (float) $val)->sort()->values()->all();
        $totalCustomers = count($spends);
        $repeatFacts = $facts->where('lifetime_valid_order_count', '>=', 2);
        $repeatCount = $repeatFacts->count();
        $repeatRate = $totalCustomers > 0 ? round(($repeatCount / $totalCustomers) * 100, 2) : 0.0;

        $p50 = $this->calculatePercentile($spends, 50);
        $p70 = $this->calculatePercentile($spends, 70);
        $p80 = $this->calculatePercentile($spends, 80);
        $p90 = $this->calculatePercentile($spends, 90);
        $maxSpend = end($spends) ?: 0.0;

        // Calculate cohort inter-purchase intervals across all repeat customers
        $cohortIntervals = $this->calculateCohortIntervals($client);

        // Calculate overall client AOV
        $totalRevenue = CommerceOrder::where('client_id', $client->id)->where('is_valid', true)->sum('net_revenue');
        $totalOrders = CommerceOrder::where('client_id', $client->id)->where('is_valid', true)->count();
        $clientAov = $totalOrders > 0 ? round((float) $totalRevenue / $totalOrders, 2) : 0.0;

        // Calculate client transaction history depth
        $orderDates = CommerceOrder::where('client_id', $client->id)
            ->where('is_valid', true)
            ->selectRaw('MIN(order_date) as earliest_order, MAX(order_date) as latest_order')
            ->first();

        $earliestOrder = $orderDates?->earliest_order ? Carbon::parse($orderDates->earliest_order) : null;
        $latestOrder = $orderDates?->latest_order ? Carbon::parse($orderDates->latest_order) : null;
        $historyDepthDays = ($earliestOrder && $latestOrder) ? (int) $earliestOrder->diffInDays($latestOrder) : 0;

        $this->baselinesCache[$client->id] = [
            'has_data'               => true,
            'total_customers'        => $totalCustomers,
            'repeat_customers'       => $repeatCount,
            'repeat_rate_pct'        => $repeatRate,
            'spend_percentiles'      => [
                'p50' => round($p50, 2),
                'p70' => round($p70, 2),
                'p80' => round($p80, 2),
                'p90' => round($p90, 2),
                'max' => round($maxSpend, 2),
            ],
            'cohort_intervals'       => $cohortIntervals,
            'client_aov'             => $clientAov,
            'history_depth_days'     => $historyDepthDays,
            'earliest_order_at'      => $earliestOrder?->toIso8601String(),
            'latest_order_at'        => $latestOrder?->toIso8601String(),
        ];

        return $this->baselinesCache[$client->id];
    }

    /**
     * Compute a detailed customer account profile including order cadence,
     * purchasing regularity, spend run-rate contraction, and seasonality.
     */
    public function getCustomerAccountProfile(Client $client, string $customerIdentityHash, ?Carbon $asOf = null): ?array
    {
        $cacheKey = "{$client->id}:{$customerIdentityHash}";
        if (isset($this->accountProfileCache[$cacheKey])) {
            return $this->accountProfileCache[$cacheKey];
        }

        $fact = CommerceCustomerPurchaseFact::where('client_id', $client->id)
            ->where('customer_identity_hash', $customerIdentityHash)
            ->first();

        if (! $fact) {
            $mappedCanonical = CommerceCustomerIdentityMapping::where('client_id', $client->id)
                ->where('identity_type', 'source_identity_hash')
                ->where('identity_value', $customerIdentityHash)
                ->where('is_active', true)
                ->value('canonical_account_id');

            if (! empty($mappedCanonical)) {
                $fact = CommerceCustomerPurchaseFact::where('client_id', $client->id)
                    ->where('canonical_account_id', $mappedCanonical)
                    ->first();
            }
        }

        if (! $fact) {
            $this->accountProfileCache[$cacheKey] = null;
            return null;
        }

        $ordersQuery = CommerceOrder::where('client_id', $client->id)
            ->where('is_valid', true);

        if (! empty($fact->canonical_account_id)) {
            $ordersQuery->where('canonical_account_id', $fact->canonical_account_id);
        } else {
            $ordersQuery->where('customer_identity_hash', $customerIdentityHash);
        }

        if ($asOf) {
            $ordersQuery->where('order_date', '<=', $asOf->toDateTimeString());
        }

        $orders = $ordersQuery->orderBy('order_date', 'asc')->get(['id', 'order_date', 'net_revenue', 'metadata_json']);

        $orderCount = $orders->count();
        if ($orderCount === 0) {
            return null;
        }

        $timezone = $client->timezone ?? 'UTC';
        $referenceDate = $asOf ? Carbon::parse($asOf)->setTimezone($timezone) : Carbon::now($timezone);

        $firstOrderAt = Carbon::parse($orders->first()->order_date)->setTimezone($timezone);
        $latestOrderAt = Carbon::parse($orders->last()->order_date)->setTimezone($timezone);
        $daysSinceLastOrder = max(0, (int) $latestOrderAt->diffInDays($referenceDate));

        $totalSpend = (float) $orders->sum('net_revenue');
        $aov = $orderCount > 0 ? round($totalSpend / $orderCount, 2) : 0.0;

        // Cluster valid orders into 48-hour purchasing episodes
        $episodes = $this->clusterOrdersIntoEpisodes($orders, 48.0);
        $episodeCount = $episodes->count();
        $averageEpisodeSpend = $episodeCount > 0 ? round($totalSpend / $episodeCount, 2) : $aov;

        // Identity confidence qualification
        $identityConfidence = 'provisional';
        if ($fact->is_registered_customer) {
            $identityConfidence = 'verified_account';
        } elseif (! empty($customerIdentityHash) && strlen($customerIdentityHash) >= 32) {
            $identityConfidence = 'stable_hash';
        }

        // Account-level cadence and variance calculation on purchasing episodes
        $cadenceData = $this->calculateAccountCadence($client, $orders, $episodes);

        // Contraction evaluation: trailing adaptive cadence window vs baseline window with bulk outlier normalization
        $contractionData = $this->calculateAccountContraction($orders, $referenceDate, $cadenceData, $daysSinceLastOrder, $aov);

        // Seasonality evaluation
        $seasonalityData = $this->calculateAccountSeasonality($orders, $referenceDate);

        $this->accountProfileCache[$cacheKey] = [
            'customer_identity_hash' => $customerIdentityHash,
            'canonical_account_id'   => $fact->canonical_account_id,
            'customer_id'            => $fact->customer_id,
            'customer_email'         => $fact->customer_email,
            'customer_name'          => $fact->customer_name,
            'company_name'           => $fact->company_name,
            'is_registered'          => (bool) $fact->is_registered_customer,
            'identity_confidence'    => $identityConfidence,
            'order_count'            => $orderCount,
            'episode_count'          => $episodeCount,
            'first_order_at'         => $firstOrderAt->toIso8601String(),
            'latest_order_at'        => $latestOrderAt->toIso8601String(),
            'days_since_last_order'  => $daysSinceLastOrder,
            'lifetime_spend'         => round($totalSpend, 2),
            'historical_aov'         => $aov,
            'average_episode_spend'  => $averageEpisodeSpend,
            'cadence'                => $cadenceData,
            'contraction'            => $contractionData,
            'seasonality'            => $seasonalityData,
        ];

        return $this->accountProfileCache[$cacheKey];
    }

    /**
     * Resolve authentic human-readable customer display attributes for a given client and identity hash.
     * Prioritizes purchase facts (registered accounts) and falls back to verified orders.
     * Never generates fictional emails and guarantees safe client segregation.
     *
     * @return array{
     *   customer_identity_hash: ?string,
     *   short_hash: ?string,
     *   customer_id: ?string,
     *   customer_email: ?string,
     *   customer_name: ?string,
     *   company_name: ?string,
     *   primary_name: ?string,
     *   has_email: bool,
     *   display_label: string,
     *   secondary_badge: ?string
     * }
     */
    protected static array $resolvedCustomerDisplayCache = [];

    public function resolveCustomerIdentityDisplay(Client|int $client, ?string $customerIdentityHash): array
    {
        if (empty($customerIdentityHash)) {
            return self::formatCustomerDisplay(null);
        }

        $clientId = $client instanceof Client ? $client->id : (int) $client;
        $cacheKey = "{$clientId}:{$customerIdentityHash}";

        if (isset(self::$resolvedCustomerDisplayCache[$cacheKey])) {
            return self::$resolvedCustomerDisplayCache[$cacheKey];
        }

        // 1. Check purchase facts (reconciled, verified), including canonical mapping fallback
        $fact = CommerceCustomerPurchaseFact::where('client_id', $clientId)
            ->where('customer_identity_hash', $customerIdentityHash)
            ->first();

        if (! $fact) {
            $mappedCanonical = CommerceCustomerIdentityMapping::where('client_id', $clientId)
                ->where('identity_type', 'source_identity_hash')
                ->where('identity_value', $customerIdentityHash)
                ->where('is_active', true)
                ->value('canonical_account_id');

            if (! empty($mappedCanonical)) {
                $fact = CommerceCustomerPurchaseFact::where('client_id', $clientId)
                    ->where('canonical_account_id', $mappedCanonical)
                    ->first();
            }
        }

        $custId = $fact?->customer_id;
        $custEmail = $fact?->customer_email;
        $custName = $fact?->customer_name;
        $companyName = $fact?->company_name;

        // 2. Fallback to latest valid order with contact info if fact is missing details
        if (empty($custEmail) || (empty($custName) && empty($companyName))) {
            $latestOrder = CommerceOrder::where('client_id', $clientId)
                ->where('customer_identity_hash', $customerIdentityHash)
                ->where('is_valid', true)
                ->where(function ($query) {
                    $query->whereNotNull('customer_email')
                        ->orWhereNotNull('customer_name')
                        ->orWhereNotNull('company_name');
                })
                ->orderBy('order_date', 'desc')
                ->first();

            if ($latestOrder) {
                $custId = $custId ?: $latestOrder->registered_customer_id;
                $custEmail = $custEmail ?: $latestOrder->customer_email;
                $custName = $custName ?: $latestOrder->customer_name;
                $companyName = $companyName ?: $latestOrder->company_name;
            }
        }

        $formatted = self::formatCustomerDisplay(
            customerIdentityHash: $customerIdentityHash,
            customerId: $custId,
            customerEmail: $custEmail,
            customerName: $custName,
            companyName: $companyName
        );

        return self::$resolvedCustomerDisplayCache[$cacheKey] = $formatted;
    }

    /**
     * Batch-compute order-header commercial summaries (last purchase date & amount,
     * pre-dormancy 12-month spend, peak ordering months) for Winback accounts in one query.
     *
     * @param  array<int, string>  $accountHashes
     * @return array<string, array<string, mixed>>
     */
    public function getWinbackOrderSummariesForHashes(int $clientId, array $accountHashes): array
    {
        $hashes = array_values(array_filter(array_unique($accountHashes)));
        if (empty($hashes)) {
            return [];
        }

        $facts = CommerceCustomerPurchaseFact::where('client_id', $clientId)
            ->whereIn('customer_identity_hash', $hashes)
            ->get(['customer_identity_hash', 'canonical_account_id'])
            ->keyBy('customer_identity_hash');

        $canonicalIds = $facts->pluck('canonical_account_id')->filter()->unique()->values()->all();

        $orders = CommerceOrder::where('client_id', $clientId)
            ->where('is_valid', true)
            ->where(function ($q) use ($hashes, $canonicalIds) {
                $q->whereIn('customer_identity_hash', $hashes);
                if (! empty($canonicalIds)) {
                    $q->orWhereIn('canonical_account_id', $canonicalIds);
                }
            })
            ->orderBy('order_date', 'asc')
            ->get(['customer_identity_hash', 'canonical_account_id', 'source_increment_id', 'order_date', 'net_revenue']);

        $byCanonical = $orders->whereNotNull('canonical_account_id')->groupBy('canonical_account_id');
        $byHash = $orders->groupBy('customer_identity_hash');

        $result = [];
        foreach ($hashes as $hash) {
            $canonicalId = $facts->get($hash)?->canonical_account_id;
            $acctOrders = ($canonicalId && $byCanonical->has($canonicalId))
                ? $byCanonical->get($canonicalId)
                : $byHash->get($hash, collect());

            if ($acctOrders->isEmpty()) {
                continue;
            }

            $latestOrder = $acctOrders->last();
            $latestAt = Carbon::parse($latestOrder->order_date);
            $windowStart = $latestAt->copy()->subDays(365);

            $preDormancyOrders = $acctOrders->filter(
                fn ($o) => Carbon::parse($o->order_date)->greaterThanOrEqualTo($windowStart)
            );

            $monthCounts = [];
            if ($acctOrders->count() >= 4) {
                foreach ($acctOrders as $o) {
                    $monthLabel = Carbon::parse($o->order_date)->format('M');
                    $monthCounts[$monthLabel] = ($monthCounts[$monthLabel] ?? 0) + 1;
                }
                arsort($monthCounts);
                $monthCounts = array_slice($monthCounts, 0, 2, true);
            }

            $result[$hash] = [
                'last_order_at'           => $latestAt->toIso8601String(),
                'last_order_net_revenue'  => round((float) $latestOrder->net_revenue, 2),
                'last_order_increment_id' => $latestOrder->source_increment_id,
                'pre_dormancy_12m_spend'  => round((float) $preDormancyOrders->sum('net_revenue'), 2),
                'pre_dormancy_12m_orders' => $preDormancyOrders->count(),
                'peak_ordering_months'    => array_keys($monthCounts),
                'has_line_item_sku_data'  => false,
            ];
        }

        return $result;
    }

    /**
     * Canonical formatting of customer display information across Forge.
     */
    public static function formatCustomerDisplay(
        ?string $customerIdentityHash,
        ?string $customerId = null,
        ?string $customerEmail = null,
        ?string $customerName = null,
        ?string $companyName = null
    ): array {
        $shortHash = $customerIdentityHash ? substr($customerIdentityHash, 0, 8) : null;
        $hasEmail = ! empty($customerEmail);
        $hasCompany = ! empty($companyName);
        $hasName = ! empty($customerName);
        $hasId = ! empty($customerId);

        // Distinguish company name from individual contact name without duplicating shared names
        $primaryName = null;
        if ($hasCompany && $hasName) {
            $trimmedCompany = trim($companyName);
            $trimmedName = trim($customerName);
            if (strcasecmp($trimmedCompany, $trimmedName) !== 0 && stripos($trimmedCompany, $trimmedName) === false) {
                $primaryName = "{$trimmedCompany} ({$trimmedName})";
            } else {
                $primaryName = $trimmedCompany;
            }
        } elseif ($hasCompany) {
            $primaryName = trim($companyName);
        } elseif ($hasName) {
            $primaryName = trim($customerName);
        } elseif ($hasId) {
            $primaryName = "Customer #{$customerId}";
        } elseif ($shortHash) {
            $primaryName = "Account #{$shortHash}";
        } else {
            $primaryName = "Customer";
        }

        if ($hasEmail) {
            $displayLabel = "{$primaryName} ({$customerEmail})";
        } else {
            $displayLabel = "{$primaryName} (Email unavailable)";
        }

        $secondaryBadge = $hasId ? "CID: #{$customerId}" : ($shortHash ? "#{$shortHash}" : null);

        return [
            'customer_identity_hash' => $customerIdentityHash,
            'short_hash'             => $shortHash,
            'customer_id'            => $customerId,
            'customer_email'         => $customerEmail,
            'customer_name'          => $customerName,
            'company_name'           => $companyName,
            'primary_name'           => $primaryName,
            'has_email'              => $hasEmail,
            'display_label'          => $displayLabel,
            'account_label'          => $displayLabel,
            'secondary_badge'        => $secondaryBadge,
        ];
    }

    /**
     * Cluster valid commerce orders into purchasing episodes.
     * Orders placed by the same account within <= 48 hours measured from the FIRST order
     * in the episode are grouped into a single purchasing episode.
     *
     * In addition:
     * - Preserves all individual orders, their order IDs, and financial values.
     * - Avoids merging clearly unrelated purchase orders solely because they occurred within 48 hours
     *   by checking purchase order / project references in order metadata.
     *
     * @return Collection<int, array{first_order_at: string, latest_order_at: string, order_count: int, total_spend: float, po_references: array, orders: array}>
     */
    public function clusterOrdersIntoEpisodes(Collection $orders, float $windowHours = 48.0): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        $sorted = $orders->sortBy('order_date')->values();
        $episodes = collect();

        $extractPo = function ($order): ?string {
            $meta = is_array($order->metadata_json ?? null)
                ? $order->metadata_json
                : (is_string($order->metadata_json ?? null) ? json_decode($order->metadata_json, true) : []);
            $po = $meta['po_number'] ?? $meta['purchase_order'] ?? $meta['project_id'] ?? $meta['reference'] ?? null;

            return ! empty($po) && is_string($po) ? trim(strtolower($po)) : null;
        };

        $firstPo = $extractPo($sorted[0]);
        $currentEpisode = [
            'first_order_at' => $sorted[0]->order_date,
            'latest_order_at'=> $sorted[0]->order_date,
            'order_count'    => 1,
            'total_spend'    => (float) $sorted[0]->net_revenue,
            'po_references'  => $firstPo ? [$firstPo] : [],
            'orders'         => [
                [
                    'id'          => $sorted[0]->id ?? null,
                    'order_date'  => $sorted[0]->order_date,
                    'net_revenue' => (float) $sorted[0]->net_revenue,
                ],
            ],
        ];

        for ($i = 1; $i < $sorted->count(); $i++) {
            $currOrder = $sorted[$i];
            $firstDate = Carbon::parse($currentEpisode['first_order_at']);
            $currDate = Carbon::parse($currOrder->order_date);
            $diffHoursFromStart = abs($currDate->diffInHours($firstDate));

            $currPo = $extractPo($currOrder);
            $hasConflictingPo = false;

            if ($currPo !== null && ! empty($currentEpisode['po_references'])) {
                // If candidate order has an explicit PO that conflicts with the episode's established PO
                if (! in_array($currPo, $currentEpisode['po_references'], true)) {
                    $hasConflictingPo = true;
                }
            }

            if ($diffHoursFromStart <= $windowHours && ! $hasConflictingPo) {
                // Clustered into current episode
                $currentEpisode['latest_order_at'] = $currOrder->order_date;
                $currentEpisode['order_count']++;
                $currentEpisode['total_spend'] += (float) $currOrder->net_revenue;
                if ($currPo !== null && ! in_array($currPo, $currentEpisode['po_references'], true)) {
                    $currentEpisode['po_references'][] = $currPo;
                }
                $currentEpisode['orders'][] = [
                    'id'          => $currOrder->id ?? null,
                    'order_date'  => $currOrder->order_date,
                    'net_revenue' => (float) $currOrder->net_revenue,
                ];
            } else {
                // Finalize current episode and begin next
                $episodes->push($currentEpisode);
                $currentEpisode = [
                    'first_order_at' => $currOrder->order_date,
                    'latest_order_at'=> $currOrder->order_date,
                    'order_count'    => 1,
                    'total_spend'    => (float) $currOrder->net_revenue,
                    'po_references'  => $currPo ? [$currPo] : [],
                    'orders'         => [
                        [
                            'id'          => $currOrder->id ?? null,
                            'order_date'  => $currOrder->order_date,
                            'net_revenue' => (float) $currOrder->net_revenue,
                        ],
                    ],
                ];
            }
        }

        $episodes->push($currentEpisode);

        return $episodes;
    }

    /**
     * Calculate account cadence and purchasing regularity based on purchasing episodes.
     * Enforces minimum evidence requirements:
     * - episode_count >= 3: account-specific mean, median, IQR, cv, adaptive multiplier M in [2.5, 4.0].
     * - episode_count == 2: single interval, provisional directional candidate, cohort benchmark used.
     * - episode_count == 1: no intervals, confidence 'unsupported', ineligible for winback.
     */
    public function calculateAccountCadence(Client $client, Collection $orders, ?Collection $episodes = null): array
    {
        if ($episodes === null) {
            $episodes = $this->clusterOrdersIntoEpisodes($orders);
        }

        $episodeCount = $episodes->count();

        if ($episodeCount < 2) {
            return [
                'has_cadence'                 => false,
                'evidence_level'              => 'single_episode_insufficient',
                'confidence'                  => 'unsupported',
                'episode_count'               => $episodeCount,
                'interval_count'              => 0,
                'intervals_days'              => [],
                'median_interval_days'        => null,
                'mean_interval_days'          => null,
                'std_dev_days'                => null,
                'variance_cv'                 => null,
                'regularity'                  => 'unknown',
                'expected_next_order_at'      => null,
                'days_overdue'                => 0,
                'adaptive_winback_multiplier' => 2.5,
                'winback_overdue_days'        => null,
                'limitation_note'             => 'Account has only 1 independent purchasing episode. Recurring cadence cannot be established.',
            ];
        }

        $intervals = [];
        for ($i = 1; $i < $episodeCount; $i++) {
            $prevDate = Carbon::parse($episodes[$i - 1]['first_order_at']);
            $currDate = Carbon::parse($episodes[$i]['first_order_at']);
            $intervals[] = max(1, (int) $prevDate->diffInDays($currDate));
        }

        $intervalCount = count($intervals);

        if ($episodeCount == 2) {
            // Exactly 2 episodes => 1 interval. Provisional candidate.
            $singleInterval = $intervals[0];

            // If the 2 episodes occurred within <= 14 days of each other, this represents a clustered project purchase
            if ($singleInterval <= 14) {
                return [
                    'has_cadence'                 => false,
                    'evidence_level'              => 'split_or_project_purchase',
                    'confidence'                  => 'unsupported',
                    'episode_count'               => 2,
                    'interval_count'              => 1,
                    'intervals_days'              => $intervals,
                    'median_interval_days'        => null,
                    'mean_interval_days'          => null,
                    'std_dev_days'                => null,
                    'variance_cv'                 => null,
                    'regularity'                  => 'project_or_split_purchase',
                    'expected_next_order_at'      => null,
                    'days_overdue'                => 0,
                    'adaptive_winback_multiplier' => 2.5,
                    'winback_overdue_days'        => null,
                    'limitation_note'             => "Account has only 2 purchasing episodes placed {$singleInterval} days apart. Classified as a split or project purchase; recurring cadence cannot be inferred.",
                ];
            }

            $cohortBaselines = $this->getDerivedClientBaselines($client);
            $cohortMedian = $cohortBaselines['cohort_intervals']['median'] ?? 30;

            $latestOrderDate = Carbon::parse($episodes->last()['latest_order_at']);
            $expectedNextOrderAt = $latestOrderDate->copy()->addDays($singleInterval);

            return [
                'has_cadence'                 => true,
                'evidence_level'              => 'provisional_two_episodes',
                'confidence'                  => 'provisional',
                'episode_count'               => 2,
                'interval_count'              => 1,
                'intervals_days'              => $intervals,
                'median_interval_days'        => $singleInterval,
                'mean_interval_days'          => (float) $singleInterval,
                'std_dev_days'                => null,
                'variance_cv'                 => null,
                'cohort_benchmark_used'       => true,
                'cohort_median_days'          => $cohortMedian,
                'regularity'                  => 'limited_history',
                'expected_next_order_at'      => $expectedNextOrderAt->toIso8601String(),
                'adaptive_winback_multiplier' => 2.5,
                'winback_overdue_days'        => (int) round($singleInterval * 2.5),
                'limitation_note'             => 'Account has only 2 historical purchasing episodes (1 observed interval). Periodicity is provisional; directional candidate only.',
            ];
        }

        // episode_count >= 3: account-specific cadence and variance
        sort($intervals);
        $mean = array_sum($intervals) / $intervalCount;
        $variance = 0.0;
        foreach ($intervals as $val) {
            $variance += pow($val - $mean, 2);
        }
        $stdDev = sqrt($variance / $intervalCount);
        $cv = $mean > 0 ? round($stdDev / $mean, 3) : 0.0;

        $median = $this->calculatePercentile($intervals, 50);

        // Regularity classification
        $regularity = 'moderately_regular';
        if ($cv <= 0.35) {
            $regularity = 'highly_regular'; // Consumable/replenishment candidate
        } elseif ($cv > 0.80) {
            $regularity = 'irregular_or_project_based';
        }

        // Calibrated adaptive winback multiplier M in [2.0, 3.2] based on cv
        $adaptiveMultiplier = 2.0;
        if ($cv > 0.35) {
            $adaptiveMultiplier = min(3.2, round(2.0 + (1.2 * min(1.0, ($cv - 0.35) / 0.45)), 2));
        }

        $latestOrderDate = Carbon::parse($episodes->last()['latest_order_at']);
        $expectedNextOrderAt = $latestOrderDate->copy()->addDays((int) round($median));
        $winbackOverdueDays = (int) round($median * $adaptiveMultiplier);

        return [
            'has_cadence'                 => true,
            'evidence_level'              => 'qualified_three_plus_episodes',
            'confidence'                  => 'high',
            'episode_count'               => $episodeCount,
            'interval_count'              => $intervalCount,
            'intervals_days'              => $intervals,
            'median_interval_days'        => (int) round($median),
            'mean_interval_days'          => round($mean, 1),
            'std_dev_days'                => round($stdDev, 1),
            'variance_cv'                 => $cv,
            'cohort_benchmark_used'       => false,
            'regularity'                  => $regularity,
            'expected_next_order_at'      => $expectedNextOrderAt->toIso8601String(),
            'adaptive_winback_multiplier' => $adaptiveMultiplier,
            'winback_overdue_days'        => $winbackOverdueDays,
        ];
    }

    /**
     * Calculate spend run rate contraction comparing trailing evaluation window vs baseline window.
     * Incorporates adaptive cadence windows, in-cycle purchasing suppression, and bulk order normalization.
     */
    protected function calculateAccountContraction(
        Collection $orders,
        Carbon $referenceDate,
        array $cadenceData = [],
        int $daysSinceLastOrder = 0,
        float $historicalAov = 0.0,
        float $outlierMultiplier = 2.2
    ): array {
        // 1. Dynamic cadence-scaled comparison windows
        $medianInterval = $cadenceData['median_interval_days'] ?? 45;
        $evalWindowDays = 90;
        $baselineWindowDays = 90;

        if (! empty($cadenceData['has_cadence']) && $medianInterval > 60) {
            $evalWindowDays = max(90, min(180, (int) round($medianInterval * 1.25)));
            $baselineWindowDays = $evalWindowDays;
        }

        $evalStart = $referenceDate->copy()->subDays($evalWindowDays);
        $baselineStart = $evalStart->copy()->subDays($baselineWindowDays);

        $evalOrdersColl = $orders->filter(function ($order) use ($evalStart, $referenceDate) {
            $d = Carbon::parse($order->order_date);
            return $d->betweenIncluded($evalStart, $referenceDate);
        });

        $baselineOrdersColl = $orders->filter(function ($order) use ($baselineStart, $evalStart) {
            $d = Carbon::parse($order->order_date);
            return $d->betweenIncluded($baselineStart, $evalStart);
        });

        // Expected run-rate spend based on historical AOV and expected order frequency
        $expectedOrdersInWindow = max(1.0, round($baselineWindowDays / max(1, $medianInterval), 2));
        $expectedQuarterlySpend = round($historicalAov * $expectedOrdersInWindow, 2);

        // 2. In-cycle check: if account is within expected cadence/variance,
        // it is not contracting (simply between recurring cycle orders).
        $isInCycle = false;
        if (! empty($cadenceData['has_cadence'])) {
            $cadenceLimit = (int) round($medianInterval * 1.25);
            $isIrregular = ($cadenceData['regularity'] ?? '') === 'irregular_or_project_based';
            if ($isIrregular) {
                $meanInterval = (float) ($cadenceData['mean_interval_days'] ?? $medianInterval);
                $stdDev = (float) ($cadenceData['std_dev_days'] ?? ($medianInterval * 0.5));
                $cadenceLimit = max($cadenceLimit, (int) round($meanInterval + $stdDev));
            }

            if ($daysSinceLastOrder <= $cadenceLimit) {
                if ($evalOrdersColl->isEmpty() || ($isIrregular && $evalOrdersColl->count() < $expectedOrdersInWindow)) {
                    $isInCycle = true;
                }
            }
        }

        // 3. Outlier bulk order identification & normalization
        $outlierThreshold = max(100.0, $historicalAov * $outlierMultiplier);
        $outlierOrders = [];

        $rawEvalSpend = (float) $evalOrdersColl->sum('net_revenue');
        $rawBaselineSpend = (float) $baselineOrdersColl->sum('net_revenue');

        $normalizedBaselineSpend = 0.0;
        foreach ($baselineOrdersColl as $order) {
            $amt = (float) $order->net_revenue;
            if ($amt > $outlierThreshold && $historicalAov > 0) {
                $cappedAmt = round($historicalAov * 1.5, 2);
                $normalizedBaselineSpend += $cappedAmt;
                $outlierOrders[] = [
                    'order_date'        => Carbon::parse($order->order_date)->toDateString(),
                    'original_amount'   => round($amt, 2),
                    'normalized_amount' => $cappedAmt,
                    'aov_ratio'         => round($amt / max(1.0, $historicalAov), 2),
                    'period'            => 'baseline',
                    'reason'            => "Non-recurring bulk order exceeds {$outlierMultiplier}x historical AOV; normalized for run-rate baseline comparison",
                ];
            } else {
                $normalizedBaselineSpend += $amt;
            }
        }

        // Check for clustered baseline orders (orders placed within <= 14 days of each other)
        $sortedBaseline = $baselineOrdersColl->sortBy('order_date')->values();
        $hasClusteredOrders = false;
        for ($i = 1; $i < $sortedBaseline->count(); $i++) {
            $pDate = Carbon::parse($sortedBaseline[$i - 1]->order_date);
            $cDate = Carbon::parse($sortedBaseline[$i]->order_date);
            if ($pDate->diffInDays($cDate) <= 14) {
                $hasClusteredOrders = true;
                break;
            }
        }

        if ($hasClusteredOrders && $normalizedBaselineSpend > ($expectedQuarterlySpend * 1.25) && $expectedQuarterlySpend > 0) {
            $originalClustered = $normalizedBaselineSpend;
            $normalizedBaselineSpend = $expectedQuarterlySpend;
            $outlierOrders[] = [
                'order_date'        => 'Baseline Window Cluster',
                'original_amount'   => round($originalClustered, 2),
                'normalized_amount' => round($expectedQuarterlySpend, 2),
                'aov_ratio'         => round($originalClustered / max(1.0, $expectedQuarterlySpend), 2),
                'period'            => 'baseline',
                'reason'            => "Clustered orders within <= 14 days produced unrepresentative baseline spend (\$" . number_format($originalClustered, 2) . "); normalized to account expected run-rate (\$" . number_format($expectedQuarterlySpend, 2) . ").",
            ];
        }

        $normalizedEvalSpend = 0.0;
        foreach ($evalOrdersColl as $order) {
            $amt = (float) $order->net_revenue;
            if ($amt > $outlierThreshold && $historicalAov > 0) {
                $cappedAmt = round($historicalAov * 1.5, 2);
                $normalizedEvalSpend += $cappedAmt;
                $outlierOrders[] = [
                    'order_date'        => Carbon::parse($order->order_date)->toDateString(),
                    'original_amount'   => round($amt, 2),
                    'normalized_amount' => $cappedAmt,
                    'aov_ratio'         => round($amt / max(1.0, $historicalAov), 2),
                    'period'            => 'evaluation',
                    'reason'            => "Non-recurring bulk order exceeds {$outlierMultiplier}x historical AOV; normalized for run-rate evaluation comparison",
                ];
            } else {
                $normalizedEvalSpend += $amt;
            }
        }

        $effectiveBaselineSpend = ! empty($outlierOrders) ? $normalizedBaselineSpend : $rawBaselineSpend;
        $effectiveEvalSpend = ! empty($outlierOrders) ? $normalizedEvalSpend : $rawEvalSpend;

        $contractionPct = 0.0;
        $revenueDrop = 0.0;
        $isContracting = false;

        if ($effectiveBaselineSpend > 0) {
            $revenueDrop = max(0, $effectiveBaselineSpend - $effectiveEvalSpend);
            $contractionPct = round(($revenueDrop / $effectiveBaselineSpend) * 100, 2);
            // Contracted if drop >= 25% AND account is NOT in-cycle AND baseline had genuine recurring activity
            $isContracting = ($contractionPct >= 25.0) && (! $isInCycle) && ($baselineOrdersColl->count() >= 1);
        }

        return [
            'baseline_spend'            => round($effectiveBaselineSpend, 2),
            'raw_baseline_spend'        => round($rawBaselineSpend, 2),
            'baseline_orders'           => $baselineOrdersColl->count(),
            'eval_spend'                => round($effectiveEvalSpend, 2),
            'raw_eval_spend'            => round($rawEvalSpend, 2),
            'eval_orders'               => $evalOrdersColl->count(),
            'revenue_drop'              => round($revenueDrop, 2),
            'contraction_pct'           => $contractionPct,
            'is_contracting'            => $isContracting,
            'is_in_cycle'               => $isInCycle,
            'eval_window_days'          => $evalWindowDays,
            'baseline_days'             => $baselineWindowDays,
            'outlier_orders'            => $outlierOrders,
            'has_outlier_normalization' => ! empty($outlierOrders),
        ];
    }

    /**
     * Calculate historical monthly purchase seasonality.
     */
    protected function calculateAccountSeasonality(Collection $orders, Carbon $referenceDate): array
    {
        $currentMonth = (int) $referenceDate->format('n'); // 1 to 12
        $monthCounts = array_fill(1, 12, 0);

        foreach ($orders as $order) {
            $m = (int) Carbon::parse($order->order_date)->format('n');
            $monthCounts[$m]++;
        }

        $totalHistoricalOrders = $orders->count();
        $ordersInCurrentMonth = $monthCounts[$currentMonth];

        // Off-season check: requires at least 365 days of history to establish genuine seasonality.
        $isHistoricallyOffSeason = false;
        if ($totalHistoricalOrders >= 4) {
            $firstDate = Carbon::parse($orders->first()->order_date);
            $lastDate = Carbon::parse($orders->last()->order_date);
            if ($firstDate->diffInDays($lastDate) >= 365 && $ordersInCurrentMonth === 0) {
                $isHistoricallyOffSeason = true;
            }
        }

        return [
            'current_month'              => $currentMonth,
            'current_month_order_count'  => $ordersInCurrentMonth,
            'monthly_distribution'       => $monthCounts,
            'is_historically_off_season' => $isHistoricallyOffSeason,
        ];
    }

    /**
     * Clear in-memory analytics cache.
     */
    public function clearCache(): void
    {
        $this->baselinesCache = [];
        $this->accountProfileCache = [];
    }

    /**
     * Calculate cohort inter-purchase intervals across all repeat customers.
     */
    protected function calculateCohortIntervals(Client $client): array
    {
        // Query repeat customers
        $repeatCustomerHashes = CommerceCustomerPurchaseFact::where('client_id', $client->id)
            ->where('lifetime_valid_order_count', '>=', 2)
            ->pluck('customer_identity_hash')
            ->filter()
            ->all();

        if (empty($repeatCustomerHashes)) {
            return ['median' => 30, 'q1' => 14, 'q3' => 60, 'iqr' => 46];
        }

        $orders = CommerceOrder::where('client_id', $client->id)
            ->whereIn('customer_identity_hash', $repeatCustomerHashes)
            ->where('is_valid', true)
            ->orderBy('customer_identity_hash')
            ->orderBy('order_date', 'asc')
            ->get(['customer_identity_hash', 'order_date']);

        $grouped = $orders->groupBy('customer_identity_hash');
        $allIntervals = [];

        foreach ($grouped as $hash => $custOrders) {
            $count = $custOrders->count();
            if ($count < 2) {
                continue;
            }
            for ($i = 1; $i < $count; $i++) {
                $p = Carbon::parse($custOrders[$i - 1]->order_date);
                $c = Carbon::parse($custOrders[$i]->order_date);
                $diff = max(1, (int) $p->diffInDays($c));
                $allIntervals[] = $diff;
            }
        }

        if (empty($allIntervals)) {
            return ['median' => 30, 'q1' => 14, 'q3' => 60, 'iqr' => 46];
        }

        sort($allIntervals);

        $median = $this->calculatePercentile($allIntervals, 50);
        $q1 = $this->calculatePercentile($allIntervals, 25);
        $q3 = $this->calculatePercentile($allIntervals, 75);
        $iqr = max(1, $q3 - $q1);

        return [
            'median' => (int) round($median),
            'q1'     => (int) round($q1),
            'q3'     => (int) round($q3),
            'iqr'    => (int) round($iqr),
        ];
    }

    /**
     * Compute percentile from a sorted array of numbers using linear interpolation.
     */
    public function calculatePercentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }
        if ($count === 1) {
            return (float) $sortedValues[0];
        }

        $rank = ($percentile / 100) * ($count - 1);
        $lowerIndex = (int) floor($rank);
        $upperIndex = (int) ceil($rank);
        $fraction = $rank - $lowerIndex;

        $lowerVal = (float) $sortedValues[$lowerIndex];
        $upperVal = (float) $sortedValues[$upperIndex];

        return $lowerVal + ($fraction * ($upperVal - $lowerVal));
    }
}
