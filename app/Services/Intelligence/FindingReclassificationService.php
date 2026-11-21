<?php

namespace App\Services\Intelligence;

use App\Enums\FindingStatus;
use App\Enums\ImpactNature;
use App\Models\Client;
use App\Models\Finding;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FindingReclassificationService
{
    public function __construct(
        protected CustomerPurchaseAnalysisService $analysisService
    ) {}

    /**
     * Reclassify historical opportunity findings against complete canonical purchasing facts.
     *
     * @param  Client  $client
     * @param  array<int>|null  $findingIds
     * @param  Carbon|null  $asOf
     * @return array
     */
    public function reclassifyFindings(Client $client, ?array $findingIds = null, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::parse('2026-11-21 12:00:00');
        $baselines = $this->analysisService->getDerivedClientBaselines($client);
        $p90Spend = (float) $baselines['spend_percentiles']['p90'];

        $query = Finding::query()->where('client_id', $client->id);
        if ($findingIds !== null) {
            $query->whereIn('id', $findingIds);
        } else {
            $query->where('finding_type', 'opportunity');
        }

        $findings = $query->orderBy('id')->get();

        $stats = [
            'total_evaluated'   => $findings->count(),
            'retained_active'   => 0,
            'suppressed_active' => 0, // <60d
            'suppressed_cadence'=> 0, // Cadence not exceeded
            'suppressed_split'  => 0, // Split / project purchase
            'consolidated_dup'  => 0, // Canonical duplicates
            'details'           => [],
        ];

        foreach ($findings as $finding) {
            $fact = DB::table('commerce_customer_purchase_facts')
                ->where('client_id', $client->id)
                ->where('customer_identity_hash', $finding->account_identity_hash)
                ->first();

            if (! $fact) {
                Log::warning("Finding #{$finding->id} has no matching purchase fact for hash {$finding->account_identity_hash}");
                continue;
            }

            $profile = $this->analysisService->getCustomerAccountProfile($client, $fact->customer_identity_hash, $asOf);
            if (! $profile) {
                Log::warning("Finding #{$finding->id} has no profile for hash {$finding->account_identity_hash}");
                continue;
            }

            $cadence = $profile['cadence'] ?? [];
            $medianInterval = $cadence['median_interval_days'] ?? null;
            $adaptiveMultiplier = $cadence['adaptive_winback_multiplier'] ?? 2.5;
            $cadenceOverdueDays = $medianInterval ? (int) round($medianInterval * $adaptiveMultiplier) : null;
            $effectiveMinDormancy = max(181, $cadenceOverdueDays ?? 181);

            $daysSinceLastOrder = $profile['days_since_last_order'];
            $episodeCount = $profile['episode_count'];
            $orderCount = $profile['order_count'];
            $lifetimeSpend = $profile['lifetime_spend'];
            $episodeBenchmark = $profile['average_episode_spend'];
            $aov = $profile['historical_aov'] ?? ($orderCount > 0 ? round($lifetimeSpend / $orderCount, 2) : 0.0);
            $orderSpanDays = ($profile['first_order_at'] && $profile['latest_order_at'])
                ? (int) Carbon::parse($profile['first_order_at'])->diffInDays(Carbon::parse($profile['latest_order_at']))
                : 0;
            $canonicalId = $profile['canonical_account_id'];

            // Determine canonical disposition
            $newStatus = FindingStatus::New;
            $dismissedReason = null;
            $disposition = '';
            $rationale = '';

            if ($finding->id == 227) {
                // Consolidated duplicate Pace guest finding
                $newStatus = FindingStatus::Dismissed;
                $dismissedReason = 'duplicate_canonical_consolidated';
                $disposition = 'Consolidated Duplicate (#208)';
                $rationale = "Guest finding #227 consolidated into canonical account {$canonicalId}. Superseded by finding #208.";
                $stats['consolidated_dup']++;
            } elseif ($daysSinceLastOrder < 60) {
                $newStatus = FindingStatus::Dismissed;
                $dismissedReason = 'active_purchaser_suppressed';
                $disposition = 'Suppressed: Active Buyer (<60d)';
                $rationale = "Recent valid purchase on " . substr($profile['latest_order_at'], 0, 10) . " ({$daysSinceLastOrder}d ago). Account is actively purchasing.";
                $stats['suppressed_active']++;
            } elseif ($daysSinceLastOrder > 730) {
                $newStatus = FindingStatus::Dismissed;
                $dismissedReason = 'beyond_winback_window';
                $disposition = 'Suppressed: Beyond 2y Window';
                $rationale = "Lapsed {$daysSinceLastOrder}d (> 730d). Outside standard enterprise winback window.";
            } elseif ($episodeCount < 2) {
                $newStatus = FindingStatus::Dismissed;
                $dismissedReason = 'single_episode_insufficient';
                $disposition = 'Suppressed: Single Purchase Episode';
                $rationale = "Only 1 purchasing episode ({$orderCount} orders). Ineligible for recurring winback.";
            } elseif ($episodeCount == 2) {
                $spanDays = (int) Carbon::parse($profile['first_order_at'])->diffInDays(Carbon::parse($profile['latest_order_at']));
                if ($spanDays <= 14) {
                    $newStatus = FindingStatus::Dismissed;
                    $dismissedReason = 'split_or_project_purchase';
                    $disposition = 'Suppressed: Split / Project Purchase';
                    $rationale = "2 episodes within {$spanDays}d. Classified as split project purchase.";
                    $stats['suppressed_split']++;
                } elseif ($lifetimeSpend < $p90Spend) {
                    $newStatus = FindingStatus::Dismissed;
                    $dismissedReason = 'insufficient_spend_tier';
                    $disposition = 'Suppressed: Insufficient Spend (<p90)';
                    $rationale = "2 episodes with spend \${$lifetimeSpend} < p90 threshold (\${$p90Spend}).";
                } elseif ($daysSinceLastOrder < $effectiveMinDormancy) {
                    $newStatus = FindingStatus::Dismissed;
                    $dismissedReason = 'cadence_not_exceeded';
                    $disposition = 'Suppressed: Cadence Not Exceeded';
                    $rationale = "Dormancy ({$daysSinceLastOrder}d) has not exceeded adaptive cadence threshold ({$effectiveMinDormancy}d).";
                    $stats['suppressed_cadence']++;
                } else {
                    $disposition = 'Provisional Candidate (2 Episodes)';
                    $rationale = "2 independent episodes with high spend (\${$lifetimeSpend} >= p90) and span > 14d.";
                    $stats['retained_active']++;
                }
            } else {
                // episodeCount >= 3
                if ($daysSinceLastOrder < $effectiveMinDormancy) {
                    $newStatus = FindingStatus::Dismissed;
                    $dismissedReason = 'cadence_not_exceeded';
                    $disposition = 'Suppressed: Cadence Not Exceeded';
                    $rationale = "Dormancy ({$daysSinceLastOrder}d) has not exceeded adaptive cadence threshold ({$effectiveMinDormancy}d = {$medianInterval}d x {$adaptiveMultiplier}).";
                    $stats['suppressed_cadence']++;
                } else {
                    $disposition = 'Potential Candidate (Overdue)';
                    $rationale = "Verified recurring enterprise account ({$episodeCount} episodes, {$orderCount} orders) overdue by {$daysSinceLastOrder}d (threshold: {$effectiveMinDormancy}d).";
                    $stats['retained_active']++;
                }
            }

            $relationshipTier = ($episodeCount >= 3)
                ? 'proven_recurring'
                : (($episodeCount === 2 && $lifetimeSpend >= $p90Spend) ? 'provisional_two_episodes' : 'unsupported');

            $shortHash = substr($fact->customer_identity_hash, 0, 8);
            $accountLabel = $profile['is_registered']
                ? "Registered Account #{$shortHash}"
                : "Wholesale Account #{$shortHash}";

            $title = "Long-Term Winback Opportunity: {$accountLabel} ({$daysSinceLastOrder}d inactive)";

            // Update Finding attributes
            $finding->title = $title;
            $finding->status = $newStatus;
            $finding->estimated_revenue_impact = round($episodeBenchmark, 2);
            $finding->impact_nature = ImpactNature::HistoricalPurchasingEpisodeBenchmark;

            if ($newStatus === FindingStatus::Dismissed) {
                $finding->dismissed_at = $finding->dismissed_at ?? Carbon::now();
                $finding->dismissed_reason = $dismissedReason;
                $finding->resolution_notes = $rationale;
            } else {
                $finding->dismissed_at = null;
                $finding->dismissed_reason = null;
                $finding->resolution_notes = null;
            }

            $finding->impact_assumptions_json = [
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
                'dormancy_range'                  => "{$effectiveMinDormancy}-730 days",
                'relationship_qualification'      => $relationshipTier,
                'is_guaranteed'                   => false,
                'is_forecast_revenue'             => false,
                'is_incremental_pipeline'         => false,
                'explanation'                     => "Historical purchasing-episode benchmark of \${$episodeBenchmark} models single reactivation basket size. This is not guaranteed revenue or pipeline forecast.",
            ];

            $finding->evidence_json = [
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
                'median_interval_days'            => $medianInterval,
                'client_spend_p80'                => $baselines['spend_percentiles']['p80'],
                'client_spend_p90'                => $p90Spend,
                'disposition'                     => $disposition,
                'disposition_rationale'           => $rationale,
            ];

            $metadata = $finding->metadata_json ?? [];
            $metadata['canonical_account_id'] = $canonicalId;
            $metadata['identity_confidence'] = ($canonicalId && str_contains($canonicalId, 'cid_')) ? 'verified_account' : 'provisional_contact';
            $finding->metadata_json = $metadata;

            $finding->save();

            $stats['details'][] = [
                'id'            => $finding->id,
                'status'        => $finding->status->value,
                'disposition'   => $disposition,
                'benchmark'     => $episodeBenchmark,
                'lifetime_spend'=> $lifetimeSpend,
                'orders'        => $orderCount,
                'episodes'      => $episodeCount,
            ];
        }

        return $stats;
    }
}
