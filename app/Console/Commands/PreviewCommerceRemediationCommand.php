<?php

namespace App\Console\Commands;

use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\Integration;
use App\Services\Intelligence\CustomerIdentityResolverService;
use App\Services\Intelligence\CustomerPurchaseAnalysisService;
use App\Services\Intelligence\CustomerPurchaseFactsReconciler;
use App\Services\Intelligence\Detectors\AccountContractionDetector;
use App\Services\Intelligence\Detectors\B2bAccountReactivationDetector;
use App\Services\Intelligence\Detectors\LongTermWinbackDetector;
use App\Services\Intelligence\Detectors\ReplenishmentCadenceDetector;
use App\Services\Metrics\OrderQualificationPolicy;
use App\Services\Metrics\OrderQualificationResult;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 100% Read-Only Phase E Backfill & Remediation Impact Assessment.
 *
 * Reads current MySQL state via SELECT only, immediately disconnects MySQL,
 * and replays OrderQualificationPolicy, canonical fact reconciliation,
 * detector eligibility, and attribution variance inside an isolated
 * SQLite :memory: sandbox. Zero production rows are ever modified.
 */
class PreviewCommerceRemediationCommand extends Command
{
    protected $signature = 'forge:preview-commerce-remediation
        {--live-snapshot=/tmp/goldkamp_all_live_snapshot.json : Path to read-only Goldkamp Magento REST snapshot}
        {--output=/tmp/commerce_remediation_preview.json : Path to write JSON assessment report}
        {--format=table : Output format (table or json)}';

    protected $description = 'Run read-only Phase E historical backfill preview across Cambro, Transpart, and Goldkamp without mutating MySQL.';

    public function handle(): int
    {
        $snapshotPath = (string) $this->option('live-snapshot');
        $outputPath   = (string) $this->option('output');

        // 1. Snapshot Baseline from Production DB (100% SELECT-only)
        $sourceConn = DB::getDefaultConnection();

        $clients = DB::connection($sourceConn)->table('clients')->whereIn('id', [1, 2, 3, 4, 5])->orderBy('id')->get();
        $integrations = DB::connection($sourceConn)->table('integrations')->whereIn('client_id', [1, 2, 5])->orderBy('id')->get();
        $orders = DB::connection($sourceConn)->table('commerce_orders')->whereIn('client_id', [1, 2, 5])->orderBy('id')->get();
        $facts = DB::connection($sourceConn)->table('commerce_customer_purchase_facts')->whereIn('client_id', [1, 2, 5])->orderBy('id')->get();
        $mappings = DB::connection($sourceConn)->table('commerce_customer_identity_mappings')->whereIn('client_id', [1, 2, 5])->orderBy('id')->get();
        $commerceMetrics = DB::connection($sourceConn)->table('commerce_metrics')->whereIn('client_id', [1, 2, 5])->orderBy('date')->get();
        $ga4Metrics = $commerceMetrics->where('client_id', 5)->where('source', 'ga4');
        $findings = DB::connection($sourceConn)->table('findings')->whereIn('client_id', [1, 2, 5])->orderBy('id')->get();
        $outcomesCount = DB::connection($sourceConn)->table('recommendation_outcomes')->count();

        $beforeCheck = [
            'orders_count'   => $orders->count(),
            'facts_count'    => $facts->count(),
            'metrics_count'  => $commerceMetrics->count(),
            'findings_count' => $findings->count(),
            'outcomes_count' => $outcomesCount,
        ];

        // Load live Magento snapshot for Goldkamp
        $liveSnapshot = file_exists($snapshotPath) ? (json_decode(file_get_contents($snapshotPath), true) ?: []) : [];
        $liveByEntity = [];
        foreach (['invalid_confirmed_172', 'recent_since_oct25'] as $bucket) {
            foreach (($liveSnapshot[$bucket] ?? []) as $eid => $payload) {
                $liveByEntity[(string) $eid] = $payload;
            }
        }

        // Sever MySQL connection completely so no simulation step can accidentally write to production
        DB::disconnect($sourceConn);
        DB::purge($sourceConn);

        // 2. Spin up isolated in-memory SQLite database
        Config::set('database.connections.preview_sqlite', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => false,
        ]);
        DB::setDefaultConnection('preview_sqlite');
        DB::reconnect('preview_sqlite');
        DB::connection('preview_sqlite')->disableQueryLog();

        Artisan::call('migrate', ['--database' => 'preview_sqlite', '--force' => true]);

        if (DB::getDefaultConnection() !== 'preview_sqlite' || DB::connection('preview_sqlite')->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Safety abort: preview sandbox failed isolation check.');
        }

        // Clear any migration-seeded fixture rows in the :memory: SQLite sandbox before loading snapshot
        foreach (['findings', 'commerce_metrics', 'commerce_customer_identity_mappings', 'commerce_customer_purchase_facts_archive', 'commerce_customer_purchase_facts', 'commerce_orders', 'integrations', 'clients'] as $tbl) {
            DB::connection('preview_sqlite')->table($tbl)->delete();
        }

        // Add covering indexes in :memory: SQLite so 15,767-order profile lookups execute in milliseconds
        DB::connection('preview_sqlite')->statement('CREATE INDEX IF NOT EXISTS idx_prev_ord_canon ON commerce_orders (client_id, is_valid, canonical_account_id, order_date)');
        DB::connection('preview_sqlite')->statement('CREATE INDEX IF NOT EXISTS idx_prev_ord_hash ON commerce_orders (client_id, is_valid, customer_identity_hash, order_date)');
        DB::connection('preview_sqlite')->statement('CREATE INDEX IF NOT EXISTS idx_prev_fact_hash ON commerce_customer_purchase_facts (client_id, customer_identity_hash)');

        // Seed sandbox with Baseline data
        $this->seedTable('clients', $clients);
        $this->seedTable('integrations', $integrations);
        $this->seedTable('commerce_orders', $orders);
        $this->seedTable('commerce_customer_purchase_facts', $facts);
        $this->seedTable('commerce_customer_identity_mappings', $mappings);
        $this->seedTable('commerce_metrics', $commerceMetrics);
        $this->seedTable('findings', $findings);

        // Snapshot BEFORE state (both DB facts + current detectors)
        $beforeDetectorCounts = [];
        $beforeWinbackAccounts = [];
        foreach ([1, 2, 5] as $cid) {
            $clientModel = Client::findOrFail($cid);
            $beforeDetectorCounts[$cid] = $this->runDetectorsForClient($clientModel);
            if ($cid === 5) {
                $beforeWinbackAccounts = $this->evaluateGoldkampActiveWinbackFindings($clientModel, $findings->where('client_id', 5));
            }
        }

        // 3. Evaluate Option 1 (Recommended Client Policies)
        $policy = app(OrderQualificationPolicy::class);
        $identityResolver = app(CustomerIdentityResolverService::class);
        $reconciler = app(CustomerPurchaseFactsReconciler::class);

        $option1ClientConfigs = [
            1 => [
                'order_settings' => [
                    'eligible_statuses'              => ['complete', 'processing'],
                    'excluded_statuses'              => OrderQualificationPolicy::DEFAULT_EXCLUDED_STATUSES,
                    'b2b_credit_term_methods'        => OrderQualificationPolicy::DEFAULT_B2B_CREDIT_TERM_METHODS,
                    'allow_authorized_b2b_confirmed' => false,
                    'allow_invoiced_pending_payment' => false,
                ],
            ],
            2 => [
                'order_settings' => [
                    'eligible_statuses'              => ['complete', 'processing'],
                    'excluded_statuses'              => OrderQualificationPolicy::DEFAULT_EXCLUDED_STATUSES,
                    'b2b_credit_term_methods'        => OrderQualificationPolicy::DEFAULT_B2B_CREDIT_TERM_METHODS,
                    'allow_authorized_b2b_confirmed' => false,
                    'allow_invoiced_pending_payment' => false,
                ],
            ],
            5 => [
                'order_settings' => [
                    'eligible_statuses'              => ['complete', 'processing', 'confirmed'],
                    'excluded_statuses'              => OrderQualificationPolicy::DEFAULT_EXCLUDED_STATUSES,
                    'b2b_credit_term_methods'        => OrderQualificationPolicy::DEFAULT_B2B_CREDIT_TERM_METHODS,
                    'b2b_authorized_statuses'        => ['confirmed'],
                    'allow_authorized_b2b_confirmed' => false,
                    'allow_invoiced_pending_payment' => true,
                ],
            ],
        ];

        $option1Results = $this->simulatePolicyAcrossClients(
            clientConfigs: $option1ClientConfigs,
            liveByEntity: $liveByEntity,
            policy: $policy,
            identityResolver: $identityResolver,
            reconciler: $reconciler,
            captureWinbackFindings: $findings->where('client_id', 5)
        );

        // 4. Evaluate Option 2 for Goldkamp (+ allow_authorized_b2b_confirmed = true) by resetting sandbox orders & facts
        $this->seedResetOrdersAndFacts($clients, $integrations, $orders, $facts, $mappings);
        $option2ClientConfigs = $option1ClientConfigs;
        $option2ClientConfigs[5]['order_settings']['allow_authorized_b2b_confirmed'] = true;

        $option2Results = $this->simulatePolicyAcrossClients(
            clientConfigs: $option2ClientConfigs,
            liveByEntity: $liveByEntity,
            policy: $policy,
            identityResolver: $identityResolver,
            reconciler: $reconciler,
            captureWinbackFindings: $findings->where('client_id', 5)
        );

        // Compute Goldkamp 26 Oct 25–Nov 23 and 172 historical invalid confirmed breakdowns directly from live snapshot
        $goldkampConfirmedAudit = $this->auditGoldkampConfirmedCohorts(
            gkOrders: $orders->where('client_id', 5),
            liveByEntity: $liveByEntity,
            policy: $policy,
            opt1Config: $option1ClientConfigs[5],
            opt2Config: $option2ClientConfigs[5]
        );

        // Compute GA4 vs Commerce reconciliation window impacts
        $ga4VarianceImpact = $this->computeGa4VarianceWindows(
            ga4Metrics: $ga4Metrics,
            beforeOrders: $orders->where('client_id', 5),
            opt1Orders: $option1Results['orders_by_client'][5],
            opt2Orders: $option2Results['orders_by_client'][5],
            gkFindings: $findings->where('client_id', 5)
        );

        // Combine Winback Before vs After table
        $winbackComparison = [];
        foreach ($beforeWinbackAccounts as $fid => $bRow) {
            $a1 = $option1Results['goldkamp_winback'][$fid] ?? [];
            $a2 = $option2Results['goldkamp_winback'][$fid] ?? [];
            $winbackComparison[] = [
                'finding_id'         => $fid,
                'status'             => $bRow['status'],
                'hash'               => $bRow['hash'],
                'canonical_id'       => $a1['canonical_id'] ?? $bRow['canonical_id'],
                'title'              => $bRow['title'],
                'before_orders'      => $bRow['orders'],
                'before_spend'       => $bRow['spend'],
                'before_last_order'  => $bRow['last_order'],
                'before_dormancy_d'  => $bRow['dormancy_days'],
                'before_impact'      => $bRow['impact'],
                'before_disposition' => $bRow['disposition'],
                'opt1_orders'        => $a1['orders'] ?? null,
                'opt1_spend'         => $a1['spend'] ?? null,
                'opt1_last_order'    => $a1['last_order'] ?? null,
                'opt1_dormancy_d'    => $a1['dormancy_days'] ?? null,
                'opt1_qualifying'    => $a1['is_qualifying'] ?? null,
                'opt1_disposition'   => $a1['disposition'] ?? null,
                'opt1_impact'        => $a1['impact'] ?? null,
                'opt2_orders'        => $a2['orders'] ?? null,
                'opt2_spend'         => $a2['spend'] ?? null,
                'opt2_qualifying'    => $a2['is_qualifying'] ?? null,
                'opt2_disposition'   => $a2['disposition'] ?? null,
                'changed_under_opt1' => (
                    ($bRow['orders'] !== ($a1['orders'] ?? null))
                    || abs($bRow['spend'] - (float) ($a1['spend'] ?? 0)) > 0.01
                    || (($bRow['last_order'] ?? null) !== ($a1['last_order'] ?? null))
                ),
            ];
        }

        // Format Before vs After per-client comparison
        $clientSummaries = [];
        foreach ([1 => 'Cambro', 2 => 'Transpart', 5 => 'GoldKamp'] as $cid => $name) {
            $bOrders = $orders->where('client_id', $cid);
            $bValid = $bOrders->where('is_valid', 1);
            $bFacts = $facts->where('client_id', $cid);

            $clientSummaries[$cid] = [
                'client_id'   => $cid,
                'client_name' => $name,
                'before'      => [
                    'total_orders'             => $bOrders->count(),
                    'valid_orders'             => $bValid->count(),
                    'valid_net_revenue'        => round((float) $bValid->sum('net_revenue'), 2),
                    'stored_fact_rows'         => $bFacts->count(),
                    'stored_repeat_customers'  => $bFacts->where('lifetime_valid_order_count', '>=', 2)->count(),
                    'detector_counts'          => $beforeDetectorCounts[$cid],
                ],
                'after_option1_recommended' => $option1Results['client_stats'][$cid],
                'after_option2_b2b_auth'    => $cid === 5 ? $option2Results['client_stats'][$cid] : null,
            ];
        }

        $report = [
            'generated_at'           => now()->toIso8601String(),
            'mode'                   => 'read_only_sqlite_sandbox',
            'live_snapshot_orders'   => count($liveByEntity),
            'production_baseline'    => $beforeCheck,
            'client_summaries'       => $clientSummaries,
            'goldkamp_confirmed'     => $goldkampConfirmedAudit,
            'goldkamp_17_winback'    => $winbackComparison,
            'ga4_variance_windows'   => $ga4VarianceImpact,
            'outcome_measurement'    => [
                'existing_completed_findings_in_db' => 0,
                'existing_recommendation_outcomes'  => $outcomesCount,
                'impact_summary'                    => 'No historical recommendation outcomes require backfill mutation; future evaluations will match both registered and verified guest orders via canonical account identity and fail closed when verified identifiers are absent.',
            ],
        ];

        file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info("Read-only Phase E Preview written to {$outputPath}");
        foreach ($clientSummaries as $s) {
            $b = $s['before'];
            $a1 = $s['after_option1_recommended'];
            $this->line(sprintf(
                "%-10s | Before: valid=%d net=$%s facts=%d repeat=%d | After(Opt1): valid=%d net=$%s paid=$%s inv=$%s rec=$%s facts=%d repeat=%d (merged=%d, arch=%d)",
                $s['client_name'],
                $b['valid_orders'],
                number_format($b['valid_net_revenue'], 2),
                $b['stored_fact_rows'],
                $b['stored_repeat_customers'],
                $a1['valid_orders'],
                number_format($a1['valid_net_revenue'], 2),
                number_format($a1['paid_revenue'], 2),
                number_format($a1['invoiced_revenue'], 2),
                number_format($a1['outstanding_receivable'], 2),
                $a1['canonical_fact_rows'],
                $a1['repeat_customers'],
                $a1['merged_or_archived_facts'],
                $a1['archived_orphan_facts']
            ));
        }

        return self::SUCCESS;
    }

    private function seedTable(string $table, $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }
        $cols = array_flip(Schema::getColumnListing($table));
        $batch = [];
        foreach ($rows as $row) {
            $arr = array_intersect_key((array) $row, $cols);
            $batch[] = $arr;
        }
        foreach (array_chunk($batch, 250) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function seedResetOrdersAndFacts($clients, $integrations, $orders, $facts, $mappings): void
    {
        DB::table('commerce_orders')->delete();
        DB::table('commerce_customer_purchase_facts')->delete();
        DB::table('commerce_customer_purchase_facts_archive')->delete();
        DB::table('commerce_customer_identity_mappings')->delete();
        DB::table('clients')->delete();
        DB::table('integrations')->delete();

        $this->seedTable('clients', $clients);
        $this->seedTable('integrations', $integrations);
        $this->seedTable('commerce_orders', $orders);
        $this->seedTable('commerce_customer_purchase_facts', $facts);
        $this->seedTable('commerce_customer_identity_mappings', $mappings);
    }

    private function simulatePolicyAcrossClients(
        array $clientConfigs,
        array $liveByEntity,
        OrderQualificationPolicy $policy,
        CustomerIdentityResolverService $identityResolver,
        CustomerPurchaseFactsReconciler $reconciler,
        $captureWinbackFindings
    ): array {
        $clientStats = [];
        $ordersByClient = [];
        $goldkampWinback = [];

        foreach ($clientConfigs as $cid => $cfg) {
            $clientModel = Client::findOrFail($cid);
            $clientModel->monitoring_config = array_merge($clientModel->monitoring_config ?? [], $cfg);
            $clientModel->save();

            $integration = Integration::where('client_id', $cid)->where('integration_type', 'adobe_commerce')->first();
            if ($integration) {
                $integration->monitoring_config = array_merge($integration->monitoring_config ?? [], $cfg);
                $integration->save();
            }

            $allOrders = CommerceOrder::where('client_id', $cid)->orderBy('id')->get();
            $paidTotal = 0.0;
            $invoicedTotal = 0.0;
            $receivableTotal = 0.0;
            $validNetTotal = 0.0;
            $validCount = 0;
            $classCounts = [];
            $reasonCounts = [];
            $liveUpdatedCount = 0;
            $snapshotForClient = [];
            $identityMemo = [];
            $registeredMemo = [];

            DB::connection('preview_sqlite')->transaction(function () use (
                $allOrders, $cid, $clientModel, $integration, $liveByEntity, $policy, $identityResolver,
                &$paidTotal, &$invoicedTotal, &$receivableTotal, &$validNetTotal, &$validCount,
                &$classCounts, &$reasonCounts, &$liveUpdatedCount, &$snapshotForClient, &$identityMemo, &$registeredMemo
            ) {
                foreach ($allOrders as $ord) {
                    $payload = [
                        'status'          => $ord->order_status,
                        'grand_total'     => (float) $ord->gross_revenue,
                        'total_refunded'  => (float) $ord->refunded_revenue,
                        'is_valid_legacy' => (bool) $ord->is_valid,
                    ];

                    $custId = $ord->registered_customer_id;
                    $custEmail = $ord->customer_email;
                    $orderDate = $ord->order_date;
                    $isLiveOverridden = false;

                    if ($cid === 5 && isset($liveByEntity[(string) $ord->source_order_id])) {
                        $live = $liveByEntity[(string) $ord->source_order_id];
                        $liveUpdatedCount++;
                        $isLiveOverridden = true;
                        $payload = $live;
                        if (! empty($live['customer_id'])) {
                            $custId = (string) $live['customer_id'];
                        }
                        if (! empty($live['customer_email'])) {
                            $custEmail = $live['customer_email'];
                        }
                    } elseif (! empty($ord->metadata_json) && is_array($ord->metadata_json) && array_key_exists('order_state', $ord->metadata_json)) {
                        $meta = $ord->metadata_json;
                        $payload['state'] = $meta['order_state'] ?? null;
                        $payload['payment_method'] = $meta['payment_method'] ?? null;
                        $payload['base_total_invoiced'] = $meta['invoiced_revenue'] ?? 0;
                        $payload['base_total_paid'] = $meta['paid_revenue'] ?? 0;
                        $payload['base_total_canceled'] = $meta['canceled_revenue'] ?? 0;
                        $payload['base_amount_authorized'] = $meta['base_amount_authorized'] ?? 0;
                    }

                    $qual = $policy->evaluate($payload, $integration ?? $clientModel);

                    $memoKey = ($custId ?? '') . '|' . strtolower(trim((string) ($custEmail ?? ''))) . '|' . ($ord->customer_identity_hash ?? '');
                    $canonicalId = $ord->canonical_account_id;
                    if (empty($canonicalId)) {
                        $canonicalId = $identityMemo[$memoKey] ??= $identityResolver->resolveCanonicalAccountId($clientModel, $custId, $custEmail, $ord->customer_identity_hash);
                    }

                    if (! empty($custId) && ! empty($canonicalId) && ! isset($registeredMemo[$memoKey])) {
                        $identityResolver->registerAccountIdentities($clientModel, $canonicalId, $custId, $custEmail, array_filter([$ord->customer_identity_hash]));
                        $registeredMemo[$memoKey] = true;
                    }

                    $newNet = $qual->isValid ? $qual->qualifyingNetRevenue : 0.0;

                    if (
                        $isLiveOverridden
                        || (int) $ord->is_valid !== ($qual->isValid ? 1 : 0)
                        || abs((float) $ord->net_revenue - $newNet) > 0.009
                        || $ord->canonical_account_id !== $canonicalId
                        || $ord->order_status !== $qual->orderStatus
                    ) {
                        DB::connection('preview_sqlite')->table('commerce_orders')->where('id', $ord->id)->update([
                            'order_status'                 => $qual->orderStatus,
                            'order_state'                  => $qual->orderState,
                            'payment_method'               => $qual->paymentMethod,
                            'qualification_classification' => $qual->classification,
                            'canonical_account_id'         => $canonicalId,
                            'registered_customer_id'       => $custId,
                            'gross_revenue'                => $qual->grossRevenue,
                            'refunded_revenue'             => $qual->refundedRevenue,
                            'net_revenue'                  => $newNet,
                            'invoiced_revenue'             => $qual->invoicedRevenue,
                            'paid_revenue'                 => $qual->paidRevenue,
                            'canceled_revenue'             => $qual->canceledRevenue,
                            'outstanding_receivable'       => $qual->outstandingReceivable,
                            'is_valid'                     => $qual->isValid ? 1 : 0,
                            'exclusion_reason'             => $qual->exclusionReason,
                        ]);
                    }

                    $classCounts[$qual->classification] = ($classCounts[$qual->classification] ?? 0) + 1;
                    if (! $qual->isValid && $qual->exclusionReason) {
                        $reasonCounts[$qual->exclusionReason] = ($reasonCounts[$qual->exclusionReason] ?? 0) + 1;
                    }

                    if ($qual->isValid) {
                        $validCount++;
                        $validNetTotal += $newNet;
                        $paidTotal += $qual->paidRevenue;
                        $invoicedTotal += $qual->invoicedRevenue;
                        $receivableTotal += $qual->outstandingReceivable;
                    }

                    $snapshotForClient[] = (object) [
                        'source_order_id'   => $ord->source_order_id,
                        'order_date'        => $orderDate,
                        'order_status'      => $qual->orderStatus,
                        'is_valid'          => $qual->isValid ? 1 : 0,
                        'net_revenue'       => $newNet,
                        'gross_revenue'     => $qual->grossRevenue,
                        'classification'    => $qual->classification,
                        'exclusion_reason'  => $qual->exclusionReason,
                    ];
                }
            });

            // Reconcile canonical purchase facts via refactored CustomerPurchaseFactsReconciler
            $recResult = $reconciler->reconcileClient($clientModel, false);
            $detectorCounts = $this->runDetectorsForClient($clientModel);

            $archivedRows = DB::table('commerce_customer_purchase_facts_archive')
                ->where('client_id', $cid)
                ->selectRaw('archive_reason, COUNT(*) as cnt')
                ->groupBy('archive_reason')
                ->pluck('cnt', 'archive_reason')
                ->all();

            $clientStats[$cid] = [
                'total_orders'              => $allOrders->count(),
                'disable_builtin_detectors' => (bool) ($clientModel->monitoring_config['disable_builtin_detectors'] ?? false),
                'live_snapshot_matched'     => $liveUpdatedCount,
                'valid_orders'             => $validCount,
                'valid_net_revenue'        => round($validNetTotal, 2),
                'paid_revenue'             => round($paidTotal, 2),
                'invoiced_revenue'         => round($invoicedTotal, 2),
                'outstanding_receivable'   => round($receivableTotal, 2),
                'classifications'          => $classCounts,
                'exclusion_reasons'        => $reasonCounts,
                'canonical_fact_rows'      => $recResult['post_reconciled_fact_count'],
                'repeat_customers'         => $recResult['authoritative_repeat_customers'],
                'repeat_rate_pct'          => $recResult['authoritative_repeat_rate_pct'],
                'merged_or_archived_facts' => $recResult['orphaned_facts_to_archive'],
                'archived_orphan_facts'    => (int) ($archivedRows['reconciliation_orphaned_hash'] ?? 0),
                'archived_merged_facts'    => (int) ($archivedRows['reconciliation_canonical_merged'] ?? 0),
                'facts_balanced'           => $recResult['is_balanced'],
                'detector_counts'          => $detectorCounts,
            ];

            $ordersByClient[$cid] = collect($snapshotForClient);

            if ($cid === 5) {
                $goldkampWinback = $this->evaluateGoldkampActiveWinbackFindings($clientModel, $captureWinbackFindings);
            }
        }

        return [
            'client_stats'     => $clientStats,
            'orders_by_client' => $ordersByClient,
            'goldkamp_winback' => $goldkampWinback,
        ];
    }

    private function runDetectorsForClient(Client $client): array
    {
        $analysisService = app(CustomerPurchaseAnalysisService::class);
        $analysisService->clearCache();

        $winbackConfig = config('intelligence.opportunity_detectors.opportunity.long_term_winback', []);
        $reactConfig   = config('intelligence.opportunity_detectors.opportunity.b2b_account_reactivation', []);
        $replConfig    = config('intelligence.opportunity_detectors.opportunity.replenishment_due', []);
        $contrConfig   = config('intelligence.opportunity_detectors.risk.account_contraction', []);

        return [
            'long_term_winback'        => app(LongTermWinbackDetector::class)->detect($client, $winbackConfig, $analysisService)->count(),
            'b2b_account_reactivation' => app(B2bAccountReactivationDetector::class)->detect($client, $reactConfig, $analysisService)->count(),
            'replenishment_due'        => app(ReplenishmentCadenceDetector::class)->detect($client, $replConfig, $analysisService)->count(),
            'account_contraction'      => app(AccountContractionDetector::class)->detect($client, $contrConfig, $analysisService)->count(),
        ];
    }

    private function evaluateGoldkampActiveWinbackFindings(Client $client, $gkFindings): array
    {
        $analysisService = app(CustomerPurchaseAnalysisService::class);
        $winbackConfig = config('intelligence.opportunity_detectors.opportunity.long_term_winback', []);
        $refDate = $client->timezone ? Carbon::now($client->timezone) : Carbon::now();

        $activeWinbacks = $gkFindings->filter(function ($f) {
            return (str_contains((string) $f->source_id, 'winback') || str_contains((string) $f->finding_type, 'winback') || str_contains((string) $f->title, 'Winback'))
                && $f->status !== FindingStatus::Dismissed->value;
        });

        $results = [];
        foreach ($activeWinbacks as $f) {
            $meta = is_string($f->metadata_json) ? (json_decode($f->metadata_json, true) ?: []) : (array) $f->metadata_json;
            $hash = $f->account_identity_hash ?: ($meta['account_identity_hash'] ?? null);
            $eval = $hash ? LongTermWinbackDetector::evaluateAccount($client, $hash, $analysisService, $winbackConfig, $refDate) : null;

            $results[$f->id] = [
                'finding_id'    => $f->id,
                'status'        => $f->status,
                'title'         => $f->title,
                'hash'          => $hash,
                'canonical_id'  => $eval['canonical_account_id'] ?? ($meta['canonical_account_id'] ?? null),
                'orders'        => $eval['order_count'] ?? ($meta['order_count'] ?? null),
                'spend'         => $eval['lifetime_net_purchasing_value'] ?? ($meta['lifetime_net_purchasing_value'] ?? null),
                'last_order'    => isset($eval['latest_order_at']) ? substr((string) $eval['latest_order_at'], 0, 10) : null,
                'dormancy_days' => $eval['days_since_last_order'] ?? ($meta['days_since_last_order'] ?? null),
                'impact'        => $eval['historical_episode_benchmark'] ?? (float) $f->estimated_revenue_impact,
                'is_qualifying' => $eval['is_qualifying'] ?? false,
                'disposition'   => $eval['disposition'] ?? 'Missing Fact / Zero Valid Orders',
            ];
        }

        return $results;
    }

    private function auditGoldkampConfirmedCohorts(
        $gkOrders,
        array $liveByEntity,
        OrderQualificationPolicy $policy,
        array $opt1Config,
        array $opt2Config
    ): array {
        $opt1Client = new Client(['id' => 5, 'monitoring_config' => $opt1Config]);
        $opt2Client = new Client(['id' => 5, 'monitoring_config' => $opt2Config]);

        $oct25Nov23 = $gkOrders->filter(fn ($o) => $o->order_status === 'confirmed' && (int) $o->is_valid === 0 && $o->order_date >= '2026-10-25 00:00:00' && $o->order_date <= '2026-11-23 23:59:59');
        $postNov23  = $gkOrders->filter(fn ($o) => $o->order_status === 'confirmed' && (int) $o->is_valid === 0 && $o->order_date > '2026-11-23 23:59:59');
        $hist172    = $gkOrders->filter(fn ($o) => $o->order_status === 'confirmed' && (int) $o->is_valid === 0);

        $summarize = function ($subset) use ($liveByEntity, $policy, $opt1Client, $opt2Client) {
            $count = 0;
            $localGross = 0.0;
            $opt1Valid = 0;
            $opt1Net = 0.0;
            $opt1Paid = 0.0;
            $opt2Valid = 0;
            $opt2Net = 0.0;
            $opt1Classes = [];
            $opt1Reasons = [];

            foreach ($subset as $o) {
                $count++;
                $localGross += (float) $o->gross_revenue;
                $payload = $liveByEntity[(string) $o->source_order_id] ?? [
                    'status'         => $o->order_status,
                    'grand_total'    => (float) $o->gross_revenue,
                    'total_refunded' => (float) $o->refunded_revenue,
                ];

                $r1 = $policy->evaluate($payload, $opt1Client);
                $r2 = $policy->evaluate($payload, $opt2Client);

                $opt1Classes[$r1->classification] = ($opt1Classes[$r1->classification] ?? 0) + 1;
                if (! $r1->isValid && $r1->exclusionReason) {
                    $opt1Reasons[$r1->exclusionReason] = ($opt1Reasons[$r1->exclusionReason] ?? 0) + 1;
                }
                if ($r1->isValid) {
                    $opt1Valid++;
                    $opt1Net += $r1->qualifyingNetRevenue;
                    $opt1Paid += $r1->paidRevenue;
                }
                if ($r2->isValid) {
                    $opt2Valid++;
                    $opt2Net += $r2->qualifyingNetRevenue;
                }
            }

            return [
                'order_count'       => $count,
                'local_gross'       => round($localGross, 2),
                'opt1_valid_count'  => $opt1Valid,
                'opt1_net_revenue'  => round($opt1Net, 2),
                'opt1_paid_revenue' => round($opt1Paid, 2),
                'opt1_classes'      => $opt1Classes,
                'opt1_exclusions'   => $opt1Reasons,
                'opt2_valid_count'  => $opt2Valid,
                'opt2_net_revenue'  => round($opt2Net, 2),
            ];
        };

        return [
            'excluded_oct25_nov23_26'     => $summarize($oct25Nov23),
            'excluded_post_nov23_2'       => $summarize($postNov23),
            'all_local_invalid_172'       => $summarize($hist172),
        ];
    }

    private function computeGa4VarianceWindows($ga4Metrics, $beforeOrders, $opt1Orders, $opt2Orders, $gkFindings): array
    {
        $windows = [
            'finding_280_window' => ['start' => '2026-10-12', 'end' => '2026-11-10'],
            'finding_187_window' => ['start' => '2026-10-09', 'end' => '2026-11-07'],
            'oct25_nov23_window' => ['start' => '2026-10-25', 'end' => '2026-11-23'],
            'oct27_nov25_window' => ['start' => '2026-10-27', 'end' => '2026-11-25'],
        ];

        $out = [];
        foreach ($windows as $label => $w) {
            $ga4Rev = round((float) $ga4Metrics->filter(fn ($m) => $m->date >= $w['start'] && $m->date <= $w['end'])->sum('revenue'), 2);
            $calc = function ($ordCollection) use ($w, $ga4Rev) {
                $valid = $ordCollection->filter(fn ($o) => (int) $o->is_valid === 1 && substr((string) $o->order_date, 0, 10) >= $w['start'] && substr((string) $o->order_date, 0, 10) <= $w['end']);
                $commNet = round((float) $valid->sum('net_revenue'), 2);
                $diffAmt = round($ga4Rev - $commNet, 2);
                $varPct  = $commNet > 0 ? round((abs($diffAmt) / $commNet) * 100, 1) : null;
                return [
                    'valid_orders'    => $valid->count(),
                    'commerce_net'    => $commNet,
                    'ga4_revenue'     => $ga4Rev,
                    'diff_amount'     => $diffAmt,
                    'variance_pct'    => $varPct,
                ];
            };

            $out[$label] = [
                'range'   => "{$w['start']}..{$w['end']}",
                'before'  => $calc($beforeOrders),
                'option1' => $calc($opt1Orders),
                'option2' => $calc($opt2Orders),
            ];
        }

        // Capture storewide revenue decline finding if present
        $revDrop = $gkFindings->first(fn ($f) => str_contains((string) $f->finding_type, 'revenue_drop') || str_contains((string) $f->source_id, 'revenue_drop'));
        $out['storewide_revenue_drop_finding'] = $revDrop ? [
            'finding_id' => $revDrop->id,
            'title'      => $revDrop->title,
            'impact'     => $revDrop->estimated_revenue_impact,
        ] : null;

        return $out;
    }
}
