<?php

namespace App\Services\Connectors;

use App\Enums\SyncStatus;
use App\Models\CommerceMetric;
use App\Models\CommerceOrder;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\Configuration\ClientConfigurationManager;
use App\Services\Intelligence\CustomerIdentityResolverService;
use App\Services\Intelligence\CustomerPurchaseFactsReconciler;
use App\Services\Metrics\OrderQualificationPolicy;
use App\Services\Metrics\ValidOrderFilter;
use App\Services\Security\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AdobeCommerceConnector
 *
 * Authenticates via the Magento 2 Admin REST API token endpoint:
 *   POST /rest/V1/integration/admin/token
 *
 * Then pulls newly created and recently updated orders from:
 *   GET /rest/V1/orders (or sales_order DB)
 * using checkpointed dual-timestamp sync, client-scoped OrderQualificationPolicy,
 * and atomic canonical customer fact reconciliation.
 */
class AdobeCommerceConnector
{
    private ValidOrderFilter $orderFilter;
    private CustomerIdentityHasher $identityHasher;
    private OrderQualificationPolicy $qualificationPolicy;
    private CustomerIdentityResolverService $identityResolver;
    private CustomerPurchaseFactsReconciler $factsReconciler;
    private ClientConfigurationManager $configManager;

    public function __construct(
        private readonly Integration $integration,
        ?OrderQualificationPolicy $qualificationPolicy = null,
        ?CustomerIdentityResolverService $identityResolver = null,
        ?CustomerPurchaseFactsReconciler $factsReconciler = null,
        ?ClientConfigurationManager $configManager = null
    ) {
        $this->orderFilter         = new ValidOrderFilter();
        $this->identityHasher      = new CustomerIdentityHasher();
        $this->qualificationPolicy = $qualificationPolicy ?? app(OrderQualificationPolicy::class);
        $this->identityResolver    = $identityResolver ?? app(CustomerIdentityResolverService::class);
        $this->factsReconciler     = $factsReconciler ?? app(CustomerPurchaseFactsReconciler::class);
        $this->configManager       = $configManager ?? app(ClientConfigurationManager::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    public function sync(SyncLog $syncLog, int $numOfDays = 1): void
    {
        $creds = $this->integration->credentials_json ?? [];

        if (! $this->hasRequiredCredentials($creds)) {
            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => 'Missing Adobe Commerce base_url, admin_username, or admin_password.',
                'completed_at'  => now(),
            ]);
            return;
        }

        try {
            // Safe token-refresh / connectivity probe without mutating orders or checkpoints
            if ($numOfDays < 0) {
                if (! empty($creds['base_url']) && (empty($creds['db_host']) || ! empty($creds['admin_username']) || ! empty($creds['access_token']) || ! empty($creds['bearer_token']))) {
                    $this->getAdminToken($creds);
                }

                $syncLog->update([
                    'status'            => SyncStatus::Success,
                    'records_processed' => 0,
                    'completed_at'      => now(),
                    'metadata_json'     => [
                        'orders_fetched' => 0,
                        'days_processed' => 0,
                        'mode'           => 'token_refresh_noop',
                    ],
                ]);

                return;
            }

            $syncState          = $this->integration->monitoring_config['sync_state'] ?? [];
            $overlapMinutes     = max(0, (int) config('intelligence.commerce_sync.checkpoint_overlap_minutes', 120));
            $initialUpdatedDays = max(1, (int) config('intelligence.commerce_sync.initial_updated_lookback_days', 30));

            $windowEnd          = now();
            $defaultCreatedFrom = now()->subDays(max(0, $numOfDays))->startOfDay();
            $createdFromCarbon  = $defaultCreatedFrom;

            if (! empty($syncState['last_successful_window_end']) && $numOfDays <= 2) {
                $checkpointStart   = Carbon::parse($syncState['last_successful_window_end'])->subMinutes($overlapMinutes);
                $createdFromCarbon = $checkpointStart->lt($defaultCreatedFrom) ? $checkpointStart : $defaultCreatedFrom;
                $updatedFromCarbon = $checkpointStart->copy();
            } else {
                $updatedFromCarbon = now()->subDays(max($numOfDays, $initialUpdatedDays))->startOfDay();
            }

            $createdFrom = $createdFromCarbon->toIso8601String();
            $updatedFrom = $updatedFromCarbon->toIso8601String();
            $to          = $windowEnd->copy()->endOfDay()->toIso8601String();

            if (! empty($creds['db_host']) && ! empty($creds['db_name'])) {
                try {
                    $orders = $this->fetchOrdersFromDb($creds, $createdFrom, $updatedFrom, $to);
                } catch (\Throwable $e) {
                    Log::warning('AdobeCommerceConnector: DB sync connection failed or timed out, falling back to REST API', [
                        'integration_id' => $this->integration->id,
                        'error'          => $e->getMessage(),
                    ]);
                    $token   = $this->getAdminToken($creds);
                    $baseUrl = rtrim($creds['base_url'], '/');
                    $orders  = $this->fetchOrders($baseUrl, $token, $createdFrom, $updatedFrom, $to);
                }
            } else {
                $token   = $this->getAdminToken($creds);
                $baseUrl = rtrim($creds['base_url'], '/');
                $orders  = $this->fetchOrders($baseUrl, $token, $createdFrom, $updatedFrom, $to);
            }

            // Persist qualified order records & reconcile affected canonical customer purchase facts
            $persistSummary = $this->storeOrdersAndFacts($orders);

            // Recalculate full-day commerce_metrics for every affected order_date
            $totalRecords = $this->recalculateDailyMetricsForDates($persistSummary['affected_dates']);

            // Advance sync checkpoint ONLY after persistence and recalculation succeed
            $nextSyncState = [
                'last_successful_sync_at'    => now()->toIso8601String(),
                'last_successful_window_end' => $windowEnd->toIso8601String(),
                'last_created_from'          => $createdFrom,
                'last_updated_from'          => $updatedFrom,
                'last_orders_fetched'        => count($orders),
                'last_dates_recalculated'    => $totalRecords,
            ];

            $this->configManager->updateIntegrationMonitoringConfig(
                $this->integration,
                ['sync_state' => $nextSyncState],
                source: 'adobe_commerce_sync',
                actorUserId: null,
                ipAddress: null,
                recordAudit: false
            );
            $this->integration->refresh();

            $syncLog->update([
                'status'            => SyncStatus::Success,
                'records_processed' => $totalRecords,
                'completed_at'      => now(),
                'metadata_json'     => [
                    'orders_fetched'      => count($orders),
                    'days_processed'      => $totalRecords,
                    'canonical_accounts'  => count($persistSummary['canonical_accounts']),
                    'date_range'          => [
                        'created_from' => $createdFrom,
                        'updated_from' => $updatedFrom,
                        'to'           => $to,
                    ],
                ],
            ]);

            Log::info('AdobeCommerceConnector: sync complete', [
                'integration_id' => $this->integration->id,
                'orders'         => count($orders),
                'days'           => $totalRecords,
            ]);

        } catch (\Throwable $e) {
            Cache::forget('adobe_token:' . $this->integration->id);

            $safe = $this->sanitiseError($e->getMessage(), $creds);

            Log::error('AdobeCommerceConnector: sync error', [
                'integration_id' => $this->integration->id,
                'message'        => $safe,
            ]);

            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => $safe,
                'completed_at'  => now(),
            ]);
        }
    }

    /**
     * Persist qualified order records into commerce_orders and reconcile canonical purchase facts.
     *
     * @return array{affected_dates: array<int, string>, canonical_accounts: array<int, string>}
     */
    private function storeOrdersAndFacts(array $orders): array
    {
        $client = $this->integration->client;
        $orderRecords = [];
        $affectedDates = [];
        $affectedCanonicalIds = [];
        $affectedHashes = [];
        $identityMemo = [];

        $nowStr = now()->toDateTimeString();
        $hasExtendedCols = Schema::hasColumn('commerce_orders', 'order_state');

        $incomingIds = [];
        foreach ($orders as $order) {
            $eid = (string) ($order['entity_id'] ?? $order['increment_id'] ?? '');
            if ($eid !== '') {
                $incomingIds[] = $eid;
            }
        }

        if (! empty($incomingIds)) {
            foreach (array_chunk(array_unique($incomingIds), 500) as $idChunk) {
                $existingRows = CommerceOrder::where('client_id', $this->integration->client_id)
                    ->where('integration_id', $this->integration->id)
                    ->where('source', 'adobe_commerce')
                    ->whereIn('source_order_id', $idChunk)
                    ->get(['order_date', 'customer_identity_hash', 'canonical_account_id']);

                foreach ($existingRows as $ex) {
                    if ($ex->order_date) {
                        $affectedDates[$ex->order_date->toDateString()] = true;
                    }
                    if (! empty($ex->customer_identity_hash)) {
                        $affectedHashes[] = $ex->customer_identity_hash;
                    }
                    if (! empty($ex->canonical_account_id)) {
                        $affectedCanonicalIds[] = $ex->canonical_account_id;
                    }
                }
            }
        }

        foreach ($orders as $order) {
            $entityId = (string) ($order['entity_id'] ?? $order['increment_id'] ?? '');
            if ($entityId === '') {
                continue;
            }

            $createdAt  = Carbon::parse($order['created_at'] ?? now());
            $affectedDates[$createdAt->toDateString()] = true;

            $tax        = (float) ($order['tax_amount'] ?? $order['base_tax_amount'] ?? 0);
            $shipping   = (float) ($order['shipping_amount'] ?? $order['base_shipping_amount'] ?? 0);
            $discount   = (float) abs($order['discount_amount'] ?? $order['base_discount_amount'] ?? 0);
            $custEmail  = $order['customer_email'] ?? null;
            $custId     = (isset($order['customer_id']) && $order['customer_id'] !== '' && $order['customer_id'] !== null)
                ? (string) $order['customer_id']
                : null;
            $company    = $order['billing_address']['company'] ?? $order['shipping_address']['company'] ?? ($order['company_name'] ?? null);
            $personName = trim(($order['customer_firstname'] ?? '') . ' ' . ($order['customer_lastname'] ?? ''));
            $custName   = $personName !== '' ? $personName : null;
            $companyName = ! empty($company) ? trim((string) $company) : null;

            $identityHash = $this->identityHasher->hashCustomerIdentity($client, $custId, $custEmail);
            $identityMemoKey = ($custId ?? '') . '|' . strtolower(trim((string) ($custEmail ?? ''))) . '|' . ($identityHash ?? '');
            if (isset($identityMemo[$identityMemoKey])) {
                $canonicalAccountId = $identityMemo[$identityMemoKey];
            } else {
                $canonicalAccountId = $this->identityResolver->resolveCanonicalAccountId($client, $custId, $custEmail, $identityHash);
                $this->identityResolver->registerAccountIdentities(
                    $client,
                    $canonicalAccountId,
                    $custId,
                    $custEmail,
                    array_filter([$identityHash])
                );
                $identityMemo[$identityMemoKey] = $canonicalAccountId;
            }

            $affectedCanonicalIds[] = $canonicalAccountId;
            if ($identityHash) {
                $affectedHashes[] = $identityHash;
            }

            $qual = $this->qualificationPolicy->evaluate($order, $this->integration);
            $isValid = $qual->isValid;
            $exclusionReason = $qual->exclusionReason;
            $netRevenue = $isValid ? $qual->qualifyingNetRevenue : 0.0;

            $metadata = [
                'customer_group_id'            => $order['customer_group_id'] ?? null,
                'total_qty_ordered'            => (int) ($order['total_qty_ordered'] ?? 0),
                'customer_is_guest'            => (bool) ($order['customer_is_guest'] ?? ($custId === null)),
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
                'base_amount_authorized'        => $order['payment']['base_amount_authorized'] ?? ($order['base_amount_authorized'] ?? null),
            ];

            $record = [
                'client_id'                 => $this->integration->client_id,
                'integration_id'            => $this->integration->id,
                'source'                    => 'adobe_commerce',
                'source_order_id'           => $entityId,
                'source_increment_id'       => $order['increment_id'] ?? null,
                'order_status'              => $qual->orderStatus,
                'customer_identity_hash'    => $identityHash,
                'canonical_account_id'      => $canonicalAccountId,
                'registered_customer_id'    => $custId,
                'customer_email'            => $custEmail,
                'customer_name'             => $custName,
                'company_name'              => $companyName,
                'order_date'                => $createdAt->toDateTimeString(),
                'refund_date'               => $qual->refundedRevenue > 0 ? $nowStr : null,
                'gross_revenue'             => $qual->grossRevenue,
                'refunded_revenue'          => $qual->refundedRevenue,
                'net_revenue'               => $netRevenue,
                'tax_amount'                => $tax,
                'shipping_amount'           => $shipping,
                'discount_amount'           => $discount,
                'currency'                  => $order['order_currency_code'] ?? 'USD',
                'base_currency'             => $order['base_currency_code'] ?? 'USD',
                'reporting_currency'        => $client->currency ?? 'USD',
                'exchange_rate'             => (float) ($order['store_to_base_rate'] ?? 1.0),
                'is_valid'                  => $isValid ? 1 : 0,
                'exclusion_reason'          => $exclusionReason,
                'source_updated_at'         => isset($order['updated_at']) ? Carbon::parse($order['updated_at'])->toDateTimeString() : null,
                'financial_last_changed_at' => $nowStr,
                'collected_at'              => $nowStr,
                'metadata_json'             => json_encode($metadata),
                'created_at'                => $nowStr,
                'updated_at'                => $nowStr,
            ];

            if ($hasExtendedCols) {
                $record['order_state']                  = $qual->orderState;
                $record['payment_method']               = $qual->paymentMethod;
                $record['qualification_classification'] = $qual->classification;
                $record['invoiced_revenue']             = $qual->invoicedRevenue;
                $record['paid_revenue']                 = $qual->paidRevenue;
                $record['canceled_revenue']             = $qual->canceledRevenue;
                $record['outstanding_receivable']       = $qual->outstandingReceivable;
                if (Schema::hasColumn('commerce_orders', 'settlement_unverified_revenue')) {
                    $record['qualification_reason']          = $qual->qualificationReason;
                    $record['qualifying_order_value']        = $qual->qualifyingOrderValue;
                    $record['settlement_unverified_revenue'] = $qual->settlementUnverifiedRevenue;
                    $record['refundable_core_deposit']       = $qual->refundableCoreDeposit;
                    $record['core_deposit_refunded']         = $qual->coreDepositRefunded;
                    $record['outstanding_core_deposit']      = $qual->outstandingCoreDeposit;
                }
            }

            $orderRecords[] = $record;
        }

        $updateColumns = [
            'source_increment_id', 'order_status', 'customer_identity_hash', 'canonical_account_id',
            'registered_customer_id', 'customer_email', 'customer_name', 'company_name',
            'order_date', 'refund_date', 'gross_revenue', 'refunded_revenue', 'net_revenue',
            'tax_amount', 'shipping_amount', 'discount_amount', 'currency', 'base_currency',
            'reporting_currency', 'exchange_rate', 'is_valid', 'exclusion_reason',
            'source_updated_at', 'financial_last_changed_at', 'collected_at', 'metadata_json', 'updated_at',
        ];

        if ($hasExtendedCols) {
            $updateColumns = array_merge($updateColumns, [
                'order_state', 'payment_method', 'qualification_classification',
                'invoiced_revenue', 'paid_revenue', 'canceled_revenue', 'outstanding_receivable',
            ]);
            if (Schema::hasColumn('commerce_orders', 'settlement_unverified_revenue')) {
                $updateColumns = array_merge($updateColumns, [
                    'qualification_reason', 'qualifying_order_value', 'settlement_unverified_revenue',
                    'refundable_core_deposit', 'core_deposit_refunded', 'outstanding_core_deposit',
                ]);
            }
        }

        // Chunked bulk upsert into commerce_orders
        foreach (array_chunk($orderRecords, 500) as $chunk) {
            CommerceOrder::upsert(
                $chunk,
                ['client_id', 'integration_id', 'source', 'source_order_id'],
                $updateColumns
            );
        }

        // Reconcile affected customer purchase facts from full canonical account history
        $this->factsReconciler->reconcileForCanonicalAccounts(
            $client,
            array_values(array_unique(array_filter($affectedCanonicalIds))),
            array_values(array_unique(array_filter($affectedHashes)))
        );

        return [
            'affected_dates'     => array_keys($affectedDates),
            'canonical_accounts' => array_values(array_unique(array_filter($affectedCanonicalIds))),
        ];
    }

    /**
     * Recalculate full daily CommerceMetric rows from all persisted commerce_orders on the given dates.
     * Ensures updated historical orders revise their original order_date aggregate accurately.
     *
     * @param  array<int, string>  $dates
     */
    public function recalculateDailyMetricsForDates(array $dates): int
    {
        $cleanDates = array_values(array_unique(array_filter($dates)));
        $updated = 0;

        foreach ($cleanDates as $date) {
            $orders = CommerceOrder::where('client_id', $this->integration->client_id)
                ->where('source', 'adobe_commerce')
                ->whereBetween('order_date', ["{$date} 00:00:00", "{$date} 23:59:59"])
                ->get(['customer_email', 'registered_customer_id', 'order_status', 'net_revenue', 'is_valid', 'metadata_json']);

            $revenue = 0.0;
            $validOrders = 0;
            $itemsSold = 0;
            $newCustomers = 0;
            $returningCustomers = 0;
            $statuses = [];
            $seenEmails = [];

            foreach ($orders as $row) {
                $status = strtolower((string) ($row->order_status ?? 'unknown'));
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;

                $meta = is_array($row->metadata_json) ? $row->metadata_json : [];

                if ((bool) $row->is_valid) {
                    $revenue += (float) $row->net_revenue;
                    $validOrders++;
                    $itemsSold += (int) ($meta['total_qty_ordered'] ?? 0);
                }

                $email = strtolower(trim((string) ($row->customer_email ?? '')));
                if ($email !== '' && ! isset($seenEmails[$email])) {
                    $seenEmails[$email] = true;
                    $isGuest = $meta['customer_is_guest'] ?? ($row->registered_customer_id === null || $row->registered_customer_id === '');
                    if ($isGuest) {
                        $newCustomers++;
                    } else {
                        $returningCustomers++;
                    }
                }
            }

            $revenueRounded = round($revenue, 2);
            $aov = $validOrders > 0 ? round($revenueRounded / $validOrders, 2) : 0.0;

            $payload = [
                'revenue'             => $revenueRounded,
                'orders'              => $validOrders,
                'items_sold'          => $itemsSold,
                'new_customers'       => $newCustomers,
                'returning_customers' => $returningCustomers,
                'sessions'            => 0,
                'conversion_rate'     => 0.0,
                'aov'                 => $aov,
                'metadata_json'       => ['statuses' => $statuses],
            ];

            $existingMetric = CommerceMetric::where('client_id', $this->integration->client_id)
                ->where('source', 'adobe_commerce')
                ->whereDate('date', $date)
                ->first();

            if ($existingMetric) {
                $existingMetric->update($payload);
            } else {
                CommerceMetric::create(array_merge([
                    'client_id' => $this->integration->client_id,
                    'date'      => $date,
                    'source'    => 'adobe_commerce',
                ], $payload));
            }

            $updated++;
        }

        return $updated;
    }

    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        $creds = $this->integration->credentials_json ?? [];

        if (! $this->hasRequiredCredentials($creds)) {
            return 0;
        }

        try {
            $fromIso = $from->toIso8601String();
            $toIso   = $to->toIso8601String();

            if (! empty($creds['db_host']) && ! empty($creds['db_name'])) {
                try {
                    $orders = $this->fetchOrdersFromDb($creds, $fromIso, $fromIso, $toIso);
                } catch (\Throwable $e) {
                    Log::warning('AdobeCommerceConnector::fetchForDateRange — DB connection failed, falling back to REST API', [
                        'integration_id' => $this->integration->id,
                        'error'          => $e->getMessage(),
                    ]);
                    $token   = $this->getAdminToken($creds);
                    $baseUrl = rtrim($creds['base_url'], '/');
                    $orders  = $this->fetchOrders($baseUrl, $token, $fromIso, $fromIso, $toIso);
                }
            } else {
                $token   = $this->getAdminToken($creds);
                $baseUrl = rtrim($creds['base_url'], '/');
                $orders  = $this->fetchOrders($baseUrl, $token, $fromIso, $fromIso, $toIso);
            }

            $persistSummary = $this->storeOrdersAndFacts($orders);
            $this->recalculateDailyMetricsForDates($persistSummary['affected_dates']);

            return count($orders);

        } catch (\Throwable $e) {
            Log::error('AdobeCommerceConnector::fetchForDateRange error', [
                'integration_id' => $this->integration->id,
                'message'        => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function testConnection(): array
    {
        $creds = $this->integration->credentials_json ?? [];

        if (! $this->hasRequiredCredentials($creds)) {
            return ['success' => false, 'message' => 'Missing Store Base URL, or credentials (admin username/password or Integration Access Token).'];
        }

        try {
            $token   = $this->getAdminToken($creds, forceRefresh: true);
            $baseUrl = rtrim($creds['base_url'], '/');

            $from = now()->subDays(1)->startOfDay()->toIso8601String();
            $to   = now()->endOfDay()->toIso8601String();

            $orders = $this->fetchOrders($baseUrl, $token, $from, $from, $to, limit: 5);

            return [
                'success' => true,
                'message' => 'Connection successful. Recent orders test returned ' . count($orders) . ' records.',
            ];

        } catch (\Exception $e) {
            $safe = $this->sanitiseError($e->getMessage(), $creds);
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $safe,
            ];
        }
    }

    private function sendWithRetry(callable $requestFn, string $operation): Response
    {
        $maxAttempts = max(1, (int) config('intelligence.commerce_sync.retry_max_attempts', 3));
        $baseDelayMs = max(0, (int) config('intelligence.commerce_sync.retry_base_delay_ms', 1000));
        $retryableStatuses = [429, 500, 502, 503, 504];

        $lastException = null;
        $lastResponse = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                /** @var Response $response */
                $response = $requestFn();
                $lastResponse = $response;

                if (! in_array($response->status(), $retryableStatuses, true) || $attempt === $maxAttempts) {
                    return $response;
                }

                $delayMs = $baseDelayMs * (2 ** ($attempt - 1));
                Log::warning("AdobeCommerceConnector: transient HTTP {$response->status()} on {$operation}, retrying", [
                    'integration_id' => $this->integration->id,
                    'attempt'        => $attempt,
                    'max_attempts'   => $maxAttempts,
                    'delay_ms'       => $delayMs,
                ]);
            } catch (ConnectionException $e) {
                $lastException = $e;
                if ($attempt === $maxAttempts) {
                    throw $e;
                }

                $delayMs = $baseDelayMs * (2 ** ($attempt - 1));
                Log::warning("AdobeCommerceConnector: connection error on {$operation}, retrying", [
                    'integration_id' => $this->integration->id,
                    'attempt'        => $attempt,
                    'error'          => $e->getMessage(),
                ]);
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        if ($lastResponse instanceof Response) {
            return $lastResponse;
        }

        throw $lastException ?? new \RuntimeException("Adobe Commerce {$operation} failed after retries.");
    }

    private function getAdminToken(array $creds, bool $forceRefresh = false): string
    {
        // 1. If an explicit Integration Access Token / Bearer Token is provided, use it directly
        if (! empty($creds['access_token'])) {
            return trim($creds['access_token']);
        }
        if (! empty($creds['bearer_token'])) {
            return trim($creds['bearer_token']);
        }

        $cacheKey = 'adobe_token:' . $this->integration->id;

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function () use ($creds) {
            $baseUrl   = rtrim($creds['base_url'], '/');
            $endpoints = [
                $baseUrl . '/rest/V1/integration/admin/token',
                $baseUrl . '/rest/all/V1/integration/admin/token',
                $baseUrl . '/rest/default/V1/integration/admin/token',
            ];

            $lastError = null;

            foreach ($endpoints as $endpoint) {
                try {
                    $response = $this->sendWithRetry(fn () => Http::timeout(30)
                        ->withHeaders([
                            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                            'Content-Type'    => 'application/json',
                            'Accept'          => 'application/json, text/plain, */*',
                            'Accept-Language' => 'en-US,en;q=0.9',
                            'Cache-Control'   => 'no-cache',
                        ])
                        ->post($endpoint, [
                            'username' => $creds['admin_username'] ?? '',
                            'password' => $creds['admin_password'] ?? '',
                        ]), 'admin_token');

                    if ($response->successful()) {
                        $token = trim($response->body(), " \t\n\r\0\x0B\"");
                        if (! empty($token)) {
                            return $token;
                        }
                    }

                    $lastError = "Adobe Commerce auth failed (HTTP {$response->status()}): " . $this->parseResponseError($response);

                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                }
            }

            throw new \RuntimeException($lastError ?? 'Adobe Commerce authentication failed. Check credentials or Cloudflare settings.');
        });
    }

    private function fetchOrders(
        string $baseUrl,
        string $token,
        string $createdFrom,
        string $updatedFrom,
        string $to,
        int $limit = 100,
        bool $isRetry = false
    ): array {
        $ordersByEntity = [];
        $page           = 1;

        $endpoints = [
            $baseUrl . '/rest/V1/orders',
            $baseUrl . '/rest/all/V1/orders',
            $baseUrl . '/rest/default/V1/orders',
        ];

        do {
            // filter_groups[0] is OR: (created_at >= createdFrom OR updated_at >= updatedFrom)
            // filter_groups[1] is AND upper bound: updated_at <= to
            $queryParams = [
                'searchCriteria[filter_groups][0][filters][0][field]'          => 'created_at',
                'searchCriteria[filter_groups][0][filters][0][value]'          => $createdFrom,
                'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'gteq',
                'searchCriteria[filter_groups][0][filters][1][field]'          => 'updated_at',
                'searchCriteria[filter_groups][0][filters][1][value]'          => $updatedFrom,
                'searchCriteria[filter_groups][0][filters][1][condition_type]' => 'gteq',
                'searchCriteria[filter_groups][1][filters][0][field]'          => 'updated_at',
                'searchCriteria[filter_groups][1][filters][0][value]'          => $to,
                'searchCriteria[filter_groups][1][filters][0][condition_type]' => 'lteq',
                'searchCriteria[sortOrders][0][field]'                         => 'entity_id',
                'searchCriteria[sortOrders][0][direction]'                     => 'ASC',
                'searchCriteria[pageSize]'                                     => $limit,
                'searchCriteria[currentPage]'                                  => $page,
            ];

            $response = null;
            $lastException = null;

            foreach ($endpoints as $endpointUrl) {
                $url = $endpointUrl . '?' . http_build_query($queryParams);

                $resp = $this->sendWithRetry(fn () => Http::timeout(30)
                    ->withHeaders([
                        'Authorization'   => "Bearer {$token}",
                        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                        'Accept'          => 'application/json, text/plain, */*',
                        'Accept-Language' => 'en-US,en;q=0.9',
                        'Cache-Control'   => 'no-cache',
                    ])
                    ->get($url), 'fetch_orders');

                if ($resp->successful()) {
                    $response = $resp;
                    break;
                }

                $status = $resp->status();

                if ($status === 401 && ! $isRetry) {
                    Log::warning('AdobeCommerceConnector: 401 token expired, clearing token cache and retrying');
                    $creds    = $this->integration->credentials_json ?? [];
                    $newToken = $this->getAdminToken($creds, forceRefresh: true);
                    return $this->fetchOrders($baseUrl, $newToken, $createdFrom, $updatedFrom, $to, $limit, isRetry: true);
                }

                $errMsg  = $this->parseResponseError($resp);
                $lastException = new \RuntimeException("Adobe Commerce API error (HTTP {$status}): {$errMsg}");
            }

            if (! $response || ! $response->successful()) {
                throw $lastException ?? new \RuntimeException('Adobe Commerce API request failed.');
            }

            $data       = $response->json();
            $items      = $data['items'] ?? [];
            $totalCount = (int) ($data['total_count'] ?? 0);

            foreach ($items as $idx => $item) {
                $key = (string) ($item['entity_id'] ?? $item['increment_id'] ?? "p{$page}_{$idx}");
                $ordersByEntity[$key] = $item;
            }

            $page++;

        } while (($page - 1) * $limit < $totalCount && ! empty($items));

        return array_values($ordersByEntity);
    }

    private function fetchOrdersFromDb(array $creds, string $createdFrom, string $updatedFrom, string $to): array
    {
        $dbHost = $creds['db_host'] ?? '';
        $dbName = $creds['db_name'] ?? '';
        $dbUser = $creds['db_user'] ?? '';
        $dbPass = $creds['db_password'] ?? '';
        $dbPort = $creds['db_port'] ?? 3306;

        $origTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '3');

        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};port={$dbPort};charset=utf8mb4";
            $pdo = new \PDO($dsn, $dbUser, $dbPass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => 3,
            ]);

            $storeId = $creds['store_id'] ?? $creds['adobe_store_id'] ?? null;
            $params  = [
                ':created_from' => Carbon::parse($createdFrom)->toDateTimeString(),
                ':updated_from' => Carbon::parse($updatedFrom)->toDateTimeString(),
                ':to_date'      => Carbon::parse($to)->toDateTimeString(),
            ];

            $storeClause = '';
            if ($storeId !== null && $storeId !== '') {
                $storeClause = ' AND so.store_id = :store_id';
                $params[':store_id'] = (int) $storeId;
            }

            $sql = "
                SELECT
                    so.entity_id,
                    so.increment_id,
                    so.state,
                    so.status,
                    so.grand_total,
                    so.base_grand_total,
                    so.total_refunded,
                    so.base_total_refunded,
                    so.base_total_invoiced,
                    so.base_total_paid,
                    so.base_total_canceled,
                    so.tax_amount,
                    so.shipping_amount,
                    so.discount_amount,
                    so.created_at,
                    so.updated_at,
                    so.customer_email,
                    so.customer_id,
                    so.customer_firstname,
                    so.customer_lastname,
                    so.customer_is_guest,
                    so.customer_group_id,
                    so.order_currency_code,
                    so.base_currency_code,
                    so.store_to_base_rate,
                    so.total_qty_ordered,
                    sop.method AS payment_method,
                    sop.base_amount_authorized AS base_amount_authorized,
                    (
                        SELECT sa.company
                        FROM sales_order_address sa
                        WHERE sa.parent_id = so.entity_id AND sa.address_type = 'billing'
                        LIMIT 1
                    ) AS company_name
                FROM sales_order so
                LEFT JOIN sales_order_payment sop ON sop.parent_id = so.entity_id
                WHERE ((so.created_at >= :created_from AND so.created_at <= :to_date)
                    OR (so.updated_at >= :updated_from AND so.updated_at <= :to_date))
                    {$storeClause}
                ORDER BY so.entity_id ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();
        } finally {
            ini_set('default_socket_timeout', $origTimeout);
        }
    }

    private function hasRequiredCredentials(array $creds): bool
    {
        if (empty($creds['base_url']) && empty($creds['db_host'])) {
            return false;
        }

        $hasToken    = ! empty($creds['access_token']) || ! empty($creds['bearer_token']);
        $hasUserPass = ! empty($creds['admin_username']) && ! empty($creds['admin_password']);
        $hasDb       = ! empty($creds['db_host']) && ! empty($creds['db_name']);

        return $hasToken || $hasUserPass || $hasDb;
    }

    private function parseResponseError(\Illuminate\Http\Client\Response $response): string
    {
        $status = $response->status();
        $body   = $response->body();

        // Detect Cloudflare WAF Managed Challenge
        if (str_contains($body, 'Just a moment...') || str_contains($body, 'challenges.cloudflare.com') || str_contains($body, '_cf_chl_opt')) {
            $serverIp = $this->getServerPublicIp();
            $configuredUrl = (string) ($this->integration->credentials_json['base_url'] ?? '');
            $storeHost = $configuredUrl ? (parse_url($configuredUrl, PHP_URL_HOST) ?: $configuredUrl) : 'configured store';
            return "Cloudflare WAF Security Challenge (HTTP {$status}). Target store ({$storeHost}) Cloudflare firewall blocked the API request. Production server outbound IP: {$serverIp}. Ensure IP {$serverIp} is whitelisted in Cloudflare (Security > WAF > Tools > IP Access Rules) or set rule Action to 'Skip' / 'Bypass'.";
        }

        $jsonMsg = $response->json('message');
        if (is_string($jsonMsg) && ! empty($jsonMsg)) {
            return $jsonMsg;
        }

        // If body is HTML or contains markup, strip tags and summarize
        if (str_starts_with(trim($body), '<') || str_contains($body, '<html')) {
            $stripped = trim(strip_tags($body));
            $summary  = strlen($stripped) > 150 ? substr($stripped, 0, 150) . '...' : $stripped;
            return "HTTP {$status} HTML Response: " . ($summary ?: 'Access Denied');
        }

        return strlen($body) < 200 ? $body : "HTTP {$status}";
    }

    private function getServerPublicIp(): string
    {
        return Cache::remember('server_public_ip', 86400, function () {
            try {
                $resp = Http::timeout(3)->get('https://api.ipify.org');
                if ($resp->successful()) {
                    return trim($resp->body());
                }
            } catch (\Exception $e) {
                // Ignore
            }
            return request()->server('SERVER_ADDR') ?? 'unknown';
        });
    }

    private function sanitiseError(string $message, array $creds): string
    {
        foreach (['admin_password', 'admin_username'] as $key) {
            if (! empty($creds[$key]) && strlen($creds[$key]) > 2) {
                $message = str_replace($creds[$key], '••••••', $message);
            }
        }
        return $message;
    }
}
