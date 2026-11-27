<?php

namespace App\Console\Commands;

use App\Enums\FindingStatus;
use App\Models\BehavioralMetric;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\Finding;
use App\Services\Intelligence\DataQualification\FindingFreshnessService;
use App\Services\Intelligence\FindingTypeRegistry;
use App\Services\Metrics\MetricsDiagnosticService;
use App\Services\Metrics\RevenueReconciler;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles production findings for pillar classification, duplicate incident
 * consolidation, fabricated static-signal invalidation, and source-grounded
 * freshness metadata without ever deleting rows or reactivating manager dismissals.
 */
class ReconcileIntelligenceIntegrityCommand extends Command
{
    protected $signature = 'forge:reconcile-intelligence-integrity
                            {--client= : Optional client ID filter}
                            {--dry-run : Preview changes without modifying the database (default when --apply is omitted)}
                            {--apply : Apply verified changes inside an immutable audit transaction}
                            {--format=table : Output format: table or json}';

    protected $description = 'Audit and reconcile finding pillars, duplicates, fabricated static signals, and source freshness with immutable audit history';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $isDryRun = ! $apply || (bool) $this->option('dry-run');
        $clientFilter = $this->option('client') ? (int) $this->option('client') : null;
        $format = $this->option('format') ?: 'table';

        if ($apply && $this->option('dry-run')) {
            $this->error('Specify either --dry-run or --apply, not both.');
            return self::FAILURE;
        }

        $query = Finding::with(['client', 'pmWorkItem'])
            ->where('is_customer_visible', true)
            ->orderBy('client_id')
            ->orderBy('id');

        if ($clientFilter) {
            $query->where('client_id', $clientFilter);
        }

        $findings = $query->get();
        $freshnessService = new FindingFreshnessService();
        $reconciler = new RevenueReconciler();
        $diagService = new MetricsDiagnosticService();

        $openFindingsByClientAndType = $findings
            ->filter(fn (Finding $f) => in_array($f->status?->value ?? (string) $f->status, FindingStatus::openValues(), true))
            ->groupBy(fn (Finding $f) => $f->client_id . '::' . $f->finding_type);

        $plan = [];
        $summary = [
            'mode'                        => $isDryRun ? 'dry_run' : 'apply',
            'total_inspected'             => $findings->count(),
            'unchanged'                   => 0,
            'reconciled_active_findings'  => 0,
            'pillar_updates'              => 0,
            'consolidated_duplicates'     => 0,
            'invalidated_fabricated'      => 0,
            'evidence_freshness_updates'  => 0,
            'pm_safeguard_blocks'         => 0,
            'before_pillars'              => [],
            'after_pillars'               => [],
        ];

        $clients = Client::all()->keyBy('id');

        foreach ($findings as $finding) {
            $statusVal = $finding->status?->value ?? (string) $finding->status;
            $pillarVal = $finding->finding_pillar?->value ?? ($finding->finding_pillar ?: 'NULL');

            if (in_array($statusVal, FindingStatus::openValues(), true)) {
                $summary['before_pillars'][$finding->client_id][$pillarVal] = ($summary['before_pillars'][$finding->client_id][$pillarVal] ?? 0) + 1;
            }

            // Never touch findings already resolved, dismissed, or ignored by managers
            if (! in_array($statusVal, FindingStatus::openValues(), true)) {
                $summary['unchanged']++;
                continue;
            }

            $client = $clients->get($finding->client_id);
            $meta = is_array($finding->metadata_json) ? $finding->metadata_json : [];
            $ev = is_array($finding->evidence_json) ? $finding->evidence_json : [];

            $action = null;

            // Safeguard: never automatically invalidate/consolidate an item already linked to Jira or approved
            $hasPmOrApproval = ! empty($finding->pm_work_item_id) || ! empty($finding->approved_by_user_id);

            // 1. Detect fabricated static Signal #3 (attribution.campaign_tagging with hardcoded 4658.47)
            $isStaticCampaignStub = $finding->finding_type === 'attribution.campaign_tagging'
                && (abs((float) ($ev['klaviyo_revenue'] ?? 0) - 4658.47) < 0.01
                    || abs((float) ($meta['primary_signal']['previous_value'] ?? 0) - 4658.47) < 0.01);

            if ($isStaticCampaignStub) {
                $klaviyoVarianceSibling = ($openFindingsByClientAndType->get($finding->client_id . '::ga4_klaviyo_attribution_variance') ?? collect())->first();

                if ($hasPmOrApproval) {
                    $summary['pm_safeguard_blocks']++;
                    $action = $this->buildManualReviewAction($finding, 'Flagged static $4,658.47 campaign_tagging stub has approval or Jira link — requires human review.');
                } else {
                    $relatedId = $klaviyoVarianceSibling?->id;
                    $reason = $relatedId
                        ? "Invalidated unsupported intelligence: originated from hardcoded static $4,658.47 production signal without link-level UTM evidence. Separate rule-evaluated finding #{$relatedId} ($4,846.63) tracks actual Klaviyo vs GA4 variance independently without absorbing fabricated evidence."
                        : 'Invalidated unsupported intelligence: fabricated static hardcoded $4,658.47 Signal #3 emitted across clients without tenant-scoped link UTM inspection evidence.';
                    $resolutionNote = $relatedId
                        ? "Invalidated fabricated static $4,658.47 Signal #3 without link-level UTM evidence (kept separate from rule-evaluated Klaviyo variance finding #{$relatedId}; historical record preserved)."
                        : 'Invalidated fabricated static $4,658.47 attribution.campaign_tagging signal unsupported by tenant link-level telemetry.';

                    $action = [
                        'finding_id'         => $finding->id,
                        'client_id'          => $finding->client_id,
                        'client_name'        => $client?->name ?? (string) $finding->client_id,
                        'finding_type'       => $finding->finding_type,
                        'action_type'        => 'invalidated_fabricated_signal',
                        'current_status'     => $statusVal,
                        'proposed_status'    => FindingStatus::Dismissed->value,
                        'current_pillar'     => $pillarVal,
                        'proposed_pillar'    => FindingTypeRegistry::classify($finding)['pillar_value'] ?? 'technical_health',
                        'superseded_by_id'   => null,
                        'related_finding_id' => $relatedId,
                        'reason'             => $reason,
                        'updates'            => [
                            'finding_pillar'   => FindingTypeRegistry::classify($finding)['pillar_value'] ?? 'technical_health',
                            'status'           => FindingStatus::Dismissed->value,
                            'dismissed_at'     => now(),
                            'dismissed_reason' => 'invalidated_fabricated_signal',
                            'resolution_notes' => $resolutionNote,
                        ],
                        'metadata_patch'     => [
                            'disposition_type'   => 'invalidated_unsupported_intelligence',
                            'related_finding_id' => $relatedId,
                        ],
                        'evidence_patch'     => null,
                    ];
                    $summary['invalidated_fabricated']++;
                }
            }

            // 2. Duplicate storewide Clarity script_error incidents (keep earliest/in-review parent, consolidate newer duplicates)
            if ($action === null && $finding->finding_type === 'technical.script_error') {
                $sameTypeGroup = ($openFindingsByClientAndType->get($finding->client_id . '::technical.script_error') ?? collect())
                    ->sortBy(fn (Finding $f) => [
                        in_array($f->status?->value ?? (string) $f->status, [FindingStatus::UnderReview->value, FindingStatus::Approved->value, FindingStatus::Assigned->value], true) ? 0 : 1,
                        $f->id,
                    ])
                    ->values();

                $primaryScriptFinding = $sameTypeGroup->first();
                if ($primaryScriptFinding && $primaryScriptFinding->id !== $finding->id) {
                    if ($hasPmOrApproval) {
                        $summary['pm_safeguard_blocks']++;
                        $action = $this->buildManualReviewAction($finding, "Duplicate technical.script_error of #{$primaryScriptFinding->id} has approval or Jira link — requires manual merge.");
                    } else {
                        $dupErrCount = (int) ($ev['error_count'] ?? 0);
                        $dupSessCount = (int) ($ev['clarity_sessions'] ?? $ev['monitored_sessions'] ?? 0);
                        $action = [
                            'finding_id'         => $finding->id,
                            'client_id'          => $finding->client_id,
                            'client_name'        => $client?->name ?? (string) $finding->client_id,
                            'finding_type'       => $finding->finding_type,
                            'action_type'        => 'consolidated_duplicate',
                            'current_status'     => $statusVal,
                            'proposed_status'    => FindingStatus::Dismissed->value,
                            'current_pillar'     => $pillarVal,
                            'proposed_pillar'    => 'technical_health',
                            'superseded_by_id'   => $primaryScriptFinding->id,
                            'related_finding_id' => $primaryScriptFinding->id,
                            'reason'             => "Duplicate episode of ongoing storewide Clarity script-error finding #{$primaryScriptFinding->id} (both originate from project-wide Clarity BehavioralMetric telemetry without page-level stack traces).",
                            'updates'            => [
                                'finding_pillar'   => 'technical_health',
                                'status'           => FindingStatus::Dismissed->value,
                                'dismissed_at'     => now(),
                                'dismissed_reason' => 'consolidated_duplicate',
                                'resolution_notes' => "Consolidated duplicate Clarity observation ({$dupErrCount} errors / {$dupSessCount} monitored sessions) into ongoing finding #{$primaryScriptFinding->id}.",
                            ],
                            'metadata_patch'     => [
                                'disposition_type'         => 'consolidated_duplicate',
                                'superseded_by_finding_id' => $primaryScriptFinding->id,
                            ],
                            'evidence_patch'     => null,
                        ];
                        $summary['consolidated_duplicates']++;
                    }
                } else {
                    // Primary storewide Clarity finding (#183): skip if already reconciled & no remaining open duplicates
                    $duplicates = $sameTypeGroup->where('id', '!=', $finding->id)->values();
                    $classification = FindingTypeRegistry::classify($finding);
                    $rawScriptFingerprint = sprintf(
                        '%d:technical.script_error:sitewide:clarity_script_errors:%s',
                        $finding->client_id,
                        $meta['episode_id'] ?? 'ep_20261107'
                    );
                    $normalizedScriptFingerprint = strlen($rawScriptFingerprint) > 64
                        ? hash('sha256', $rawScriptFingerprint)
                        : $rawScriptFingerprint;
                    if (
                        $duplicates->isEmpty()
                        && $pillarVal === $classification['pillar_value']
                        && $finding->source_id === 'clarity_script_errors'
                        && $finding->fingerprint === $normalizedScriptFingerprint
                        && ! empty($meta['base_scope_key'])
                    ) {
                        $summary['unchanged']++;
                        $summary['after_pillars'][$finding->client_id][$pillarVal] = ($summary['after_pillars'][$finding->client_id][$pillarVal] ?? 0) + 1;
                        continue;
                    }

                    $freshness = $freshnessService->evaluateFinding($finding);

                    $peakStart = '2026-11-04';
                    $peakEnd = '2026-11-06';
                    $obsHistory = is_array($meta['observation_history'] ?? null) ? $meta['observation_history'] : [];
                    if (empty($obsHistory)) {
                        $obsHistory[] = [
                            'observed_at'         => $peakEnd,
                            'error_count'         => (int) ($ev['error_count'] ?? 0),
                            'monitored_sessions'  => (int) ($ev['clarity_sessions'] ?? $ev['monitored_sessions'] ?? 0),
                            'rate_per_session'    => $meta['primary_signal']['current_value'] ?? null,
                            'source_window_start' => $peakStart,
                            'source_window_end'   => $peakEnd,
                        ];
                    }

                    $latestDup = $duplicates->sortByDesc('id')->first();
                    $latestEv = [
                        'error_count'         => (int) ($ev['error_count'] ?? 0),
                        'monitored_sessions'  => (int) ($ev['clarity_sessions'] ?? $ev['monitored_sessions'] ?? 0),
                        'clarity_sessions'    => (int) ($ev['clarity_sessions'] ?? $ev['monitored_sessions'] ?? 0),
                        'errors_per_session'  => ($ev['clarity_sessions'] ?? 0) > 0
                            ? round(((int) ($ev['error_count'] ?? 0)) / ((int) $ev['clarity_sessions']), 4)
                            : null,
                        'scope_granularity'   => 'sitewide',
                        'source_window_start' => $peakStart,
                        'source_window_end'   => $peakEnd,
                        'evaluated_at'        => now()->toIso8601String(),
                        'sources'             => ['clarity'],
                    ];
                    $latestTitle = 'Storewide Clarity JavaScript error rate elevated';
                    $latestWindowStart = $peakStart;
                    $latestWindowEnd = $peakEnd;
                    $absorbedNote = '';

                    if ($latestDup) {
                        $dupEv = is_array($latestDup->evidence_json) ? $latestDup->evidence_json : [];
                        $dupMeta = is_array($latestDup->metadata_json) ? $latestDup->metadata_json : [];
                        $dupEnd = $dupMeta['source_window_end'] ?? '2026-11-22';
                        $dupStart = $dupMeta['source_window_start'] ?? $dupEnd;

                        $nov22 = BehavioralMetric::where('client_id', $finding->client_id)
                            ->where('date', $dupEnd)
                            ->first();
                        $affectedPct = $nov22 && isset($nov22->metadata_json['metrics']['ScriptErrorCount']['pct'])
                            ? round((float) $nov22->metadata_json['metrics']['ScriptErrorCount']['pct'], 2)
                            : null;
                        $errCnt = (int) ($dupEv['error_count'] ?? 0);
                        $monSess = (int) ($dupEv['clarity_sessions'] ?? $dupEv['monitored_sessions'] ?? 0);
                        $obsHistory[] = [
                            'absorbed_finding_id' => $latestDup->id,
                            'observed_at'         => $dupEnd,
                            'error_count'         => $errCnt,
                            'monitored_sessions'  => $monSess,
                            'rate_per_session'    => $monSess > 0 ? round($errCnt / $monSess, 4) : null,
                            'affected_session_pct'=> $affectedPct,
                            'source_window_start' => $dupStart,
                            'source_window_end'   => $dupEnd,
                        ];

                        $latestWindowStart = $dupStart;
                        $latestWindowEnd = $dupEnd;
                        $latestTitle = sprintf(
                            'Storewide Clarity JavaScript errors elevated (%s errors across %s sessions on %s)',
                            number_format($errCnt),
                            number_format($monSess),
                            Carbon::parse($dupEnd)->format('M j')
                        );
                        $latestEv = [
                            'error_count'            => $errCnt,
                            'monitored_sessions'     => $monSess,
                            'clarity_sessions'       => $monSess,
                            'errors_per_session'     => $monSess > 0 ? round($errCnt / $monSess, 4) : null,
                            'scope_granularity'      => 'sitewide',
                            'prior_peak_error_count' => (int) ($ev['error_count'] ?? 0),
                            'prior_peak_sessions'    => (int) ($ev['clarity_sessions'] ?? $ev['monitored_sessions'] ?? 0),
                            'source_window_start'    => $latestWindowStart,
                            'source_window_end'      => $latestWindowEnd,
                            'evaluated_at'           => now()->toIso8601String(),
                            'observation_refreshed'  => true,
                            'sources'                => ['clarity'],
                        ];
                        $absorbedNote = sprintf(
                            '- **Latest Verified Observation (%s)**: %s script errors across %s monitored sessions%s (absorbed from duplicate #%d).',
                            Carbon::parse($dupEnd)->format('M j, Y'),
                            number_format($errCnt),
                            number_format($monSess),
                            $affectedPct !== null ? " ({$affectedPct}% affected sessions)" : '',
                            $latestDup->id
                        );
                    }

                    $historicalPeakLine = $latestDup
                        ? sprintf(
                            "\n%s\n- **Historical Episode Peak (%s to %s)**: %s script errors across %s monitored sessions (prior peak context, not current conditions).",
                            $absorbedNote,
                            $peakStart,
                            $peakEnd,
                            number_format((int) ($ev['error_count'] ?? 0)),
                            number_format((int) ($ev['clarity_sessions'] ?? $ev['monitored_sessions'] ?? 0))
                        )
                        : sprintf(
                            "\n- **Observed Window (%s to %s)**: %s script errors across %s monitored sessions.",
                            $peakStart,
                            $peakEnd,
                            number_format((int) ($ev['error_count'] ?? 0)),
                            number_format((int) ($ev['clarity_sessions'] ?? $ev['monitored_sessions'] ?? 0))
                        );

                    $cleanDescription = sprintf(
                        "### Incident Summary\n%s\n\n### Verified Sitewide Telemetry Scope\n- **Scope**: `sitewide` (`clarity_script_errors`; project-level Clarity API counts without per-URL exception stack traces)%s\n\n### Recommended Remediation\nInspect storefront browser console logs and Microsoft Clarity session recordings in Engineering to isolate the throwing script.",
                        $latestTitle,
                        $historicalPeakLine
                    );

                    $cleanPrimarySignal = [
                        'family'         => 'technical.script_error',
                        'scope_type'     => 'sitewide',
                        'scope_target'   => 'clarity_script_errors',
                        'metric'         => 'script_errors_per_session',
                        'title'          => $latestTitle,
                        'current_value'  => $latestEv['errors_per_session'] ?? ($meta['primary_signal']['current_value'] ?? null),
                        'previous_value' => $meta['primary_signal']['previous_value'] ?? null,
                        'detected_at'    => $meta['primary_signal']['detected_at'] ?? ($finding->detected_at?->toDateTimeString()),
                        'episode_id'     => $meta['episode_id'] ?? 'ep_20261107',
                        'base_scope_key' => sprintf('%d:technical.script_error:sitewide:clarity_script_errors', $finding->client_id),
                        'fingerprint'    => $rawScriptFingerprint,
                        'lifecycle_state'=> 'active_episode',
                        'evidence'       => $latestEv,
                    ];

                    $action = [
                        'finding_id'         => $finding->id,
                        'client_id'          => $finding->client_id,
                        'client_name'        => $client?->name ?? (string) $finding->client_id,
                        'finding_type'       => $finding->finding_type,
                        'action_type'        => 'reconcile_primary_script_error',
                        'current_status'     => $statusVal,
                        'proposed_status'    => $statusVal,
                        'current_pillar'     => $pillarVal,
                        'proposed_pillar'    => $classification['pillar_value'],
                        'superseded_by_id'   => null,
                        'related_finding_id' => $latestDup?->id,
                        'reason'             => 'Assign technical_health pillar, normalize fingerprint/scope, strip unverified packaging-tapes/cart_item_options/+216.5% stub claims from actionable fields, preserve initial snapshot in immutable audit trail, and absorb verified Nov 22 Clarity telemetry.',
                        'updates'            => [
                            'finding_pillar' => $classification['pillar_value'],
                            'title'          => $latestTitle,
                            'description'    => $cleanDescription,
                            'fingerprint'    => $normalizedScriptFingerprint,
                            'source_type'    => 'sitewide',
                            'source_id'      => 'clarity_script_errors',
                            'target_role'    => $classification['target_role'],
                        ],
                        'metadata_patch'     => [
                            'base_scope_key'      => sprintf('%d:technical.script_error:sitewide:clarity_script_errors', $finding->client_id),
                            'scope_type'          => 'sitewide',
                            'scope_target'        => 'clarity_script_errors',
                            'actionability'       => [
                                'type'        => 'technical_remediation',
                                'description' => 'Inspect storefront browser console logs and Microsoft Clarity session recordings to isolate the throwing script.',
                                'target_team' => 'engineering',
                            ],
                            'primary_signal'      => $cleanPrimarySignal,
                            'source_window_start' => $latestWindowStart,
                            'source_window_end'   => $latestWindowEnd,
                            'last_observed_at'    => $latestWindowEnd . 'T23:59:59+00:00',
                            'observation_history' => $obsHistory,
                        ],
                        'evidence_patch'     => $latestEv,
                    ];
                    $summary['reconciled_active_findings']++;
                    $summary['pillar_updates']++;
                    $summary['evidence_freshness_updates']++;
                }
            }

            // 3. Reconcile remaining active operational findings (assign pillar + true source_window + refreshed analytical evidence)
            if ($action === null) {
                $classification = FindingTypeRegistry::classify($finding);
                $proposedPillar = $classification['pillar_value'];
                $needsPillar = $pillarVal === 'NULL' || ($proposedPillar !== null && $pillarVal !== $proposedPillar);
                $freshness = $freshnessService->evaluateFinding($finding);

                $alreadyEnriched = ! empty($meta['source_window_end']) && ! empty($ev['source_window_end']);
                $needsOperationalEnrichment = ! $alreadyEnriched
                    && empty($finding->account_identity_hash)
                    && in_array($finding->finding_type, [
                        'attribution.platform_variance',
                        'traffic.volume_surge',
                        'gk_ga4_conversion_rate_decline_30d',
                        'ga4_klaviyo_attribution_variance',
                        'conversion_rate_drop',
                        'adobe_commerce_revenue_drop',
                    ], true);

                if (! $needsPillar && ! $needsOperationalEnrichment) {
                    $summary['unchanged']++;
                    $afterPillar = $pillarVal;
                    $summary['after_pillars'][$finding->client_id][$afterPillar] = ($summary['after_pillars'][$finding->client_id][$afterPillar] ?? 0) + 1;
                    continue;
                }

                $metaPatch = [];
                $evPatch = null;
                $updates = [];

                if ($proposedPillar !== null && $pillarVal !== $proposedPillar) {
                    $updates['finding_pillar'] = $proposedPillar;
                }

                if ($needsOperationalEnrichment && $client) {
                    $wEnd = $freshness['source_window_end']
                        ? Carbon::parse($freshness['source_window_end'])->endOfDay()
                        : ($finding->detected_at ? $finding->detected_at->copy()->subDay()->endOfDay() : Carbon::yesterday()->endOfDay());
                    $periodDays = (int) ($meta['period_days'] ?? ($finding->finding_type === 'traffic.volume_surge' ? 7 : 30));
                    $wStart = $freshness['source_window_start']
                        ? Carbon::parse($freshness['source_window_start'])->startOfDay()
                        : $wEnd->copy()->subDays(max(0, $periodDays - 1))->startOfDay();

                    // Reconcile last_observed_at strictly to actual source window end (removing false Nov 25 stamps!)
                    $metaPatch['source_window_start'] = $wStart->toDateString();
                    $metaPatch['source_window_end'] = $wEnd->toDateString();
                    $metaPatch['last_observed_at'] = $wEnd->toDateString() . 'T23:59:59+00:00';
                    $metaPatch['evaluated_at'] = $meta['primary_signal']['detected_at'] ?? $finding->detected_at?->toIso8601String();

                    if ($finding->finding_type === 'attribution.platform_variance') {
                        $recon = $reconciler->summarizeReadOnly($client, $wStart, $wEnd);
                        $verifiedPct = (float) ($recon['variance_pct'] ?: ($ev['variance_pct'] ?? 0.0));
                        $newVarTitle = sprintf('GA4 vs Commerce revenue variance (%.1f%% discrepancy)', $verifiedPct);
                        $updates['title'] = $newVarTitle;
                        $updates['description'] = sprintf(
                            "### Incident Summary\nGA4 tracked \$%s vs \$%s in Commerce valid net revenue over %s to %s — a reporting discrepancy of \$%s (%.1f%%; GA4 exceeds Commerce valid net revenue).\n\n### Transaction-ID Reconciliation\n- **Matched Valid Orders**: %d\n- **Excluded/Canceled Commerce Orders Retained in GA4**: %d (\$%s)\n- **Valid Commerce Orders Missing in GA4**: %d\n\n### Assessment\nCross-platform tracking and order-status alignment variance rather than lost sales.",
                            number_format((float) $recon['ga4_tracked_revenue'], 2),
                            number_format((float) $recon['adobe_net_revenue'], 2),
                            $wStart->toDateString(),
                            $wEnd->toDateString(),
                            number_format((float) $recon['variance_amount'], 2),
                            $verifiedPct,
                            (int) ($recon['matched_valid_count'] ?? 0),
                            (int) ($recon['matched_excluded_count'] ?? 0),
                            number_format((float) ($recon['matched_excluded_revenue'] ?? 0.0), 2),
                            (int) ($recon['missing_in_ga4_count'] ?? 0)
                        );
                        $metaPatch['difference_amount'] = $recon['variance_amount'];
                        $metaPatch['difference_pct'] = $verifiedPct;
                        $evPatch = array_merge($ev, [
                            'reconciliation_summary' => $recon,
                            'variance_amount'        => $recon['variance_amount'],
                            'variance_pct'           => $verifiedPct,
                            'ga4_revenue'            => $recon['ga4_tracked_revenue'],
                            'commerce_net_revenue'   => $recon['adobe_net_revenue'],
                            'source_window_start'    => $wStart->toDateString(),
                            'source_window_end'      => $wEnd->toDateString(),
                        ]);
                        if (isset($meta['primary_signal']) && is_array($meta['primary_signal'])) {
                            $metaPatch['primary_signal'] = array_merge($meta['primary_signal'], [
                                'title'          => $newVarTitle,
                                'current_value'  => $recon['ga4_tracked_revenue'],
                                'previous_value' => $recon['adobe_net_revenue'],
                                'evidence'       => $evPatch,
                            ]);
                        }
                    } elseif ($finding->finding_type === 'gk_ga4_conversion_rate_decline_30d' || $finding->finding_type === 'conversion_rate_drop') {
                        $prevEnd = $wStart->copy()->subDay()->endOfDay();
                        $prevStart = $prevEnd->copy()->subDays(max(0, $periodDays - 1))->startOfDay();
                        $convDiag = $diagService->buildConversionWindowDiagnostics($client, $wStart, $wEnd, $prevStart, $prevEnd);
                        $metaPatch['conversion_diagnostics'] = $convDiag;
                        $evPatch = array_merge($ev, [
                            'conversion_diagnostics' => $convDiag,
                            'source_window_start'    => $wStart->toDateString(),
                            'source_window_end'      => $wEnd->toDateString(),
                        ]);
                    } elseif ($finding->finding_type === 'ga4_klaviyo_attribution_variance') {
                        $klaviyoDiag = $diagService->buildKlaviyoAttributionDiagnostics($client, $wStart, $wEnd);
                        $metaPatch['klaviyo_diagnostics'] = $klaviyoDiag;
                        $evPatch = array_merge($ev, [
                            'klaviyo_diagnostics' => $klaviyoDiag,
                            'source_window_start' => $wStart->toDateString(),
                            'source_window_end'   => $wEnd->toDateString(),
                        ]);
                    } elseif ($finding->finding_type === 'traffic.volume_surge') {
                        // Compute genuine Direct channel numbers for Nov 1..Nov 7 (replacing hardcoded 91.7% / flat conversion)
                        $wStart7 = $wEnd->copy()->subDays(6)->startOfDay();
                        $prevEnd7 = $wStart7->copy()->subDay()->endOfDay();
                        $prevStart7 = $prevEnd7->copy()->subDays(6)->startOfDay();
                        $diag7 = $diagService->buildConversionWindowDiagnostics($client, $wStart7, $wEnd, $prevStart7, $prevEnd7);
                        $curr7 = $diag7['current'];
                        $prev7 = $diag7['previous'];
                        $surgePct7 = (float) ($diag7['direct_sessions_delta_pct'] ?? ($ev['surge_pct'] ?? 0));
                        $directShare7 = (float) ($curr7['ga4_direct_share_pct'] ?? 0);
                        $surgeTitle = sprintf(
                            'Sudden surge in Direct traffic (+%.1f%% Direct sessions; %.1f%% of site traffic)',
                            $surgePct7,
                            $directShare7
                        );
                        $metaPatch['source_window_start'] = $wStart7->toDateString();
                        $metaPatch['source_window_end'] = $wEnd->toDateString();
                        $metaPatch['last_observed_at'] = $wEnd->toDateString() . 'T23:59:59+00:00';
                        $updates['title'] = $surgeTitle;
                        $updates['description'] = sprintf(
                            "### Incident Summary\nGA4 Direct traffic rose +%.1f%% from %s to %s sessions over %s to %s (%.1f%% of %s total sessions), while storewide GA4 orders moved from %d to %d.\n\n### Investigation Scope\nInspect referrer headers, landing pages, user-agent distributions, and GA4 channel grouping rules to isolate traffic origin (do not assume automated bot traffic without supporting log evidence).",
                            $surgePct7,
                            number_format((int) ($prev7['ga4_direct_sessions'] ?? 0)),
                            number_format((int) ($curr7['ga4_direct_sessions'] ?? 0)),
                            $wStart7->toDateString(),
                            $wEnd->toDateString(),
                            $directShare7,
                            number_format((int) ($curr7['ga4_sessions'] ?? 0)),
                            (int) ($prev7['ga4_orders'] ?? 0),
                            (int) ($curr7['ga4_orders'] ?? 0)
                        );
                        $evPatch = [
                            'nature'                    => 'unassigned_traffic_volume',
                            'dominant_channel'          => 'direct',
                            'surge_pct'                 => $surgePct7,
                            'direct_sessions'           => $curr7['ga4_direct_sessions'],
                            'prior_direct_sessions'     => $prev7['ga4_direct_sessions'],
                            'total_sessions'            => $curr7['ga4_sessions'],
                            'direct_share_pct'          => $directShare7,
                            'direct_conversions'        => $curr7['ga4_direct_orders'],
                            'prior_direct_conversions'  => $prev7['ga4_direct_orders'],
                            'storewide_conversions'     => $curr7['ga4_orders'],
                            'prior_storewide_conversions' => $prev7['ga4_orders'],
                            'requires_investigation'    => true,
                            'source_window_start'       => $wStart7->toDateString(),
                            'source_window_end'         => $wEnd->toDateString(),
                        ];
                        if (isset($meta['primary_signal']) && is_array($meta['primary_signal'])) {
                            $metaPatch['primary_signal'] = array_merge($meta['primary_signal'], [
                                'title'          => $surgeTitle,
                                'current_value'  => (int) ($curr7['ga4_direct_sessions'] ?? 0),
                                'previous_value' => (int) ($prev7['ga4_direct_sessions'] ?? 0),
                                'evidence'       => $evPatch,
                            ]);
                        }
                    } else {
                        $evPatch = array_merge($ev, [
                            'source_window_start' => $wStart->toDateString(),
                            'source_window_end'   => $wEnd->toDateString(),
                            'evaluated_at'        => $metaPatch['evaluated_at'],
                        ]);
                    }
                }

                $action = [
                    'finding_id'         => $finding->id,
                    'client_id'          => $finding->client_id,
                    'client_name'        => $client?->name ?? (string) $finding->client_id,
                    'finding_type'       => $finding->finding_type,
                    'action_type'        => ($needsPillar ? 'assign_pillar' : 'refresh_evidence_window') . ($needsOperationalEnrichment ? '+enrich_evidence' : ''),
                    'current_status'     => $statusVal,
                    'proposed_status'    => $statusVal,
                    'current_pillar'     => $pillarVal,
                    'proposed_pillar'    => $proposedPillar ?? 'UNCLASSIFIED',
                    'superseded_by_id'   => null,
                    'related_finding_id' => null,
                    'reason'             => sprintf(
                        'Assign authoritative pillar (%s -> %s), anchor source window (%s..%s), and attach verified source telemetry.',
                        $pillarVal,
                        $proposedPillar ?? 'UNCLASSIFIED',
                        $metaPatch['source_window_start'] ?? ($freshness['source_window_start'] ?? 'n/a'),
                        $metaPatch['source_window_end'] ?? ($freshness['source_window_end'] ?? 'n/a')
                    ),
                    'updates'            => $updates,
                    'metadata_patch'     => $metaPatch,
                    'evidence_patch'     => $evPatch,
                ];

                $summary['reconciled_active_findings']++;
                if ($needsPillar) {
                    $summary['pillar_updates']++;
                }
                if ($needsOperationalEnrichment) {
                    $summary['evidence_freshness_updates']++;
                }
            }

            $plan[] = $action;

            if (in_array($action['proposed_status'], FindingStatus::openValues(), true)) {
                $afterPillar = $action['proposed_pillar'];
                $summary['after_pillars'][$finding->client_id][$afterPillar] = ($summary['after_pillars'][$finding->client_id][$afterPillar] ?? 0) + 1;
            }
        }

        $summary['disposition_breakdown'] = [
            'unchanged'                  => $summary['unchanged'],
            'reconciled_active_findings' => $summary['reconciled_active_findings'],
            'consolidated_duplicates'    => $summary['consolidated_duplicates'],
            'invalidated_fabricated'     => $summary['invalidated_fabricated'],
        ];

        if (! $isDryRun && ! empty($plan)) {
            DB::transaction(function () use ($plan) {
                $findingsById = Finding::whereIn('id', collect($plan)->pluck('finding_id')->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($plan as $item) {
                    if ($item['action_type'] === 'manual_review_blocked') {
                        continue;
                    }
                    /** @var Finding|null $model */
                    $model = $findingsById->get($item['finding_id']);
                    if (! $model) {
                        continue;
                    }

                    $origMeta = is_array($model->metadata_json) ? $model->metadata_json : [];
                    $auditTrail = is_array($origMeta['integrity_reconciliation_audit'] ?? null)
                        ? $origMeta['integrity_reconciliation_audit']
                        : [];

                    $auditTrail[] = [
                        'reconciled_at'      => now()->toIso8601String(),
                        'action_type'        => $item['action_type'],
                        'reason'             => $item['reason'],
                        'prior_status'       => $model->status?->value ?? (string) $model->status,
                        'prior_pillar'       => $model->finding_pillar?->value,
                        'prior_title'        => $model->title,
                        'prior_description'  => $model->description,
                        'prior_fingerprint'  => $model->fingerprint,
                        'prior_source_type'  => $model->source_type,
                        'prior_source_id'    => $model->source_id,
                        'evidence_snapshot'  => $model->evidence_json,
                        'metadata_snapshot'  => collect($origMeta)->except(['integrity_reconciliation_audit'])->all(),
                    ];

                    $newMeta = array_merge($origMeta, $item['metadata_patch'] ?? []);
                    $newMeta['integrity_reconciliation_audit'] = $auditTrail;

                    $updates = $item['updates'] ?? [];
                    $updates['metadata_json'] = $newMeta;
                    if ($item['evidence_patch'] !== null) {
                        $updates['evidence_json'] = $item['evidence_patch'];
                    }

                    $model->fill($updates);
                    $model->save();
                }
            });
        }

        if ($format === 'json') {
            $this->line(json_encode(['summary' => $summary, 'actions' => $plan], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info($isDryRun
            ? 'DRY-RUN COMPLETE (Zero database rows modified):'
            : 'RECONCILIATION APPLIED WITH IMMUTABLE AUDIT TRAIL:');

        if (empty($plan)) {
            $this->info('No findings require reconciliation.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Client', 'Type', 'Action', 'Status (Before→After)', 'Pillar (Before→After)', 'Reason'],
            collect($plan)->map(fn ($p) => [
                $p['finding_id'],
                "{$p['client_name']} (#{$p['client_id']})",
                $p['finding_type'],
                $p['action_type'],
                "{$p['current_status']} → {$p['proposed_status']}",
                "{$p['current_pillar']} → {$p['proposed_pillar']}",
                mb_strimwidth($p['reason'], 0, 95, '…'),
            ])->all()
        );

        $this->line('Summary: ' . json_encode($summary));

        return self::SUCCESS;
    }

    private function buildManualReviewAction(Finding $finding, string $reason): array
    {
        return [
            'finding_id'         => $finding->id,
            'client_id'          => $finding->client_id,
            'client_name'        => $finding->client?->name ?? (string) $finding->client_id,
            'finding_type'       => $finding->finding_type,
            'action_type'        => 'manual_review_blocked',
            'current_status'     => $finding->status?->value ?? (string) $finding->status,
            'proposed_status'    => $finding->status?->value ?? (string) $finding->status,
            'current_pillar'     => $finding->finding_pillar?->value ?? 'NULL',
            'proposed_pillar'    => $finding->finding_pillar?->value ?? 'NULL',
            'superseded_by_id'   => null,
            'reason'             => $reason,
            'updates'            => [],
            'metadata_patch'     => [],
            'evidence_patch'     => null,
        ];
    }
}
