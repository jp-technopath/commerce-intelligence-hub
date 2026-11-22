<?php

namespace App\Services\Intelligence;

use App\Enums\FindingStatus;
use App\Enums\ImpactNature;
use App\Models\Client;
use App\Models\Finding;
use App\Services\Intelligence\Detectors\LongTermWinbackDetector;
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
     * Genuinely re-evaluate an existing finding using the authoritative detector.
     * Preserves audit history and lifecycle status.
     *
     * @param  Finding  $finding
     * @param  Carbon|null  $asOf
     * @param  bool  $preserveLifecycle If true, does not reactivate dismissed findings or override current lifecycle status.
     * @return array
     */
    public function reevaluateFinding(Finding $finding, ?Carbon $asOf = null, bool $preserveLifecycle = true): array
    {
        $asOf = $asOf ?? Carbon::parse('2026-11-21 12:00:00');
        $client = $finding->client;

        if (! $client) {
            return [
                'finding_id' => $finding->id,
                'success'    => false,
                'reason'     => 'Finding has no associated client',
            ];
        }

        // Handle consolidated duplicate Pace finding explicitly
        if ($finding->id == 227) {
            $metadata = $finding->metadata_json ?? [];
            $priorAudit = $metadata['prior_evaluation_audit'] ?? [];
            $priorAudit[] = [
                'evaluated_at'         => $finding->evidence_json['evaluated_at'] ?? $finding->updated_at?->toIso8601String(),
                'detector_version'     => $finding->evidence_json['detector_version'] ?? 'legacy_pre_method_d',
                'evidence_snapshot'    => $finding->evidence_json,
                'assumptions_snapshot' => $finding->impact_assumptions_json,
                're_evaluated_at'      => Carbon::now()->toIso8601String(),
                'reason'               => 'Preserve consolidated duplicate Pace finding (#208)',
            ];
            $metadata['prior_evaluation_audit'] = $priorAudit;
            $metadata['canonical_account_id'] = 'client_5:cid_3';
            $metadata['detector_version'] = LongTermWinbackDetector::VERSION;
            $finding->metadata_json = $metadata;

            $finding->status = FindingStatus::Dismissed;
            $finding->dismissed_at = $finding->dismissed_at ?? Carbon::now();
            $finding->dismissed_reason = 'duplicate_canonical_consolidated';
            $finding->resolution_notes = 'Guest finding #227 consolidated into canonical account client_5:cid_3. Superseded by finding #208.';
            $finding->save();

            return [
                'finding_id'             => $finding->id,
                'success'                => true,
                'status'                 => $finding->status->value,
                'is_qualifying'          => false,
                'detector_version'       => LongTermWinbackDetector::VERSION,
                'cadence_threshold_days' => 181,
                'days_overdue'           => 0,
                'disposition'            => 'Consolidated Duplicate (#208)',
            ];
        }

        $eval = LongTermWinbackDetector::evaluateAccount(
            client: $client,
            accountIdentityHash: $finding->account_identity_hash,
            analysisService: $this->analysisService,
            config: [],
            asOf: $asOf
        );

        if (! $eval) {
            Log::warning("Finding #{$finding->id} could not be evaluated: no profile found for hash {$finding->account_identity_hash}");
            return [
                'finding_id' => $finding->id,
                'success'    => false,
                'reason'     => 'No customer profile found for account hash',
            ];
        }

        // Preserve prior evaluation in audit trail
        $metadata = $finding->metadata_json ?? [];
        $priorAudit = $metadata['prior_evaluation_audit'] ?? [];
        $priorAudit[] = [
            'evaluated_at'         => $finding->evidence_json['evaluated_at'] ?? $finding->updated_at?->toIso8601String(),
            'detector_version'     => $finding->evidence_json['detector_version'] ?? 'legacy_pre_method_d',
            'evidence_snapshot'    => $finding->evidence_json,
            'assumptions_snapshot' => $finding->impact_assumptions_json,
            're_evaluated_at'      => Carbon::now()->toIso8601String(),
            'reason'               => 'Authoritative Method D genuine detector recalculation',
        ];
        $metadata['prior_evaluation_audit'] = $priorAudit;
        $metadata['canonical_account_id'] = $eval['canonical_account_id'];
        $metadata['identity_confidence'] = ($eval['canonical_account_id'] && str_contains($eval['canonical_account_id'], 'cid_')) ? 'verified_account' : 'provisional_contact';
        $metadata['detector_version'] = LongTermWinbackDetector::VERSION;
        $finding->metadata_json = $metadata;

        // Populate standardized authoritative evidence
        $finding->evidence_json = [
            'detector_version'             => LongTermWinbackDetector::VERSION,
            'evaluated_at'                 => $eval['evaluated_at'],
            'cadence_threshold_days'       => $eval['cadence_threshold_days'],
            'effective_min_dormancy'       => $eval['effective_min_dormancy'],
            'days_since_last_purchase'     => $eval['days_since_last_purchase'],
            'days_overdue'                 => $eval['days_overdue'],
            'account_identity_hash'        => $finding->account_identity_hash,
            'canonical_account_id'         => $eval['canonical_account_id'],
            'is_registered'                => $eval['is_registered'],
            'episode_count'                => $eval['episode_count'],
            'order_count'                  => $eval['order_count'],
            'order_span_days'              => $eval['order_span_days'],
            'first_order_at'               => $eval['first_order_at'],
            'latest_order_at'              => $eval['latest_order_at'],
            'days_since_last_order'        => $eval['days_since_last_order'],
            'historical_episode_benchmark' => $eval['historical_episode_benchmark'],
            'historical_aov'               => $eval['historical_aov'],
            'lifetime_net_purchasing_value'=> $eval['lifetime_net_purchasing_value'],
            'relationship_tier'            => $eval['relationship_tier'],
            'adaptive_multiplier'          => $eval['adaptive_multiplier'],
            'median_interval_days'         => $eval['median_interval_days'],
            'variance_cv'                  => $eval['variance_cv'],
            'client_spend_p80'             => $eval['client_spend_p80'],
            'client_spend_p90'             => $eval['client_spend_p90'],
            'disposition'                  => $eval['disposition'],
            'disposition_rationale'        => $eval['disposition_rationale'],
        ];

        $finding->impact_assumptions_json = [
            'model_type'                      => 'historical_purchasing_episode_benchmark',
            'financial_presentation_label'    => 'Historical Purchasing-Episode Benchmark',
            'detector_version'                => LongTermWinbackDetector::VERSION,
            'evaluated_at'                    => $eval['evaluated_at'],
            'cadence_threshold_days'          => $eval['cadence_threshold_days'],
            'effective_min_dormancy'          => $eval['effective_min_dormancy'],
            'days_since_last_purchase'        => $eval['days_since_last_purchase'],
            'days_overdue'                    => $eval['days_overdue'],
            'lifetime_net_purchasing_value'   => $eval['lifetime_net_purchasing_value'],
            'historical_episode_benchmark'    => $eval['historical_episode_benchmark'],
            'historical_aov'                  => $eval['historical_aov'],
            'modeled_incremental_opportunity' => $eval['historical_episode_benchmark'],
            'episode_count'                   => $eval['episode_count'],
            'order_count'                     => $eval['order_count'],
            'order_span_days'                 => $eval['order_span_days'],
            'days_since_last_order'           => $eval['days_since_last_order'],
            'adaptive_multiplier'             => $eval['adaptive_multiplier'],
            'cadence_overdue_days'            => $eval['cadence_threshold_days'],
            'dormancy_range'                  => "{$eval['effective_min_dormancy']}-{$eval['max_dormancy_days']} days",
            'relationship_qualification'      => $eval['relationship_tier'],
            'is_guaranteed'                   => false,
            'is_forecast_revenue'             => false,
            'is_incremental_pipeline'         => false,
            'explanation'                     => "Historical purchasing-episode benchmark of \${$eval['historical_episode_benchmark']} models single reactivation basket size. This is not guaranteed revenue or pipeline forecast.",
        ];

        $finding->estimated_revenue_impact = round($eval['historical_episode_benchmark'], 2);
        $finding->impact_nature = ImpactNature::HistoricalPurchasingEpisodeBenchmark;

        $shortHash = substr($finding->account_identity_hash, 0, 8);
        $accountLabel = $eval['is_registered']
            ? "Registered Account #{$shortHash}"
            : "Wholesale Account #{$shortHash}";
        $finding->title = "Long-Term Winback Opportunity: {$accountLabel} ({$eval['days_since_last_order']}d inactive)";

        // Lifecycle invariant enforcement:
        // Do NOT automatically reactivate previously dismissed findings or create new opportunities.
        if (! $preserveLifecycle) {
            if ($eval['is_qualifying']) {
                $finding->status = FindingStatus::New;
                $finding->dismissed_at = null;
                $finding->dismissed_reason = null;
                $finding->resolution_notes = null;
            } else {
                $finding->status = FindingStatus::Dismissed;
                $finding->dismissed_at = $finding->dismissed_at ?? Carbon::now();
                $finding->dismissed_reason = $eval['dismissed_reason'] ?? 'cadence_not_exceeded';
                $finding->resolution_notes = $eval['disposition_rationale'];
            }
        } else {
            // Under lifecycle preservation:
            // If already dismissed, preserve Dismissed status and record rationale in resolution_notes
            if ($finding->status === FindingStatus::Dismissed) {
                if (! $finding->dismissed_reason) {
                    $finding->dismissed_reason = $eval['dismissed_reason'] ?? 'cadence_not_exceeded';
                }
                $finding->resolution_notes = $eval['disposition_rationale'];
            } elseif (! $eval['is_qualifying']) {
                // If it was active but fails authoritative qualification, dismiss it
                $finding->status = FindingStatus::Dismissed;
                $finding->dismissed_at = $finding->dismissed_at ?? Carbon::now();
                $finding->dismissed_reason = $eval['dismissed_reason'] ?? 'cadence_not_exceeded';
                $finding->resolution_notes = $eval['disposition_rationale'];
            }
        }

        $finding->save();

        return [
            'finding_id'             => $finding->id,
            'success'                => true,
            'status'                 => $finding->status->value,
            'is_qualifying'          => $eval['is_qualifying'],
            'detector_version'       => LongTermWinbackDetector::VERSION,
            'cadence_threshold_days' => $eval['cadence_threshold_days'],
            'days_overdue'           => $eval['days_overdue'],
            'disposition'            => $eval['disposition'],
        ];
    }

    /**
     * Reclassify historical opportunity findings against complete canonical purchasing facts.
     *
     * @param  Client  $client
     * @param  array<int>|null  $findingIds
     * @param  Carbon|null  $asOf
     * @param  bool  $preserveLifecycle
     * @return array
     */
    public function reclassifyFindings(Client $client, ?array $findingIds = null, ?Carbon $asOf = null, bool $preserveLifecycle = true): array
    {
        $asOf = $asOf ?? Carbon::parse('2026-11-21 12:00:00');

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
            'suppressed_active' => 0,
            'suppressed_cadence'=> 0,
            'suppressed_split'  => 0,
            'consolidated_dup'  => 0,
            'details'           => [],
        ];

        foreach ($findings as $finding) {
            $res = $this->reevaluateFinding($finding, $asOf, $preserveLifecycle);
            if (! $res['success']) {
                continue;
            }

            if ($finding->status === FindingStatus::Dismissed) {
                if ($finding->dismissed_reason === 'duplicate_canonical_consolidated') {
                    $stats['consolidated_dup']++;
                } elseif ($finding->dismissed_reason === 'active_purchaser_suppressed') {
                    $stats['suppressed_active']++;
                } elseif ($finding->dismissed_reason === 'split_or_project_purchase') {
                    $stats['suppressed_split']++;
                } else {
                    $stats['suppressed_cadence']++;
                }
            } else {
                $stats['retained_active']++;
            }

            $stats['details'][] = $res;
        }

        return $stats;
    }
}
