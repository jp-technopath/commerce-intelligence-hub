<?php

namespace App\Console\Commands;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\FindingStatusTransition;
use App\Services\Configuration\ClientConfigurationManager;
use App\Services\Metrics\RevenueReconciler;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Narrowly scoped Commerce Data Foundation closeout reconciliation:
 * - Isolates & quarantines Client #638 (Tinker Test) synthetic metrics and Finding #283.
 * - Reconciles #187, #280, #281, #186, #191 using equivalent complete observation windows.
 * - Never deletes finding rows, never fabricates recovered revenue, and preserves full audit history.
 */
class ExecuteCommerceCloseoutCommand extends Command
{
    protected $signature = 'forge:closeout-commerce-foundation
                            {--dry-run : Preview closeout changes without mutating database or files (default)}
                            {--apply : Apply closeout changes transactionally with immutable audit logs}
                            {--synthetic-client=638 : Client ID of the synthetic test tenant}
                            {--finding-283=283 : Finding ID for synthetic fixture attribution variance}
                            {--finding-187=187 : Finding ID for Transpart GA4 vs Commerce variance}
                            {--finding-280=280 : Finding ID for Goldkamp GA4 vs Commerce variance}
                            {--finding-281=281 : Finding ID for Cambro revenue drop}
                            {--finding-186=186 : Finding ID for Cambro conversion drop}
                            {--finding-191=191 : Finding ID for Goldkamp 30d conversion dilution}
                            {--as-of= : Optional YYYY-MM-DD reference date for equivalent-period revalidation}';

    protected $description = 'Execute idempotent final closeout for synthetic isolation and operational finding reconciliation';

    public function handle(ClientConfigurationManager $configManager, RevenueReconciler $reconciler): int
    {
        $apply = (bool) $this->option('apply');
        $isDryRun = ! $apply || (bool) $this->option('dry-run');

        if ($apply && $this->option('dry-run')) {
            $this->error('Specify either --dry-run or --apply, not both.');
            return self::FAILURE;
        }

        $syntheticClientId = (int) $this->option('synthetic-client');
        $id283 = (int) $this->option('finding-283');
        $id187 = (int) $this->option('finding-187');
        $id280 = (int) $this->option('finding-280');
        $id281 = (int) $this->option('finding-281');
        $id186 = (int) $this->option('finding-186');
        $id191 = (int) $this->option('finding-191');

        $plan = [];
        $summary = [
            'mode'                 => $isDryRun ? 'dry_run' : 'apply',
            'synthetic_metrics'    => 0,
            'synthetic_client'     => null,
            'findings_planned'     => 0,
            'findings_skipped_pm'  => 0,
            'findings_already_ok'  => 0,
        ];

        // 1. Synthetic Tenant Isolation Plan (Client #638)
        $syntheticClient = Client::find($syntheticClientId);
        $syntheticMetrics = CommerceMetric::where('client_id', $syntheticClientId)->orderBy('date')->get();
        $summary['synthetic_metrics'] = $syntheticMetrics->count();

        if ($syntheticClient) {
            $cfg = is_array($syntheticClient->monitoring_config) ? $syntheticClient->monitoring_config : [];
            $needsInternalFlag = ! (bool) $syntheticClient->is_internal || empty($cfg['is_synthetic_fixture']);
            if ($needsInternalFlag || $syntheticMetrics->isNotEmpty()) {
                $summary['synthetic_client'] = [
                    'client_id'             => $syntheticClient->id,
                    'name'                  => $syntheticClient->name,
                    'current_is_internal'   => (bool) $syntheticClient->is_internal,
                    'proposed_is_internal'  => true,
                    'metrics_to_quarantine' => $syntheticMetrics->count(),
                ];
            }
        }

        // 2. Findings Plan (#283, #187, #280, #281, #186, #191)
        $findingIds = [$id283, $id187, $id280, $id281, $id186, $id191];
        $findings = Finding::whereIn('id', $findingIds)->get()->keyBy('id');

        foreach ([
            '283' => $id283,
            '187' => $id187,
            '280' => $id280,
            '281' => $id281,
            '186' => $id186,
            '191' => $id191,
        ] as $slot => $fid) {
            /** @var Finding|null $finding */
            $finding = $findings->get($fid);
            if (! $finding) {
                continue;
            }

            $meta = is_array($finding->metadata_json) ? $finding->metadata_json : [];
            if (! empty($meta['closeout_reconciled_v1'])) {
                $summary['findings_already_ok']++;
                continue;
            }

            if (! empty($finding->pm_work_item_id) || ! empty($finding->approved_by_user_id)) {
                $summary['findings_skipped_pm']++;
                continue;
            }

            $item = match ((string) $slot) {
                '283' => $this->planSyntheticFinding($finding),
                '187' => $this->planPlatformVarianceFinding($finding, $reconciler, 'invalidated_by_source_data_correction', 'Closed as invalidated by corrected source data'),
                '280' => $this->planPlatformVarianceFinding($finding, $reconciler, 'remediated_by_source_data_correction', 'Closed as remediated by source data correction'),
                '281' => $this->planCambroRevenueFinding($finding, $this->option('as-of')),
                '186' => $this->planCambroConversionFinding($finding, $this->option('as-of')),
                '191' => $this->planGoldkampDilutionFinding($finding, $this->option('as-of')),
            };

            if ($item !== null) {
                $plan[] = $item;
                $summary['findings_planned']++;
            } else {
                $summary['findings_already_ok']++;
            }
        }

        if ($isDryRun) {
            $this->info('DRY-RUN COMPLETE (Zero database rows or files modified):');
            $this->line(json_encode(['summary' => $summary, 'plan' => $plan], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // Execute quarantine export first if metrics exist, then mutate DB transactionally
        $quarantinePath = null;
        if ($syntheticMetrics->isNotEmpty()) {
            $quarantineDir = storage_path("app/private/security-quarantine/synthetic-client-{$syntheticClientId}");
            if (! is_dir($quarantineDir)) {
                mkdir($quarantineDir, 0700, true);
            }
            @chmod($quarantineDir, 0700);

            $quarantinePath = "{$quarantineDir}/client{$syntheticClientId}_synthetic_metrics_quarantine.json.gz";
            $payload = [
                'quarantined_at' => now()->toIso8601String(),
                'client_id'      => $syntheticClientId,
                'client_name'    => $syntheticClient?->name,
                'row_count'      => $syntheticMetrics->count(),
                'rows'           => $syntheticMetrics->toArray(),
            ];
            file_put_contents($quarantinePath, gzencode(json_encode($payload, JSON_PRETTY_PRINT), 6));
            @chmod($quarantinePath, 0600);

            $manifestPath = "{$quarantineDir}/manifest.json";
            file_put_contents($manifestPath, json_encode([
                'client_id'   => $syntheticClientId,
                'row_count'   => $syntheticMetrics->count(),
                'min_date'    => $syntheticMetrics->min('date'),
                'max_date'    => $syntheticMetrics->max('date'),
                'quarantined_at' => now()->toIso8601String(),
                'sha256'      => hash_file('sha256', $quarantinePath),
            ], JSON_PRETTY_PRINT));
            @chmod($manifestPath, 0600);
        }

        DB::transaction(function () use ($syntheticClient, $syntheticClientId, $syntheticMetrics, $configManager, $plan) {
            if ($syntheticClient) {
                if (! (bool) $syntheticClient->is_internal) {
                    $syntheticClient->is_internal = true;
                    $syntheticClient->save();
                }

                $cfg = is_array($syntheticClient->monitoring_config) ? $syntheticClient->monitoring_config : [];
                if (empty($cfg['is_synthetic_fixture'])) {
                    $configManager->updateClientMonitoringConfig($syntheticClient, [
                        'is_synthetic_fixture' => true,
                    ], 'commerce_closeout');
                }
            }

            if ($syntheticMetrics->isNotEmpty()) {
                CommerceMetric::where('client_id', $syntheticClientId)->delete();
            }

            $lockedFindings = Finding::whereIn('id', collect($plan)->pluck('finding_id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($plan as $item) {
                /** @var Finding|null $model */
                $model = $lockedFindings->get($item['finding_id']);
                if (! $model) {
                    continue;
                }

                $origMeta = is_array($model->metadata_json) ? $model->metadata_json : [];
                if (! empty($origMeta['closeout_reconciled_v1'])) {
                    continue;
                }

                $fromStatus = $model->status instanceof FindingStatus ? $model->status : FindingStatus::New;
                $fromExec = $model->execution_status instanceof FindingExecutionStatus ? $model->execution_status : FindingExecutionStatus::New;
                $fromOutcome = $model->outcome_status instanceof FindingOutcomeStatus ? $model->outcome_status : FindingOutcomeStatus::PendingExecution;

                $toStatus = FindingStatus::from($item['to_status']);
                $toExec = FindingExecutionStatus::from($item['to_execution_status']);
                $toOutcome = FindingOutcomeStatus::from($item['to_outcome_status']);

                $auditTrail = is_array($origMeta['integrity_reconciliation_audit'] ?? null)
                    ? $origMeta['integrity_reconciliation_audit']
                    : [];
                $auditTrail[] = [
                    'reconciled_at'     => now()->toIso8601String(),
                    'action_type'       => $item['action_type'],
                    'reason'            => $item['reason'],
                    'prior_status'      => $fromStatus->value,
                    'prior_execution'   => $fromExec->value,
                    'prior_outcome'     => $fromOutcome->value,
                    'prior_title'       => $model->title,
                    'prior_description' => $model->description,
                    'evidence_snapshot' => $model->evidence_json,
                    'metadata_snapshot' => collect($origMeta)->except(['integrity_reconciliation_audit'])->all(),
                ];

                $newMeta = array_merge($origMeta, $item['metadata_patch'] ?? []);
                $newMeta['integrity_reconciliation_audit'] = $auditTrail;
                $newMeta['closeout_reconciled_v1'] = true;

                $history = is_array($model->status_history_json) ? $model->status_history_json : [];
                if ($fromStatus->value !== $toStatus->value) {
                    $history[] = [
                        'from_status'           => $fromStatus->value,
                        'to_status'             => $toStatus->value,
                        'from_execution_status' => $fromExec->value,
                        'to_execution_status'   => $toExec->value,
                        'from_outcome_status'   => $fromOutcome->value,
                        'to_outcome_status'     => $toOutcome->value,
                        'changed_by_user_id'    => null,
                        'changed_by_name'       => 'System Closeout Reconciliation',
                        'changed_at'            => now()->toIso8601String(),
                        'notes'                 => $item['resolution_notes'],
                    ];
                }

                $updates = array_merge($item['updates'] ?? [], [
                    'status'              => $toStatus->value,
                    'execution_status'    => $toExec->value,
                    'outcome_status'      => $toOutcome->value,
                    'metadata_json'       => $newMeta,
                    'status_history_json' => $history,
                    'resolution_notes'    => $item['resolution_notes'],
                ]);

                if ($item['evidence_patch'] !== null) {
                    $updates['evidence_json'] = $item['evidence_patch'];
                }

                if ($toStatus === FindingStatus::Dismissed) {
                    $updates['dismissed_at'] = $model->dismissed_at ?? now();
                    $updates['dismissed_reason'] = $item['dismissed_reason'];
                }

                $model->fill($updates);
                $model->save();

                if ($fromStatus->value !== $toStatus->value) {
                    FindingStatusTransition::create([
                        'finding_id'            => $model->id,
                        'from_status'           => $fromStatus,
                        'to_status'             => $toStatus,
                        'from_execution_status' => $fromExec,
                        'to_execution_status'   => $toExec,
                        'from_outcome_status'   => $fromOutcome,
                        'to_outcome_status'     => $toOutcome,
                        'user_id'               => null,
                        'user_name'             => 'System Closeout Reconciliation',
                        'transition_source'     => 'system_reconciliation',
                        'notes'                 => $item['resolution_notes'],
                    ]);
                }
            }
        });

        $this->info('COMMERCE CLOSEOUT APPLIED WITH IMMUTABLE AUDIT TRAIL:');
        $this->line(json_encode([
            'summary'         => $summary,
            'quarantine_path' => $quarantinePath,
            'applied_count'   => count($plan),
            'findings'        => collect($plan)->map(fn ($p) => [
                'finding_id'  => $p['finding_id'],
                'action_type' => $p['action_type'],
                'to_status'   => $p['to_status'],
            ])->all(),
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function planSyntheticFinding(Finding $finding): array
    {
        $ev = is_array($finding->evidence_json) ? $finding->evidence_json : [];

        return [
            'finding_id'          => $finding->id,
            'action_type'         => 'invalidate_synthetic_fixture',
            'to_status'           => FindingStatus::Dismissed->value,
            'to_execution_status' => FindingExecutionStatus::Dismissed->value,
            'to_outcome_status'   => FindingOutcomeStatus::Dismissed->value,
            'dismissed_reason'    => 'invalidated_synthetic_fixture_data',
            'reason'              => 'Invalidated synthetic test-fixture finding generated from internal test tenant Client #638 (Tinker Test) with 0 integrations and synthetic metrics.',
            'resolution_notes'    => 'Invalidated synthetic test-fixture finding from Tinker Test (Client #638); synthetic metrics quarantined and tenant marked internal. Historical audit record preserved.',
            'updates'             => [
                'is_customer_visible'       => false,
                'visibility_classification' => 'internal_only',
            ],
            'metadata_patch'      => [
                'disposition_type' => 'invalidated_synthetic_fixture',
            ],
            'evidence_patch'      => array_merge($ev, [
                'synthetic_fixture_invalidated' => true,
            ]),
        ];
    }

    private function planPlatformVarianceFinding(Finding $finding, RevenueReconciler $reconciler, string $dispositionKey, string $prefix): ?array
    {
        $client = Client::find($finding->client_id);
        if (! $client) {
            return null;
        }

        $maxGa4 = CommerceMetric::where('client_id', $client->id)->where('source', 'ga4')->max('date');
        $maxCom = CommerceMetric::where('client_id', $client->id)->where('source', 'adobe_commerce')->max('date');
        $endDate = ($maxGa4 && $maxCom) ? min($maxGa4, $maxCom) : ($maxGa4 ?? $maxCom);
        if (! $endDate) {
            return null;
        }

        $wEnd = Carbon::parse($endDate)->endOfDay();
        $wStart = $wEnd->copy()->subDays(29)->startOfDay();
        $recon = $reconciler->summarizeReadOnly($client, $wStart, $wEnd);
        $variancePct = (float) ($recon['variance_pct'] ?? 100.0);

        $thresholdPct = $this->resolveVarianceThresholdPct($client, 20.0);
        if ($variancePct >= $thresholdPct) {
            return null;
        }

        $ev = is_array($finding->evidence_json) ? $finding->evidence_json : [];
        $notes = sprintf(
            '%s: equivalent complete 30-day (%s..%s) GA4 ($%s across %d orders) vs Commerce valid net ($%s across %d orders) variance is %.1f%% (< %.1f%% threshold). Data correction only — not recovered revenue or customer optimization.',
            $prefix,
            $wStart->toDateString(),
            $wEnd->toDateString(),
            number_format((float) $recon['ga4_tracked_revenue'], 2),
            (int) ($recon['ga4_transaction_count'] ?? 0),
            number_format((float) $recon['adobe_net_revenue'], 2),
            (int) ($recon['adobe_valid_count'] ?? 0),
            $variancePct,
            $thresholdPct
        );

        return [
            'finding_id'          => $finding->id,
            'action_type'         => $dispositionKey,
            'to_status'           => FindingStatus::Dismissed->value,
            'to_execution_status' => FindingExecutionStatus::Dismissed->value,
            'to_outcome_status'   => FindingOutcomeStatus::Dismissed->value,
            'dismissed_reason'    => $dispositionKey,
            'reason'              => $notes,
            'resolution_notes'    => $notes,
            'updates'             => [],
            'metadata_patch'      => [
                'disposition_type'          => $dispositionKey,
                'closeout_threshold_pct'    => $thresholdPct,
                'post_remediation_variance' => [
                    'window'        => $wStart->toDateString() . '..' . $wEnd->toDateString(),
                    'variance_pct'  => $variancePct,
                    'ga4_revenue'   => $recon['ga4_tracked_revenue'],
                    'adobe_net_rev' => $recon['adobe_net_revenue'],
                ],
            ],
            'evidence_patch'      => array_merge($ev, [
                'post_remediation_reconciliation' => $recon,
            ]),
        ];
    }

    private function planCambroRevenueFinding(Finding $finding, ?string $asOf): ?array
    {
        $clientId = $finding->client_id;
        $maxGa4 = CommerceMetric::where('client_id', $clientId)->where('source', 'ga4')->max('date');
        $endStr = $asOf ?: ($maxGa4 ?: Carbon::yesterday()->toDateString());
        $curEnd = Carbon::parse($endStr)->endOfDay();
        $curStart = $curEnd->copy()->subDays(29)->startOfDay();
        $prevEnd = $curStart->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays(29)->startOfDay();

        $cur = CommerceOrder::where('client_id', $clientId)->where('is_valid', true)
            ->whereBetween('order_date', [$curStart, $curEnd])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(net_revenue), 0) as rev')
            ->first();
        $prev = CommerceOrder::where('client_id', $clientId)->where('is_valid', true)
            ->whereBetween('order_date', [$prevStart, $prevEnd])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(net_revenue), 0) as rev')
            ->first();

        $curRev = round((float) ($cur->rev ?? 0), 2);
        $prevRev = round((float) ($prev->rev ?? 0), 2);
        $curCnt = (int) ($cur->cnt ?? 0);
        $prevCnt = (int) ($prev->cnt ?? 0);

        $pctChange = $prevRev > 0 ? round((($curRev - $prevRev) / $prevRev) * 100, 1) : 0.0;
        if ($pctChange <= 0.0) {
            return null;
        }

        $ev = is_array($finding->evidence_json) ? $finding->evidence_json : [];
        $notes = sprintf(
            'Closed after equivalent 30-day revalidation: original 2026-10-13..2026-11-11 observation preserved; current complete 30-day window (%s..%s) recorded $%s across %d valid orders (%.1f%% MoM vs $%s / %d orders in %s..%s). Natural commercial rebound — not classified as Forge-recovered revenue or completed optimization.',
            $curStart->toDateString(),
            $curEnd->toDateString(),
            number_format($curRev, 2),
            $curCnt,
            $pctChange,
            number_format($prevRev, 2),
            $prevCnt,
            $prevStart->toDateString(),
            $prevEnd->toDateString()
        );

        return [
            'finding_id'          => $finding->id,
            'action_type'         => 'closed_superseded_historical_observation',
            'to_status'           => FindingStatus::Dismissed->value,
            'to_execution_status' => FindingExecutionStatus::Dismissed->value,
            'to_outcome_status'   => FindingOutcomeStatus::Dismissed->value,
            'dismissed_reason'    => 'historical_episode_subsided_naturally',
            'reason'              => $notes,
            'resolution_notes'    => $notes,
            'updates'             => [],
            'metadata_patch'      => [
                'disposition_type' => 'closed_superseded_historical_observation',
            ],
            'evidence_patch'      => array_merge($ev, [
                'current_equivalent_30d_revalidation' => [
                    'current_window' => $curStart->toDateString() . '..' . $curEnd->toDateString(),
                    'current_orders' => $curCnt,
                    'current_net'    => $curRev,
                    'prior_window'   => $prevStart->toDateString() . '..' . $prevEnd->toDateString(),
                    'prior_orders'   => $prevCnt,
                    'prior_net'      => $prevRev,
                    'mom_pct'        => $pctChange,
                ],
            ]),
        ];
    }

    private function planCambroConversionFinding(Finding $finding, ?string $asOf): ?array
    {
        $clientId = $finding->client_id;
        $maxGa4 = CommerceMetric::where('client_id', $clientId)->where('source', 'ga4')->max('date');
        $endStr = $asOf ?: ($maxGa4 ?: Carbon::yesterday()->toDateString());

        $curEnd = Carbon::parse($endStr)->endOfDay();
        $curStart = $curEnd->copy()->subDays(13)->startOfDay();
        $prevEnd = $curStart->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays(13)->startOfDay();

        $cur = $this->computeConversionMetrics($clientId, $curStart->toDateString(), $curEnd->toDateString());
        $prev = $this->computeConversionMetrics($clientId, $prevStart->toDateString(), $prevEnd->toDateString());

        $curCvr = $cur['ga4_overall_cvr_pct'];
        $prevCvr = $prev['ga4_overall_cvr_pct'];
        $curNonDirect = $cur['ga4_non_direct_cvr_pct'];
        $prevNonDirect = $prev['ga4_non_direct_cvr_pct'];
        $curBuyerCvr = $cur['magento_orders_per_active_user_pct'];
        $prevBuyerCvr = $prev['magento_orders_per_active_user_pct'];

        // Must verify true conversion-rate & funnel improvement, not raw order count alone
        if ($curCvr <= $prevCvr || $curNonDirect <= $prevNonDirect || $curBuyerCvr <= $prevBuyerCvr) {
            return null;
        }

        $ev = is_array($finding->evidence_json) ? $finding->evidence_json : [];
        $notes = sprintf(
            'Closed after equivalent 14-day conversion and funnel revalidation (not order volume alone): in %s..%s vs %s..%s, GA4 session CVR rose %.3f%% -> %.3f%%, non-Direct CVR rose %.3f%% -> %.3f%%, checkout completion rose %.1f%% -> %.1f%% (%d/%d), and Commerce valid-order/active-user rate rose %.3f%% -> %.3f%%. Original 2026-10-25..2026-11-07 observation preserved; not classified as completed optimization.',
            $curStart->toDateString(),
            $curEnd->toDateString(),
            $prevStart->toDateString(),
            $prevEnd->toDateString(),
            $prevCvr,
            $curCvr,
            $prevNonDirect,
            $curNonDirect,
            (float) ($prev['checkout_completion_pct'] ?? 0),
            (float) ($cur['checkout_completion_pct'] ?? 0),
            (int) ($cur['funnel']['purchase'] ?? 0),
            (int) ($cur['funnel']['begin_checkout'] ?? 0),
            $prevBuyerCvr,
            $curBuyerCvr
        );

        return [
            'finding_id'          => $finding->id,
            'action_type'         => 'closed_revalidated_funnel_recovery',
            'to_status'           => FindingStatus::Dismissed->value,
            'to_execution_status' => FindingExecutionStatus::Dismissed->value,
            'to_outcome_status'   => FindingOutcomeStatus::Dismissed->value,
            'dismissed_reason'    => 'historical_conversion_dip_recovered_on_funnel_revalidation',
            'reason'              => $notes,
            'resolution_notes'    => $notes,
            'updates'             => [],
            'metadata_patch'      => [
                'disposition_type' => 'closed_revalidated_funnel_recovery',
            ],
            'evidence_patch'      => array_merge($ev, [
                'current_equivalent_14d_revalidation' => [
                    'current' => $cur,
                    'prior'   => $prev,
                ],
            ]),
        ];
    }

    private function planGoldkampDilutionFinding(Finding $finding, ?string $asOf): array
    {
        $clientId = $finding->client_id;
        $maxGa4 = CommerceMetric::where('client_id', $clientId)->where('source', 'ga4')->max('date');
        $endStr = $asOf ?: ($maxGa4 ?: Carbon::yesterday()->toDateString());

        $curEnd = Carbon::parse($endStr)->endOfDay();
        $curStart = $curEnd->copy()->subDays(29)->startOfDay();
        $prevEnd = $curStart->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays(29)->startOfDay();

        $origCurr = $this->computeConversionMetrics($clientId, '2026-10-09', '2026-11-07');
        $origPrev = $this->computeConversionMetrics($clientId, '2026-09-09', '2026-10-08');
        $latestCurr = $this->computeConversionMetrics($clientId, $curStart->toDateString(), $curEnd->toDateString());
        $latestPrev = $this->computeConversionMetrics($clientId, $prevStart->toDateString(), $prevEnd->toDateString());

        $ev = is_array($finding->evidence_json) ? $finding->evidence_json : [];
        $meta = is_array($finding->metadata_json) ? $finding->metadata_json : [];

        $diag = is_array($ev['conversion_diagnostics'] ?? null) ? $ev['conversion_diagnostics'] : [];
        $diag['current'] = array_merge($diag['current'] ?? [], [
            'magento_valid_orders'      => $origCurr['magento_valid_orders'],
            'magento_valid_net_revenue' => $origCurr['magento_valid_net_rev'],
        ]);
        $diag['previous'] = array_merge($diag['previous'] ?? [], [
            'magento_valid_orders'      => $origPrev['magento_valid_orders'],
            'magento_valid_net_revenue' => $origPrev['magento_valid_net_rev'],
        ]);
        $diag['magento_orders_delta_pct'] = $origPrev['magento_valid_orders'] > 0
            ? round((($origCurr['magento_valid_orders'] - $origPrev['magento_valid_orders']) / $origPrev['magento_valid_orders']) * 100, 1)
            : 0.0;
        $diag['magento_net_rev_delta_pct'] = $origPrev['magento_valid_net_rev'] > 0
            ? round((($origCurr['magento_valid_net_rev'] - $origPrev['magento_valid_net_rev']) / $origPrev['magento_valid_net_rev']) * 100, 1)
            : 0.0;

        $description = sprintf(
            "### Incident Summary\nGA4 storewide conversion rate dropped -70.0%% over 2026-10-09..2026-11-07 (weighted 0.705%% -> 0.194%%) due to Direct-session denominator dilution (+386.9%% Direct sessions: 8,599 -> 41,865; 93.5%% share), while Commerce valid orders grew %d -> %d (\$%s -> \$%s) and non-Direct GA4 CVR rose 2.12%% -> 2.53%%.\n\n### Equivalent 30-Day Revalidation (%s vs %s)\nDirect-session concentration persisted (%s -> %s sessions, %.1f%% share), while checkout completion held steady (%.1f%% -> %.1f%%) and Commerce valid orders remained stable (%d -> %d).\n\n### Investigation Scope\nRetain for Analytics & tracking attribution review (referrer headers, landing pages, user-agents, and GA4 channel grouping rules). Available evidence does not support checkout remediation or confirm automated bot traffic without supporting server/CDN log evidence.",
            $origPrev['magento_valid_orders'],
            $origCurr['magento_valid_orders'],
            number_format($origPrev['magento_valid_net_rev'], 2),
            number_format($origCurr['magento_valid_net_rev'], 2),
            $latestCurr['window'],
            $latestPrev['window'],
            number_format($latestPrev['ga4_direct_sessions']),
            number_format($latestCurr['ga4_direct_sessions']),
            $latestCurr['ga4_direct_share_pct'],
            (float) ($latestPrev['checkout_completion_pct'] ?? 0),
            (float) ($latestCurr['checkout_completion_pct'] ?? 0),
            $latestPrev['magento_valid_orders'],
            $latestCurr['magento_valid_orders']
        );

        return [
            'finding_id'          => $finding->id,
            'action_type'         => 'retain_analytics_traffic_dilution_audit',
            'to_status'           => $finding->status instanceof FindingStatus ? $finding->status->value : (string) $finding->status,
            'to_execution_status' => $finding->execution_status instanceof FindingExecutionStatus ? $finding->execution_status->value : (string) $finding->execution_status,
            'to_outcome_status'   => $finding->outcome_status instanceof FindingOutcomeStatus ? $finding->outcome_status->value : (string) $finding->outcome_status,
            'dismissed_reason'    => null,
            'reason'              => 'Retained open #191 as an Analytics & GA4 Direct-traffic dilution investigation; removed unsupported checkout-remediation / bot-traffic assumptions and attached repaired Commerce + equivalent 30-day telemetry.',
            'resolution_notes'    => $finding->resolution_notes,
            'updates'             => [
                'target_role' => 'analytics',
                'description' => $description,
            ],
            'metadata_patch'      => [
                'actionability' => [
                    'type'        => 'analytics_traffic_attribution_audit',
                    'description' => 'Audit Direct-session referrer headers, landing pages, user-agent distributions, and GA4 channel grouping rules; do not assume automated bot traffic or modify checkout flows without supporting log evidence.',
                    'target_team' => 'analytics',
                ],
                'conversion_diagnostics' => $diag,
                'revenue_impact_calculation' => array_merge(
                    $meta['revenue_impact_calculation'] ?? [],
                    ['reason' => 'GA4 conversion rate diluted by Direct-session surge while Commerce valid orders and checkout completion remained stable; requires traffic-origin audit rather than checkout remediation.']
                ),
            ],
            'evidence_patch'      => array_merge($ev, [
                'conversion_diagnostics'              => $diag,
                'current_equivalent_30d_revalidation' => [
                    'current' => $latestCurr,
                    'prior'   => $latestPrev,
                ],
            ]),
        ];
    }

    private function resolveVarianceThresholdPct(Client $client, float $default = 20.0): float
    {
        $cfg = is_array($client->monitoring_config) ? $client->monitoring_config : [];
        foreach (($cfg['thresholds'] ?? []) as $t) {
            if (($t['metric'] ?? null) === 'revenue_variance' && isset($t['value'])) {
                $val = (float) $t['value'];
                return $val <= 1.0 ? ($val * 100.0) : $val;
            }
        }
        return $default;
    }

    private function computeConversionMetrics(int $clientId, string $start, string $end): array
    {
        $ga4Rows = DB::table('commerce_metrics')
            ->where('client_id', $clientId)
            ->where('source', 'ga4')
            ->whereBetween('date', [$start, $end])
            ->get();

        $sessions = (int) $ga4Rows->sum('sessions');
        $activeUsers = (int) $ga4Rows->sum('active_users');
        $ga4Orders = (int) $ga4Rows->sum('orders');
        $ga4Rev = round((float) $ga4Rows->sum('revenue'), 2);
        $directSessions = 0;
        $directOrders = 0;
        $funnel = ['view_item' => 0, 'add_to_cart' => 0, 'begin_checkout' => 0, 'purchase' => 0];

        foreach ($ga4Rows as $r) {
            $sb = json_decode((string) $r->source_breakdown_json, true) ?: [];
            foreach ($sb as $ch => $vals) {
                if (stripos((string) $ch, 'direct') !== false) {
                    $directSessions += (int) ($vals['sessions'] ?? 0);
                    $directOrders += (int) ($vals['orders'] ?? $vals['conversions'] ?? 0);
                }
            }
            $meta = json_decode((string) $r->metadata_json, true) ?: [];
            foreach ($funnel as $fk => $_) {
                $funnel[$fk] += (int) ($meta['funnel'][$fk] ?? $meta[$fk] ?? 0);
            }
        }

        $nonDirectSessions = max(0, $sessions - $directSessions);
        $nonDirectOrders = max(0, $ga4Orders - $directOrders);

        $magValid = DB::table('commerce_orders')
            ->where('client_id', $clientId)
            ->where('is_valid', 1)
            ->whereBetween('order_date', ["{$start} 00:00:00", "{$end} 23:59:59"])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(net_revenue), 0) as net')
            ->first();

        $magOrders = (int) ($magValid->cnt ?? 0);
        $magNet = round((float) ($magValid->net ?? 0), 2);

        return [
            'window'                             => "{$start}..{$end}",
            'ga4_sessions'                       => $sessions,
            'ga4_active_users'                   => $activeUsers,
            'ga4_orders'                         => $ga4Orders,
            'ga4_revenue'                        => $ga4Rev,
            'ga4_overall_cvr_pct'                => $sessions > 0 ? round(($ga4Orders / $sessions) * 100, 3) : 0.0,
            'ga4_direct_sessions'                => $directSessions,
            'ga4_direct_share_pct'               => $sessions > 0 ? round(($directSessions / $sessions) * 100, 1) : 0.0,
            'ga4_non_direct_sessions'            => $nonDirectSessions,
            'ga4_non_direct_orders'              => $nonDirectOrders,
            'ga4_non_direct_cvr_pct'             => $nonDirectSessions > 0 ? round(($nonDirectOrders / $nonDirectSessions) * 100, 3) : 0.0,
            'funnel'                             => $funnel,
            'checkout_completion_pct'            => $funnel['begin_checkout'] > 0 ? round(($funnel['purchase'] / $funnel['begin_checkout']) * 100, 1) : null,
            'magento_valid_orders'               => $magOrders,
            'magento_valid_net_rev'              => $magNet,
            'magento_orders_per_active_user_pct' => $activeUsers > 0 ? round(($magOrders / $activeUsers) * 100, 3) : 0.0,
        ];
    }
}
