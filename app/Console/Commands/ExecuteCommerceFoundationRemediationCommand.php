<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\Integration;
use App\Services\Configuration\ClientConfigurationManager;
use App\Services\Connectors\AdobeCommerceConnector;
use App\Services\Intelligence\CustomerPurchaseFactsReconciler;
use App\Services\Metrics\OrderQualificationPolicy;
use App\Services\Security\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExecuteCommerceFoundationRemediationCommand extends Command
{
    protected $signature = 'forge:remediate-commerce-foundation
                            {--client=* : Client IDs to remediate (default: 1, 2, 5)}
                            {--batch-size=500 : Orders per transactional batch upsert}
                            {--live-dir=/tmp : Directory containing verified live Magento REST JSON snapshots}
                            {--dry-run : Compute bridges and financials without mutating database}';

    protected $description = 'Execute idempotent, source-backed Phase E commerce foundation remediation, policy configuration, PII containment, metric rebuild, and fact reconciliation.';

    public function handle(
        OrderQualificationPolicy $policy,
        ClientConfigurationManager $configManager,
        CustomerPurchaseFactsReconciler $reconciler,
        CustomerIdentityHasher $hasher,
    ): int {
        $dryRun    = (bool) $this->option('dry-run');
        $batchSize = max(50, min(1000, (int) $this->option('batch-size')));
        $liveDir   = rtrim((string) $this->option('live-dir'), '/');

        $rawClients = $this->option('client');
        $clientIds  = ! empty($rawClients)
            ? array_values(array_unique(array_map('intval', $rawClients)))
            : [1, 2, 5];

        $policies = $this->approvedPolicies();
        $hasExtendedCols = Schema::hasColumn('commerce_orders', 'order_state');
        $hasPhaseECols   = Schema::hasColumn('commerce_orders', 'settlement_unverified_revenue');

        $summary = [];

        foreach ($clientIds as $cid) {
            if (! isset($policies[$cid])) {
                $this->warn("No approved Phase E policy defined for client #{$cid}; skipping.");
                continue;
            }

            $client      = Client::findOrFail($cid);
            $integration = Integration::where('client_id', $cid)
                ->where('integration_type', 'adobe_commerce')
                ->firstOrFail();

            if ((int) $integration->client_id !== (int) $client->id) {
                $this->error("Fail-closed: tenant mismatch on integration #{$integration->id}.");
                return self::FAILURE;
            }

            $liveMap = $this->loadLiveOrders($cid, $liveDir);
            if (empty($liveMap)) {
                $this->error("Fail-closed: live source snapshot missing or empty for client #{$cid} in {$liveDir}.");
                return self::FAILURE;
            }

            $policyConfig = $policies[$cid];
            $evalClient   = clone $client;
            $evalClient->monitoring_config = $configManager->mergePreservingProtectedKeys(
                is_array($client->monitoring_config) ? $client->monitoring_config : [],
                $policyConfig,
                ClientConfigurationManager::PROTECTED_CLIENT_KEYS
            );
            $evalIntegration = clone $integration;
            $evalIntegration->setRelation('client', $evalClient);
            $evalIntegration->monitoring_config = $configManager->mergePreservingProtectedKeys(
                is_array($integration->monitoring_config) ? $integration->monitoring_config : [],
                $policyConfig,
                ClientConfigurationManager::PROTECTED_INTEGRATION_KEYS
            );

            // Load pre-remediation local orders and identity mappings
            $localRows = DB::table('commerce_orders')
                ->where('client_id', $cid)
                ->orderBy('id')
                ->get()
                ->keyBy('source_order_id');

            $existingMappings = $cid === 2
                ? collect() // Transpart PII contamination requires clean mapping rebuild
                : DB::table('commerce_customer_identity_mappings')
                    ->where('client_id', $cid)
                    ->where('is_active', true)
                    ->get(['identity_type', 'identity_value', 'canonical_account_id']);

            $mapByCid   = [];
            $mapByEmail = [];
            $mapByHash  = [];
            foreach ($existingMappings as $m) {
                if ($m->identity_type === 'registered_customer_id') {
                    $mapByCid[$m->identity_value] = $m->canonical_account_id;
                } elseif ($m->identity_type === 'normalized_email') {
                    $mapByEmail[$m->identity_value] = $m->canonical_account_id;
                } elseif ($m->identity_type === 'source_identity_hash') {
                    $mapByHash[$m->identity_value] = $m->canonical_account_id;
                }
            }

            $hmacKey     = $hasher->getClientHmacKey($client);
            $nowStr      = Carbon::now()->toDateTimeString();
            $minLocalDay = $localRows->isNotEmpty() ? substr((string) $localRows->min('order_date'), 0, 10) : null;

            $beforeValid = 0;
            $beforeNet   = 0.0;
            foreach ($localRows as $lo) {
                if ((int) $lo->is_valid === 1) {
                    $beforeValid++;
                    $beforeNet = round($beforeNet + (float) $lo->net_revenue, 2);
                }
            }

            $afterStats = [
                'total_orders'                  => 0,
                'valid_orders'                  => 0,
                'qualifying_order_value'        => 0.0,
                'net_merchandise_revenue'       => 0.0,
                'paid_revenue'                  => 0.0,
                'invoiced_revenue'              => 0.0,
                'outstanding_receivable'        => 0.0,
                'settlement_unverified_revenue' => 0.0,
                'refundable_core_deposit'       => 0.0,
                'core_deposit_refunded'         => 0.0,
                'outstanding_core_deposit'      => 0.0,
                'classifications'               => [],
                'exclusion_reasons'             => [],
            ];

            $bridge = [
                'matched_live'              => 0,
                'unverifiable_legacy'       => 0,
                'added'                     => ['count' => 0, 'net' => 0.0],
                'removed'                   => ['count' => 0, 'loc_net' => 0.0],
                'adjusted'                  => ['count' => 0, 'delta_net' => 0.0],
                'missing_locally'           => ['total' => 0, 'valid' => 0, 'valid_net' => 0.0, 'skipped_before_window' => 0],
                'pii_identities_corrected'  => 0,
            ];

            $orderUpserts   = [];
            $mappingUpserts = [];
            $affectedDates  = [];
            $seenEids       = [];

            $processOrder = function (string $eid, ?object $lo, ?array $lv) use (
                $cid, $integration, $evalIntegration, $policy, $hasher, $hmacKey, $nowStr,
                $hasExtendedCols, $hasPhaseECols,
                &$mapByCid, &$mapByEmail, &$mapByHash, &$orderUpserts, &$mappingUpserts,
                &$affectedDates, &$afterStats, &$bridge
            ) {
                if ($lv) {
                    $qual = $policy->evaluate($lv, $evalIntegration);
                    $custId = (isset($lv['customer_id']) && $lv['customer_id'] !== '' && $lv['customer_id'] !== null)
                        ? trim((string) $lv['customer_id'])
                        : null;
                    $custEmail = ! empty($lv['customer_email']) ? trim((string) $lv['customer_email']) : null;
                    $person    = trim(($lv['customer_firstname'] ?? '') . ' ' . ($lv['customer_lastname'] ?? ''));
                    $custName  = $person !== '' ? $person : null;
                    $compRaw   = $lv['billing_address']['company'] ?? $lv['shipping_address']['company'] ?? null;
                    $custComp  = ! empty($compRaw) ? trim((string) $compRaw) : null;

                    if ($custId !== null) {
                        $identityHash = hash_hmac('sha256', 'cid:' . $custId, $hmacKey);
                    } elseif ($custEmail !== null) {
                        $identityHash = hash_hmac('sha256', 'email:' . $hasher->normalizeEmail($custEmail), $hmacKey);
                    } else {
                        $identityHash = $lo?->customer_identity_hash;
                    }

                    if ($lo && $custEmail && $lo->customer_email && strcasecmp(trim($custEmail), trim($lo->customer_email)) !== 0) {
                        $bridge['pii_identities_corrected']++;
                    }
                } else {
                    $qual = $policy->evaluate([
                        'status'          => $lo->order_status,
                        'grand_total'     => (float) $lo->gross_revenue,
                        'total_refunded'  => (float) $lo->refunded_revenue,
                        'created_at'      => (string) $lo->order_date,
                        'registered_customer_id' => $lo->registered_customer_id,
                    ], $evalIntegration);

                    $custId       = $lo->registered_customer_id;
                    $custEmail    = $lo->customer_email;
                    $custName     = $lo->customer_name;
                    $custComp     = $lo->company_name;
                    $identityHash = $lo->customer_identity_hash;
                }

                // Resolve canonical account ID strictly within client scope
                $canonicalId = null;
                if (! empty($custId)) {
                    $canonicalId = $mapByCid[$custId] ?? ("client_{$cid}:cid_{$custId}");
                } elseif (! empty($custEmail)) {
                    $norm = $hasher->normalizeEmail($custEmail);
                    $canonicalId = $mapByEmail[$norm] ?? ("client_{$cid}:guest_" . hash('sha256', $norm));
                } elseif (! empty($identityHash)) {
                    $canonicalId = $mapByHash[$identityHash] ?? ("client_{$cid}:hash_{$identityHash}");
                } else {
                    $canonicalId = "client_{$cid}:unknown";
                }

                if (! empty($custId)) {
                    $mapByCid[$custId] = $canonicalId;
                    $mappingUpserts["{$cid}|registered_customer_id|{$custId}"] = [
                        'client_id'            => $cid,
                        'canonical_account_id' => $canonicalId,
                        'identity_type'        => 'registered_customer_id',
                        'identity_value'       => $custId,
                        'source_system'        => 'adobe_commerce',
                        'confidence_score'     => 1.0,
                        'provenance_metadata'  => json_encode(['type' => 'registered_customer_id']),
                        'is_active'            => 1,
                        'created_at'           => $nowStr,
                        'updated_at'           => $nowStr,
                    ];
                }
                if (! empty($custEmail)) {
                    $norm = $hasher->normalizeEmail($custEmail);
                    $mapByEmail[$norm] = $canonicalId;
                    $mappingUpserts["{$cid}|normalized_email|{$norm}"] = [
                        'client_id'            => $cid,
                        'canonical_account_id' => $canonicalId,
                        'identity_type'        => 'normalized_email',
                        'identity_value'       => $norm,
                        'source_system'        => 'adobe_commerce',
                        'confidence_score'     => 1.0,
                        'provenance_metadata'  => json_encode(['type' => 'normalized_email']),
                        'is_active'            => 1,
                        'created_at'           => $nowStr,
                        'updated_at'           => $nowStr,
                    ];
                }
                if (! empty($identityHash)) {
                    $mapByHash[$identityHash] = $canonicalId;
                    $mappingUpserts["{$cid}|source_identity_hash|{$identityHash}"] = [
                        'client_id'            => $cid,
                        'canonical_account_id' => $canonicalId,
                        'identity_type'        => 'source_identity_hash',
                        'identity_value'       => $identityHash,
                        'source_system'        => 'adobe_commerce',
                        'confidence_score'     => 1.0,
                        'provenance_metadata'  => json_encode(['type' => 'source_identity_hash']),
                        'is_active'            => 1,
                        'created_at'           => $nowStr,
                        'updated_at'           => $nowStr,
                    ];
                }

                $newNet = $qual->isValid ? $qual->qualifyingNetRevenue : 0.0;
                $afterStats['total_orders']++;
                $afterStats['classifications'][$qual->classification] = ($afterStats['classifications'][$qual->classification] ?? 0) + 1;
                if (! $qual->isValid && $qual->exclusionReason) {
                    $afterStats['exclusion_reasons'][$qual->exclusionReason] = ($afterStats['exclusion_reasons'][$qual->exclusionReason] ?? 0) + 1;
                }

                $afterStats['refundable_core_deposit']  = round($afterStats['refundable_core_deposit'] + $qual->refundableCoreDeposit, 2);
                $afterStats['core_deposit_refunded']    = round($afterStats['core_deposit_refunded'] + $qual->coreDepositRefunded, 2);

                if ($qual->isValid) {
                    $afterStats['valid_orders']++;
                    $afterStats['qualifying_order_value']        = round($afterStats['qualifying_order_value'] + $qual->qualifyingOrderValue, 2);
                    $afterStats['net_merchandise_revenue']       = round($afterStats['net_merchandise_revenue'] + $newNet, 2);
                    $afterStats['paid_revenue']                  = round($afterStats['paid_revenue'] + $qual->paidRevenue, 2);
                    $afterStats['invoiced_revenue']              = round($afterStats['invoiced_revenue'] + $qual->invoicedRevenue, 2);
                    $afterStats['outstanding_receivable']        = round($afterStats['outstanding_receivable'] + $qual->outstandingReceivable, 2);
                    $afterStats['settlement_unverified_revenue'] = round($afterStats['settlement_unverified_revenue'] + $qual->settlementUnverifiedRevenue, 2);
                    $afterStats['outstanding_core_deposit']      = round($afterStats['outstanding_core_deposit'] + $qual->outstandingCoreDeposit, 2);
                }

                $orderDateStr = $lo
                    ? (string) $lo->order_date
                    : Carbon::parse($lv['created_at'] ?? $nowStr)->toDateTimeString();
                $orderDay = substr($orderDateStr, 0, 10);

                $financialChanged = ! $lo
                    || ((int) $lo->is_valid !== ($qual->isValid ? 1 : 0))
                    || abs((float) $lo->net_revenue - $newNet) > 0.01
                    || abs((float) $lo->refunded_revenue - $qual->refundedRevenue) > 0.01
                    || ($lo->order_status !== $qual->orderStatus)
                    || ($lo->customer_email !== $custEmail);

                if ($financialChanged) {
                    $affectedDates[$orderDay] = true;
                }

                if ($lo) {
                    $wasValid = (int) $lo->is_valid === 1;
                    if (! $wasValid && $qual->isValid) {
                        $bridge['added']['count']++;
                        $bridge['added']['net'] = round($bridge['added']['net'] + $newNet, 2);
                    } elseif ($wasValid && ! $qual->isValid) {
                        $bridge['removed']['count']++;
                        $bridge['removed']['loc_net'] = round($bridge['removed']['loc_net'] + (float) $lo->net_revenue, 2);
                    } elseif ($wasValid && $qual->isValid && abs((float) $lo->net_revenue - $newNet) > 0.01) {
                        $bridge['adjusted']['count']++;
                        $bridge['adjusted']['delta_net'] = round($bridge['adjusted']['delta_net'] + ($newNet - (float) $lo->net_revenue), 2);
                    }
                } else {
                    $bridge['missing_locally']['total']++;
                    if ($qual->isValid) {
                        $bridge['missing_locally']['valid']++;
                        $bridge['missing_locally']['valid_net'] = round($bridge['missing_locally']['valid_net'] + $newNet, 2);
                    }
                }

                $existingMeta = $lo && ! empty($lo->metadata_json)
                    ? (json_decode((string) $lo->metadata_json, true) ?: [])
                    : [];

                $mergedMeta = array_merge($existingMeta, [
                    'customer_group_id'             => $lv['customer_group_id'] ?? ($existingMeta['customer_group_id'] ?? null),
                    'total_qty_ordered'             => (int) ($lv['total_qty_ordered'] ?? ($existingMeta['total_qty_ordered'] ?? 0)),
                    'customer_is_guest'             => (bool) ($lv['customer_is_guest'] ?? ($custId === null)),
                    'order_state'                   => $qual->orderState,
                    'payment_method'                => $qual->paymentMethod,
                    'qualification_classification'  => $qual->classification,
                    'qualification_reason'          => $qual->qualificationReason,
                    'invoiced_revenue'              => $qual->invoicedRevenue,
                    'paid_revenue'                  => $qual->paidRevenue,
                    'canceled_revenue'              => $qual->canceledRevenue,
                    'qualifying_order_value'        => $qual->qualifyingOrderValue,
                    'outstanding_receivable'        => $qual->outstandingReceivable,
                    'settlement_unverified_revenue' => $qual->settlementUnverifiedRevenue,
                    'refundable_core_deposit'       => $qual->refundableCoreDeposit,
                    'core_deposit_refunded'         => $qual->coreDepositRefunded,
                    'outstanding_core_deposit'      => $qual->outstandingCoreDeposit,
                    'qualifying_gross_revenue'      => $qual->qualifyingGrossRevenue,
                    'unsettled_balance'             => $qual->unsettledBalance,
                    'qualification_provenance'      => $qual->provenance,
                    'base_amount_authorized'        => $lv['payment']['base_amount_authorized'] ?? ($existingMeta['base_amount_authorized'] ?? null),
                ]);

                $row = [
                    'client_id'                 => $cid,
                    'integration_id'            => $integration->id,
                    'source'                    => 'adobe_commerce',
                    'source_order_id'           => $eid,
                    'source_increment_id'       => $lv['increment_id'] ?? $lo?->source_increment_id,
                    'order_status'              => $qual->orderStatus,
                    'customer_identity_hash'    => $identityHash,
                    'canonical_account_id'      => $canonicalId,
                    'registered_customer_id'    => $custId,
                    'customer_email'            => $custEmail,
                    'customer_name'             => $custName,
                    'company_name'              => $custComp,
                    'order_date'                => $orderDateStr,
                    'refund_date'               => $qual->refundedRevenue > 0 ? ($lo?->refund_date ?? $nowStr) : null,
                    'gross_revenue'             => $qual->grossRevenue,
                    'refunded_revenue'          => $qual->refundedRevenue,
                    'net_revenue'               => $newNet,
                    'tax_amount'                => (float) ($lv['tax_amount'] ?? ($lo?->tax_amount ?? 0)),
                    'shipping_amount'           => (float) ($lv['shipping_amount'] ?? ($lo?->shipping_amount ?? 0)),
                    'discount_amount'           => abs((float) ($lv['discount_amount'] ?? ($lo?->discount_amount ?? 0))),
                    'currency'                  => $lv['order_currency_code'] ?? ($lo?->currency ?? 'USD'),
                    'base_currency'             => $lv['base_currency_code'] ?? ($lo?->base_currency ?? 'USD'),
                    'reporting_currency'        => $lo?->reporting_currency ?? ($evalClient->currency ?? 'USD'),
                    'exchange_rate'             => (float) ($lv['store_to_base_rate'] ?? ($lo?->exchange_rate ?? 1.0)),
                    'is_valid'                  => $qual->isValid ? 1 : 0,
                    'exclusion_reason'          => $qual->exclusionReason,
                    'source_updated_at'         => isset($lv['updated_at']) ? Carbon::parse($lv['updated_at'])->toDateTimeString() : ($lo?->source_updated_at ?? null),
                    'financial_last_changed_at' => $financialChanged ? $nowStr : ($lo?->financial_last_changed_at ?? $nowStr),
                    'collected_at'              => $lo?->collected_at ?? $nowStr,
                    'metadata_json'             => json_encode($mergedMeta),
                    'created_at'                => $lo?->created_at ?? $nowStr,
                    'updated_at'                => $nowStr,
                ];

                if ($hasExtendedCols) {
                    $row['order_state']                  = $qual->orderState;
                    $row['payment_method']               = $qual->paymentMethod;
                    $row['qualification_classification'] = $qual->classification;
                    $row['invoiced_revenue']             = $qual->invoicedRevenue;
                    $row['paid_revenue']                 = $qual->paidRevenue;
                    $row['canceled_revenue']             = $qual->canceledRevenue;
                    $row['outstanding_receivable']       = $qual->outstandingReceivable;
                }
                if ($hasPhaseECols) {
                    $row['qualification_reason']          = $qual->qualificationReason;
                    $row['qualifying_order_value']        = $qual->qualifyingOrderValue;
                    $row['settlement_unverified_revenue'] = $qual->settlementUnverifiedRevenue;
                    $row['refundable_core_deposit']       = $qual->refundableCoreDeposit;
                    $row['core_deposit_refunded']         = $qual->coreDepositRefunded;
                    $row['outstanding_core_deposit']      = $qual->outstandingCoreDeposit;
                }

                $orderUpserts[] = $row;
            };

            foreach ($localRows as $eid => $lo) {
                $eidStr = (string) $eid;
                $seenEids[$eidStr] = true;
                $lv = $liveMap[$eidStr] ?? null;
                if ($lv) {
                    $bridge['matched_live']++;
                } else {
                    $bridge['unverifiable_legacy']++;
                }
                $processOrder($eidStr, $lo, $lv);
            }

            foreach ($liveMap as $eid => $lv) {
                $eidStr = (string) $eid;
                if (isset($seenEids[$eidStr])) {
                    continue;
                }
                $dt = substr((string) ($lv['created_at'] ?? ''), 0, 10);
                if ($minLocalDay !== null && $dt !== '' && $dt < $minLocalDay) {
                    $bridge['missing_locally']['skipped_before_window']++;
                    continue;
                }
                $processOrder($eidStr, null, $lv);
            }

            $factResult = null;
            $metricsUpdated = 0;

            if (! $dryRun) {
                // 1. Persist audited policy configurations
                $configManager->updateClientMonitoringConfig($client, $policyConfig, 'phase_e_remediation');
                $configManager->updateIntegrationMonitoringConfig($integration, $policyConfig, 'phase_e_remediation', recordAudit: true);

                // 2. If Client #2 (Transpart), purge contaminated derived facts & mappings before restoring clean records
                if ($cid === 2) {
                    DB::transaction(function () use ($cid) {
                        DB::table('commerce_customer_purchase_facts_archive')->where('client_id', $cid)->delete();
                        DB::table('commerce_customer_purchase_facts')->where('client_id', $cid)->delete();
                        DB::table('commerce_customer_identity_mappings')->where('client_id', $cid)->delete();
                    });
                }

                // 3. Upsert identity mappings in chunks
                foreach (array_chunk(array_values($mappingUpserts), $batchSize) as $mapChunk) {
                    DB::transaction(fn () => DB::table('commerce_customer_identity_mappings')->upsert(
                        $mapChunk,
                        ['client_id', 'identity_type', 'identity_value'],
                        ['canonical_account_id', 'source_system', 'confidence_score', 'provenance_metadata', 'is_active', 'updated_at']
                    ));
                }

                // 4. Upsert orders in idempotent 500-order transactional batches
                $orderUpdateCols = [
                    'source_increment_id', 'order_status', 'customer_identity_hash', 'canonical_account_id',
                    'registered_customer_id', 'customer_email', 'customer_name', 'company_name',
                    'order_date', 'refund_date', 'gross_revenue', 'refunded_revenue', 'net_revenue',
                    'tax_amount', 'shipping_amount', 'discount_amount', 'currency', 'base_currency',
                    'reporting_currency', 'exchange_rate', 'is_valid', 'exclusion_reason',
                    'source_updated_at', 'financial_last_changed_at', 'collected_at', 'metadata_json', 'updated_at',
                ];
                if ($hasExtendedCols) {
                    $orderUpdateCols = array_merge($orderUpdateCols, [
                        'order_state', 'payment_method', 'qualification_classification',
                        'invoiced_revenue', 'paid_revenue', 'canceled_revenue', 'outstanding_receivable',
                    ]);
                }
                if ($hasPhaseECols) {
                    $orderUpdateCols = array_merge($orderUpdateCols, [
                        'qualification_reason', 'qualifying_order_value', 'settlement_unverified_revenue',
                        'refundable_core_deposit', 'core_deposit_refunded', 'outstanding_core_deposit',
                    ]);
                }

                foreach (array_chunk($orderUpserts, $batchSize) as $ordChunk) {
                    DB::transaction(fn () => DB::table('commerce_orders')->upsert(
                        $ordChunk,
                        ['client_id', 'integration_id', 'source', 'source_order_id'],
                        $orderUpdateCols
                    ));
                }

                // 5. Recalculate affected daily commerce_metrics
                if (! empty($affectedDates)) {
                    $connector = app(AdobeCommerceConnector::class, ['integration' => $integration]);
                    $metricsUpdated = $connector->recalculateDailyMetricsForDates(array_keys($affectedDates));
                }

                // 6. Rebuild authoritative lifetime customer purchase facts
                $factResult = $reconciler->reconcileClient($client->fresh(), false);
                if (empty($factResult['is_balanced'])) {
                    $this->error("Fail-closed: Client #{$cid} fact reconciliation did not balance!");
                    return self::FAILURE;
                }

                // 7. Update sync_state checkpoint
                $configManager->updateIntegrationMonitoringConfig($integration->fresh(), [
                    'sync_state' => [
                        'last_remediated_at'         => now()->toIso8601String(),
                        'last_successful_updated_at' => collect($liveMap)->max('updated_at'),
                        'last_successful_entity_id'  => collect($liveMap)->max('entity_id'),
                        'orders_remediated'          => count($orderUpserts),
                    ],
                ], 'phase_e_remediation', recordAudit: false);
            } else {
                $factResult = $reconciler->reconcileClient($client, true);
            }

            $summary[$cid] = [
                'client_id'       => $cid,
                'client_name'     => $client->name,
                'dry_run'         => $dryRun,
                'before'          => ['total_orders' => $localRows->count(), 'valid_orders' => $beforeValid, 'net_revenue' => $beforeNet],
                'bridge'          => $bridge,
                'after'           => $afterStats,
                'affected_dates'  => count($affectedDates),
                'metrics_updated' => $metricsUpdated,
                'facts'           => $factResult,
            ];
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Load live Magento REST order snapshots (overlaying full legacy/PayPal details for Goldkamp #5).
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadLiveOrders(int $clientId, string $liveDir): array
    {
        $path = match ($clientId) {
            1 => "{$liveDir}/cambro_all_live_orders.json",
            2 => "{$liveDir}/transpart_real_live_orders.json",
            5 => "{$liveDir}/goldkamp_full_live_orders.json",
            default => null,
        };

        if (! $path || ! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true) ?: [];

        if ($clientId === 5 && is_file("{$liveDir}/goldkamp_legacy_and_paypal_full.json")) {
            $overlay = json_decode((string) file_get_contents("{$liveDir}/goldkamp_legacy_and_paypal_full.json"), true) ?: [];
            foreach ($overlay as $eid => $fullOrder) {
                $data[(string) $eid] = $fullOrder;
            }
        }

        return $data;
    }

    /**
     * Client-scoped Phase E policies approved by the business owner.
     *
     * @return array<int, array<string, mixed>>
     */
    private function approvedPolicies(): array
    {
        return [
            1 => [
                'order_settings' => [
                    'eligible_statuses'              => ['complete', 'processing'],
                    'excluded_statuses'              => OrderQualificationPolicy::DEFAULT_EXCLUDED_STATUSES,
                    'b2b_credit_term_methods'        => OrderQualificationPolicy::DEFAULT_B2B_CREDIT_TERM_METHODS,
                    'allow_authorized_b2b_confirmed' => false,
                    'allow_invoiced_pending_payment' => true,
                ],
            ],
            2 => [
                'order_settings' => [
                    'eligible_statuses'              => ['complete', 'processing', 'processing_core_charged', 'awaiting_core_return'],
                    'excluded_statuses'              => OrderQualificationPolicy::DEFAULT_EXCLUDED_STATUSES,
                    'b2b_credit_term_methods'        => OrderQualificationPolicy::DEFAULT_B2B_CREDIT_TERM_METHODS,
                    'allow_authorized_b2b_confirmed' => false,
                    'allow_invoiced_pending_payment' => true,
                    'core_deposit_settings'          => [
                        'enabled'                                    => true,
                        'deduct_outstanding_from_merchandise_revenue' => true,
                        'surcharge_codes'                            => ['fooman_surcharge'],
                        'surcharge_labels'                           => ['refundable deposit'],
                    ],
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
                    'legacy_completed_workflows'     => [
                        'enabled'                 => true,
                        'cutoff_before'           => '2023-01-01',
                        'required_status'         => 'complete',
                        'allowed_states'          => ['processing', 'complete'],
                        'allowed_payment_methods' => ['authorizenet', 'authorizenet_directpost', 'md_authorizecim'],
                    ],
                    'settlement_unverified_workflows' => [
                        'enabled'          => true,
                        'payment_methods'  => ['paypal_express'],
                        'allowed_statuses' => ['complete', 'processing', 'confirmed', 'paid_in_full'],
                        'allowed_states'   => ['processing', 'complete'],
                    ],
                ],
            ],
        ];
    }
}
