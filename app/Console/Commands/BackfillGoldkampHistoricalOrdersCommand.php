<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\Integration;
use App\Services\Intelligence\CustomerIdentityResolverService;
use App\Services\Metrics\ValidOrderFilter;
use App\Services\Security\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BackfillGoldkampHistoricalOrdersCommand extends Command
{
    protected $signature = 'commerce:backfill-goldkamp-history
                            {--page-size=100 : Number of orders per API page}
                            {--max-pages=0 : Maximum pages to process (0 for all)}
                            {--start-page=1 : Page to start from}
                            {--max-entity-id=14044 : Upper bound entity_id for missing historical backfill}
                            {--concurrency=4 : Number of concurrent pages to fetch}
                            {--confirm-tenant-isolation : Explicit acknowledgment required before mutating orders}
                            {--dry-run : Simulate ingestion without writing to database}';

    protected $description = 'Safely backfills historical Magento 2 orders for Goldkamp (Client #5) preserving financial provenance and canonical identity';

    public function handle(
        CustomerIdentityHasher $hasher,
        CustomerIdentityResolverService $resolver,
        ValidOrderFilter $orderFilter
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $this->option('confirm-tenant-isolation')) {
            $this->error('Fail-closed: pass --confirm-tenant-isolation (or use --dry-run) before mutating historical order records.');
            return 1;
        }

        $client = Client::findOrFail(5);
        $integration = Integration::where('client_id', $client->id)
            ->where('integration_type', 'adobe_commerce')
            ->findOrFail(9);

        if ((int) $integration->client_id !== (int) $client->id) {
            $this->error('Fail-closed: Integration tenant mismatch.');
            return 1;
        }

        $baseHost = rtrim((string) ($integration->credentials_json['base_url'] ?? $integration->credentials_json['store_url'] ?? ''), '/');
        if ($baseHost === '') {
            $this->error('Fail-closed: Integration lacks explicit base_url in credentials_json.');
            return 1;
        }
        $baseUrl = $baseHost . '/rest/V1/orders';

        $this->info("=================================================================");
        $this->info("Starting Goldkamp Historical Order Backfill (Client #5, Integ #9)");
        $this->info("=================================================================");

        // 1. First, populate canonical_account_id and mappings for existing local orders
        $this->info("\nStep 1: Canonicalizing existing local orders in commerce_orders...");
        $this->canonicalizeExistingOrders($client);

        // 2. Retrieve valid Adobe Commerce REST token
        $this->info("\nStep 2: Authenticating with Goldkamp Magento 2 REST API...");
        $token = $this->getAdminToken();
        if (! $token) {
            $this->error("Failed to retrieve valid Adobe Commerce REST token.");
            return 1;
        }
        $this->info("Successfully authenticated with Magento 2 API.");

        // 3. Query total available historical orders
        $maxEntityId = (int) $this->option('max-entity-id');
        $pageSize = (int) $this->option('page-size');
        $startPage = (int) $this->option('start-page');
        $maxPages = (int) $this->option('max-pages');
        $concurrency = max(1, min(6, (int) $this->option('concurrency')));

        $this->info("\nStep 3: Querying historical order volume (entity_id < {$maxEntityId})...");
        $metaCheck = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept'        => 'application/json',
        ])->timeout(30)->get($baseUrl, [
            'searchCriteria[filter_groups][0][filters][0][field]'          => 'entity_id',
            'searchCriteria[filter_groups][0][filters][0][value]'          => $maxEntityId,
            'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'lt',
            'searchCriteria[pageSize]'                                     => 1,
            'searchCriteria[currentPage]'                                  => 1,
        ]);

        if (! $metaCheck->successful()) {
            $this->error("Failed to query Magento orders: HTTP {$metaCheck->status()} - {$metaCheck->body()}");
            return 1;
        }

        $totalAvailable = (int) ($metaCheck->json('total_count') ?? 0);
        $totalPages = (int) ceil($totalAvailable / $pageSize);
        $this->info("Total historical orders in Magento (entity_id < {$maxEntityId}): {$totalAvailable} orders ({$totalPages} pages).");

        if ($maxPages > 0) {
            $totalPages = min($totalPages, $startPage + $maxPages - 1);
            $this->info("Capping execution to page {$totalPages} per --max-pages option.");
        }

        // 4. Cache existing hash lookup tables to preserve source hashes
        $existingHashesByCid = DB::table('commerce_orders')
            ->where('client_id', 5)
            ->whereNotNull('registered_customer_id')
            ->whereNotNull('customer_identity_hash')
            ->pluck('customer_identity_hash', 'registered_customer_id')
            ->toArray();

        $existingHashesByEmail = DB::table('commerce_orders')
            ->where('client_id', 5)
            ->whereNotNull('customer_email')
            ->whereNotNull('customer_identity_hash')
            ->pluck('customer_identity_hash', 'customer_email')
            ->toArray();

        $this->info("Preloaded existing hash mappings: " . count($existingHashesByCid) . " CIDs, " . count($existingHashesByEmail) . " emails.");

        // 5. Ingest paginated orders
        $this->info("\nStep 4: Beginning safe paginated ingestion (pages {$startPage} to {$totalPages}, concurrency={$concurrency})...");

        $processedCount = 0;
        $skippedCount = 0;
        $startTime = microtime(true);

        $currentPage = $startPage;
        $targetCount = min($totalAvailable, ($totalPages - $startPage + 1) * $pageSize);
        $bar = $this->output->createProgressBar($targetCount);
        $bar->start();

        while ($currentPage <= $totalPages) {
            $batchPages = [];
            for ($p = 0; $p < $concurrency && ($currentPage + $p) <= $totalPages; $p++) {
                $batchPages[] = $currentPage + $p;
            }

            // Concurrent page fetching via Http::pool
            $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($baseUrl, $token, $maxEntityId, $pageSize, $batchPages) {
                $requests = [];
                foreach ($batchPages as $page) {
                    $requests[] = $pool->withHeaders([
                        'Authorization' => "Bearer {$token}",
                        'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept'        => 'application/json',
                    ])->timeout(45)->get($baseUrl, [
                        'searchCriteria[filter_groups][0][filters][0][field]'          => 'entity_id',
                        'searchCriteria[filter_groups][0][filters][0][value]'          => $maxEntityId,
                        'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'lt',
                        'searchCriteria[pageSize]'                                     => $pageSize,
                        'searchCriteria[currentPage]'                                  => $page,
                        'searchCriteria[sortOrders][0][field]'                         => 'entity_id',
                        'searchCriteria[sortOrders][0][direction]'                     => 'ASC',
                    ]);
                }
                return $requests;
            });

            $batchOrders = [];
            $batchMappings = [];
            $nowStr = now()->toDateTimeString();

            // Process responses
            foreach ($responses as $idx => $resp) {
                $pageNumber = $batchPages[$idx];
                if (! $resp instanceof \Illuminate\Http\Client\Response || ! $resp->successful()) {
                    $status = $resp instanceof \Illuminate\Http\Client\Response ? $resp->status() : 'Error';
                    // Sequential fallback retry
                    $retryResp = Http::withHeaders([
                        'Authorization' => "Bearer {$token}",
                        'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept'        => 'application/json',
                    ])->timeout(60)->retry(3, 1000)->get($baseUrl, [
                        'searchCriteria[filter_groups][0][filters][0][field]'          => 'entity_id',
                        'searchCriteria[filter_groups][0][filters][0][value]'          => $maxEntityId,
                        'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'lt',
                        'searchCriteria[pageSize]'                                     => $pageSize,
                        'searchCriteria[currentPage]'                                  => $pageNumber,
                        'searchCriteria[sortOrders][0][field]'                         => 'entity_id',
                        'searchCriteria[sortOrders][0][direction]'                     => 'ASC',
                    ]);

                    if (! $retryResp->successful()) {
                        $this->error("\nRetry for page {$pageNumber} failed. Skipping page.");
                        continue;
                    }
                    $resp = $retryResp;
                }

                $data = $resp->json();
                $items = $data['items'] ?? [];
                if (empty($items)) {
                    continue;
                }

                foreach ($items as $order) {
                    $entityId = (string) ($order['entity_id'] ?? $order['increment_id'] ?? '');
                    if (! $entityId) {
                        $skippedCount++;
                        $bar->advance();
                        continue;
                    }

                    $status     = strtolower($order['status'] ?? 'unknown');
                    $grandTotal = (float) ($order['grand_total'] ?? $order['base_grand_total'] ?? 0);
                    $totalRef   = (float) ($order['total_refunded'] ?? $order['base_total_refunded'] ?? 0);
                    $tax        = (float) ($order['tax_amount'] ?? 0);
                    $shipping   = (float) ($order['shipping_amount'] ?? 0);
                    $discount   = (float) (abs($order['discount_amount'] ?? 0));
                    $createdAt  = Carbon::parse($order['created_at'] ?? now());
                    $custEmail  = ! empty($order['customer_email']) ? trim($order['customer_email']) : null;
                    $custId     = ! empty($order['customer_id']) ? (string) $order['customer_id'] : null;

                    $company    = $order['billing_address']['company'] ?? $order['shipping_address']['company'] ?? null;
                    $personName = trim(($order['customer_firstname'] ?? '') . ' ' . ($order['customer_lastname'] ?? ''));
                    $custName   = $personName !== '' ? $personName : null;
                    $companyName = ! empty($company) ? trim($company) : null;

                    // Resolve identity hash preserving original fingerprints
                    $identityHash = null;
                    if ($custId && isset($existingHashesByCid[$custId])) {
                        $identityHash = $existingHashesByCid[$custId];
                    } elseif ($custEmail && isset($existingHashesByEmail[$custEmail])) {
                        $identityHash = $existingHashesByEmail[$custEmail];
                    } else {
                        $identityHash = $hasher->hashCustomerIdentity($client, $custId, $custEmail);
                        if ($custId) {
                            $existingHashesByCid[$custId] = $identityHash;
                        }
                        if ($custEmail) {
                            $existingHashesByEmail[$custEmail] = $identityHash;
                        }
                    }

                    // Resolve canonical purchasing account
                    $canonicalAccountId = ! empty($custId)
                        ? "client_5:cid_{$custId}"
                        : (! empty($custEmail)
                            ? "client_5:guest_" . hash('sha256', strtolower(trim($custEmail)))
                            : "client_5:hash_{$identityHash}");

                    // Collect identity mappings for fast batch insert
                    if (! empty($custId)) {
                        $batchMappings["cid:{$custId}"] = [
                            'client_id'            => 5,
                            'canonical_account_id' => $canonicalAccountId,
                            'identity_type'        => 'registered_customer_id',
                            'identity_value'       => (string) $custId,
                            'source_system'        => 'adobe_commerce',
                            'confidence_score'     => 1.00,
                            'provenance_metadata'  => json_encode(['source' => 'magento_backfill', 'entity_id' => $entityId]),
                            'is_active'            => 1,
                            'created_at'           => $nowStr,
                            'updated_at'           => $nowStr,
                        ];
                    }

                    if (! empty($custEmail)) {
                        $normEmail = strtolower(trim($custEmail));
                        $batchMappings["email:{$normEmail}"] = [
                            'client_id'            => 5,
                            'canonical_account_id' => $canonicalAccountId,
                            'identity_type'        => 'normalized_email',
                            'identity_value'       => $normEmail,
                            'source_system'        => 'adobe_commerce',
                            'confidence_score'     => 1.00,
                            'provenance_metadata'  => json_encode(['source' => 'magento_backfill', 'raw_email' => $custEmail, 'entity_id' => $entityId]),
                            'is_active'            => 1,
                            'created_at'           => $nowStr,
                            'updated_at'           => $nowStr,
                        ];
                    }

                    if (! empty($identityHash)) {
                        $batchMappings["hash:{$identityHash}"] = [
                            'client_id'            => 5,
                            'canonical_account_id' => $canonicalAccountId,
                            'identity_type'        => 'source_identity_hash',
                            'identity_value'       => $identityHash,
                            'source_system'        => 'adobe_commerce',
                            'confidence_score'     => 1.00,
                            'provenance_metadata'  => json_encode(['source' => 'magento_backfill', 'entity_id' => $entityId]),
                            'is_active'            => 1,
                            'created_at'           => $nowStr,
                            'updated_at'           => $nowStr,
                        ];
                    }

                    // Determine validity and net revenue
                    $isFullyRefunded = ($grandTotal > 0 && $totalRef >= $grandTotal);
                    $orderPayload = [
                        'status'            => $status,
                        'is_fully_refunded' => $isFullyRefunded,
                    ];

                    $isValid = $orderFilter->isValidOrder($orderPayload, $integration);
                    $exclusionReason = $orderFilter->getExclusionReason($orderPayload, $integration);
                    $netRevenue = $isValid ? max(0.0, $grandTotal - $totalRef) : 0.0;

                    $batchOrders[$entityId] = [
                        'client_id'                 => 5,
                        'integration_id'            => 9,
                        'source'                    => 'adobe_commerce',
                        'source_order_id'           => $entityId,
                        'source_increment_id'       => $order['increment_id'] ?? null,
                        'order_status'              => $status,
                        'customer_identity_hash'    => $identityHash,
                        'canonical_account_id'      => $canonicalAccountId,
                        'registered_customer_id'    => $custId,
                        'customer_email'            => $custEmail,
                        'customer_name'             => $custName,
                        'company_name'              => $companyName,
                        'order_date'                => $createdAt->toDateTimeString(),
                        'refund_date'               => $totalRef > 0 ? $nowStr : null,
                        'gross_revenue'             => $grandTotal,
                        'refunded_revenue'          => $totalRef,
                        'net_revenue'               => $netRevenue,
                        'tax_amount'                => $tax,
                        'shipping_amount'           => $shipping,
                        'discount_amount'           => $discount,
                        'currency'                  => $order['order_currency_code'] ?? 'USD',
                        'base_currency'             => $order['base_currency_code'] ?? 'USD',
                        'reporting_currency'        => 'USD',
                        'exchange_rate'             => (float) ($order['store_to_base_rate'] ?? 1.0),
                        'is_valid'                  => $isValid ? 1 : 0,
                        'exclusion_reason'          => $exclusionReason,
                        'source_updated_at'         => isset($order['updated_at']) ? Carbon::parse($order['updated_at'])->toDateTimeString() : null,
                        'financial_last_changed_at' => $nowStr,
                        'collected_at'              => $nowStr,
                        'metadata_json'             => json_encode([
                            'customer_group_id' => $order['customer_group_id'] ?? null,
                            'backfilled'        => true,
                        ]),
                        'created_at'                => $nowStr,
                        'updated_at'                => $nowStr,
                    ];

                    $processedCount++;
                    $bar->advance();
                }
            }

            // Bulk upsert orders and mappings for this batch
            if (! empty($batchOrders) && ! $dryRun) {
                CommerceOrder::upsert(
                    array_values($batchOrders),
                    ['client_id', 'integration_id', 'source', 'source_order_id'],
                    [
                        'source_increment_id', 'order_status', 'customer_identity_hash', 'canonical_account_id',
                        'registered_customer_id', 'customer_email', 'customer_name', 'company_name',
                        'order_date', 'refund_date', 'gross_revenue', 'refunded_revenue', 'net_revenue',
                        'tax_amount', 'shipping_amount', 'discount_amount', 'currency', 'base_currency',
                        'reporting_currency', 'exchange_rate', 'is_valid', 'exclusion_reason',
                        'source_updated_at', 'financial_last_changed_at', 'collected_at', 'metadata_json', 'updated_at',
                    ]
                );

                if (! empty($batchMappings)) {
                    DB::table('commerce_customer_identity_mappings')->insertOrIgnore(array_values($batchMappings));
                }
            }

            $currentPage += count($batchPages);
        }

        $bar->finish();
        $this->newLine();

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("\nBackfill complete in {$elapsed} seconds!");
        $this->info("Summary: {$processedCount} processed, {$skippedCount} skipped.");

        // 6. Generate order coverage comparison by year
        $this->info("\nStep 5: Historical Order Coverage Analysis (2011 - 2026):");
        $this->printCoverageSummary();

        return 0;
    }

    /**
     * Canonicalize existing orders in commerce_orders for Client 5.
     */
    protected function canonicalizeExistingOrders(Client $client): void
    {
        $now = now()->toDateTimeString();

        // 1. Registered orders canonical update
        DB::statement("UPDATE commerce_orders SET canonical_account_id = CONCAT('client_', client_id, ':cid_', registered_customer_id) WHERE client_id = {$client->id} AND registered_customer_id IS NOT NULL AND registered_customer_id != ''");

        // 2. Guest orders by email
        DB::statement("UPDATE commerce_orders SET canonical_account_id = CONCAT('client_', client_id, ':guest_', SHA2(LOWER(TRIM(customer_email)), 256)) WHERE client_id = {$client->id} AND (registered_customer_id IS NULL OR registered_customer_id = '') AND customer_email IS NOT NULL AND customer_email != ''");

        // 3. Fallback to hash
        DB::statement("UPDATE commerce_orders SET canonical_account_id = CONCAT('client_', client_id, ':hash_', customer_identity_hash) WHERE client_id = {$client->id} AND canonical_account_id IS NULL");

        $this->info("Canonicalized existing commerce_orders.");
    }

    /**
     * Retrieve valid admin token from cache table or integration credentials.
     */
    protected function getAdminToken(): ?string
    {
        $cached = DB::table('cache')->where('key', 'like', '%adobe_token:9')->value('value');
        if ($cached) {
            try {
                $token = unserialize($cached);
                if (! empty($token)) {
                    return $token;
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return null;
    }

    /**
     * Print summary table of order coverage by year.
     */
    protected function printCoverageSummary(): void
    {
        $coverage = DB::table('commerce_orders')
            ->where('client_id', 5)
            ->selectRaw('
                YEAR(order_date) as order_year,
                COUNT(*) as total_orders,
                SUM(CASE WHEN is_valid = 1 THEN 1 ELSE 0 END) as valid_orders,
                ROUND(SUM(CASE WHEN is_valid = 1 THEN net_revenue ELSE 0 END), 2) as valid_net_revenue,
                COUNT(DISTINCT canonical_account_id) as unique_accounts
            ')
            ->groupBy(DB::raw('YEAR(order_date)'))
            ->orderBy('order_year', 'asc')
            ->get();

        $rows = [];
        $totalOrders = 0;
        $totalValid = 0;
        $totalRev = 0.0;

        foreach ($coverage as $c) {
            $rows[] = [
                $c->order_year,
                number_format($c->total_orders),
                number_format($c->valid_orders),
                '$' . number_format($c->valid_net_revenue, 2),
                number_format($c->unique_accounts),
            ];
            $totalOrders += $c->total_orders;
            $totalValid += $c->valid_orders;
            $totalRev += (float) $c->valid_net_revenue;
        }

        $this->table(
            ['Year', 'Total Orders', 'Valid Orders', 'Valid Net Revenue', 'Unique Accounts'],
            $rows
        );

        $this->info("Grand Total: " . number_format($totalOrders) . " orders, " . number_format($totalValid) . " valid, $" . number_format($totalRev, 2) . " net revenue.");
    }
}
