<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\Integration;
use App\Services\Metrics\ValidOrderFilter;
use App\Services\Security\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

class SyncTranspartOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transpart:sync-data {--days=365 : Number of historical days to sync} {--from= : Start date (YYYY-MM-DD)} {--to= : End date (YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync historical sales orders for Transpart directly from Magento database into commerce_orders and commerce_metrics via bulk upsert';

    private ValidOrderFilter $orderFilter;
    private CustomerIdentityHasher $identityHasher;

    public function __construct()
    {
        parent::__construct();
        $this->orderFilter    = new ValidOrderFilter();
        $this->identityHasher = new CustomerIdentityHasher();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Transpart Magento Fast Bulk Data Sync ===');

        $client = Client::find(2);
        if (! $client) {
            $this->error('Client ID 2 (Transpart) not found.');
            return self::FAILURE;
        }

        $integration = Integration::where('client_id', 2)
            ->where('integration_type', 'adobe_commerce')
            ->first();

        if (! $integration) {
            $this->error('Adobe Commerce integration for Transpart not found.');
            return self::FAILURE;
        }

        $creds  = $integration->credentials_json ?? [];
        $dbHost = $creds['db_host'] ?? 'cloudhost-8643311.us-midwest-1.nxcli.net';
        $dbName = $creds['db_name'] ?? 'a34148d5_09052023';
        $dbUser = $creds['db_user'] ?? 'a34148d5_jp';
        $dbPass = $creds['db_password'] ?? 'GrapedHookeyFeintHewers';
        $dbPort = $creds['db_port'] ?? 3306;

        $days    = (int) $this->option('days');
        $fromOpt = $this->option('from');
        $toOpt   = $this->option('to');

        if ($fromOpt && $toOpt) {
            $fromDate = Carbon::parse($fromOpt)->startOfDay();
            $toDate   = Carbon::parse($toOpt)->endOfDay();
        } else {
            $fromDate = now()->subDays($days)->startOfDay();
            $toDate   = now()->endOfDay();
        }

        $this->info("Syncing orders between {$fromDate->toDateTimeString()} and {$toDate->toDateTimeString()}...");

        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 15,
            ]);
            $this->info('Connected to Transpart Magento database successfully.');
        } catch (\Exception $e) {
            $this->error("Failed to connect to Transpart Magento DB: {$e->getMessage()}");
            return self::FAILURE;
        }

        $sql = '
            SELECT
                entity_id,
                increment_id,
                status,
                grand_total,
                total_refunded,
                tax_amount,
                shipping_amount,
                discount_amount,
                created_at,
                updated_at,
                customer_email,
                customer_id,
                customer_is_guest,
                customer_group_id,
                order_currency_code,
                base_currency_code,
                store_to_base_rate,
                total_qty_ordered
            FROM sales_order
            WHERE created_at >= :from_date AND created_at <= :to_date
            ORDER BY created_at ASC
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':from_date' => $fromDate->toDateTimeString(),
            ':to_date'   => $toDate->toDateTimeString(),
        ]);

        $orders = $stmt->fetchAll();
        $totalFetched = count($orders);

        $this->info("Fetched {$totalFetched} sales orders from Magento DB.");

        if ($totalFetched === 0) {
            $this->warn('No orders found in date range.');
            return self::SUCCESS;
        }

        $orderBatch     = [];
        $customerFacts  = [];
        $dailyAggregates = [];

        $validOrdersCount     = 0;
        $totalValidNetRevenue = 0.0;
        $totalItemsSold       = 0;
        $nowStr               = now()->toDateTimeString();

        foreach ($orders as $order) {
            $entityId = (string) ($order['entity_id'] ?? $order['increment_id'] ?? '');
            if (! $entityId) continue;

            $status     = strtolower($order['status'] ?? 'unknown');
            $grandTotal = (float) ($order['grand_total'] ?? 0);
            $totalRef   = (float) ($order['total_refunded'] ?? 0);
            $tax        = (float) ($order['tax_amount'] ?? 0);
            $shipping   = (float) ($order['shipping_amount'] ?? 0);
            $discount   = (float) (abs($order['discount_amount'] ?? 0));
            $qty        = (int) ($order['total_qty_ordered'] ?? 0);
            $createdAt  = Carbon::parse($order['created_at'] ?? now());
            $custEmail  = $order['customer_email'] ?? null;
            $custId     = isset($order['customer_id']) ? (string) $order['customer_id'] : null;
            $isGuest    = (bool) ($order['customer_is_guest'] ?? false);

            $identityHash = $this->identityHasher->hashCustomerIdentity($client, $custId, $custEmail);

            $isFullyRefunded = ($grandTotal > 0 && $totalRef >= $grandTotal);
            $orderPayload = [
                'status'            => $status,
                'is_fully_refunded' => $isFullyRefunded,
            ];

            $isValid         = $this->orderFilter->isValidOrder($orderPayload, $integration);
            $exclusionReason = $this->orderFilter->getExclusionReason($orderPayload, $integration);
            $netRevenue      = $isValid ? max(0.0, $grandTotal - $totalRef) : 0.0;

            $orderBatch[] = [
                'client_id'                 => $client->id,
                'integration_id'            => $integration->id,
                'source'                    => 'adobe_commerce',
                'source_order_id'           => $entityId,
                'source_increment_id'       => $order['increment_id'] ?? null,
                'order_status'              => $status,
                'customer_identity_hash'    => $identityHash,
                'registered_customer_id'    => $custId,
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
                'reporting_currency'        => $client->currency ?? 'USD',
                'exchange_rate'             => (float) ($order['store_to_base_rate'] ?? 1.0),
                'is_valid'                  => $isValid ? 1 : 0,
                'exclusion_reason'          => $exclusionReason,
                'source_updated_at'         => isset($order['updated_at']) ? Carbon::parse($order['updated_at'])->toDateTimeString() : null,
                'financial_last_changed_at' => $nowStr,
                'collected_at'              => $nowStr,
                'metadata_json'             => json_encode(['customer_group_id' => $order['customer_group_id'] ?? null]),
                'created_at'                => $nowStr,
                'updated_at'                => $nowStr,
            ];

            if ($isValid && $identityHash) {
                if (! isset($customerFacts[$identityHash])) {
                    $customerFacts[$identityHash] = [
                        'client_id'                  => $client->id,
                        'customer_identity_hash'     => $identityHash,
                        'customer_id'                => $custId,
                        'first_valid_order_at'       => $createdAt->toDateTimeString(),
                        'latest_valid_order_at'      => $createdAt->toDateTimeString(),
                        'lifetime_valid_order_count' => 1,
                        'lifetime_net_revenue'       => $netRevenue,
                        'is_registered_customer'     => ($custId !== null) ? 1 : 0,
                        'refreshed_at'               => $nowStr,
                        'created_at'                 => $nowStr,
                        'updated_at'                 => $nowStr,
                    ];
                } else {
                    if ($createdAt->lt(Carbon::parse($customerFacts[$identityHash]['first_valid_order_at']))) {
                        $customerFacts[$identityHash]['first_valid_order_at'] = $createdAt->toDateTimeString();
                    }
                    if ($createdAt->gt(Carbon::parse($customerFacts[$identityHash]['latest_valid_order_at']))) {
                        $customerFacts[$identityHash]['latest_valid_order_at'] = $createdAt->toDateTimeString();
                    }
                    $customerFacts[$identityHash]['lifetime_valid_order_count'] += 1;
                    $customerFacts[$identityHash]['lifetime_net_revenue']       += $netRevenue;
                }
            }

            // Daily metrics aggregation
            $dateKey = $createdAt->format('Y-m-d');
            if (! isset($dailyAggregates[$dateKey])) {
                $dailyAggregates[$dateKey] = [
                    'revenue'             => 0.0,
                    'orders'              => 0,
                    'items_sold'          => 0,
                    'new_customers'       => 0,
                    'returning_customers' => 0,
                    'metadata_json'       => ['statuses' => []],
                    '_emails'             => [],
                ];
            }

            if ($isValid) {
                $dailyAggregates[$dateKey]['revenue']    += $netRevenue;
                $dailyAggregates[$dateKey]['items_sold'] += $qty;
                $dailyAggregates[$dateKey]['orders']++;

                $validOrdersCount++;
                $totalValidNetRevenue += $netRevenue;
                $totalItemsSold += $qty;
            }

            if ($custEmail && ! in_array($custEmail, $dailyAggregates[$dateKey]['_emails'], true)) {
                $dailyAggregates[$dateKey]['_emails'][] = $custEmail;
                if ($isGuest) {
                    $dailyAggregates[$dateKey]['new_customers']++;
                } else {
                    $dailyAggregates[$dateKey]['returning_customers']++;
                }
            }

            $dailyAggregates[$dateKey]['metadata_json']['statuses'][$status] =
                ($dailyAggregates[$dateKey]['metadata_json']['statuses'][$status] ?? 0) + 1;
        }

        // Bulk insert/upsert orders in chunks of 500
        $this->info('Upserting orders into commerce_orders in chunks of 500...');
        $chunks = array_chunk($orderBatch, 500);
        $orderColumnsToUpdate = [
            'source_increment_id', 'order_status', 'customer_identity_hash', 'registered_customer_id',
            'order_date', 'refund_date', 'gross_revenue', 'refunded_revenue', 'net_revenue',
            'tax_amount', 'shipping_amount', 'discount_amount', 'currency', 'base_currency',
            'reporting_currency', 'exchange_rate', 'is_valid', 'exclusion_reason',
            'source_updated_at', 'financial_last_changed_at', 'collected_at', 'metadata_json', 'updated_at',
        ];

        foreach ($chunks as $chunk) {
            DB::table('commerce_orders')->upsert(
                $chunk,
                ['client_id', 'integration_id', 'source', 'source_order_id'],
                $orderColumnsToUpdate
            );
        }

        // Bulk insert/upsert customer facts in chunks of 500
        $this->info('Upserting customer purchase facts in chunks of 500...');
        $factChunks = array_chunk(array_values($customerFacts), 500);
        $factColumnsToUpdate = [
            'customer_id', 'first_valid_order_at', 'latest_valid_order_at',
            'lifetime_valid_order_count', 'lifetime_net_revenue', 'is_registered_customer', 'refreshed_at', 'updated_at',
        ];

        foreach ($factChunks as $chunk) {
            DB::table('commerce_customer_purchase_facts')->upsert(
                $chunk,
                ['client_id', 'customer_identity_hash'],
                $factColumnsToUpdate
            );
        }

        // Upsert daily metrics
        $this->info('Upserting ' . count($dailyAggregates) . ' daily commerce_metrics records...');
        $metricBatch = [];
        foreach ($dailyAggregates as $date => $metrics) {
            unset($metrics['_emails']);
            $ordersCount = $metrics['orders'];
            $revenueVal  = $metrics['revenue'];
            $aov         = $ordersCount > 0 ? round($revenueVal / $ordersCount, 2) : 0.0;

            $metricBatch[] = [
                'client_id'           => $client->id,
                'date'                => $date,
                'source'              => 'adobe_commerce',
                'revenue'             => $revenueVal,
                'orders'              => $ordersCount,
                'items_sold'          => $metrics['items_sold'],
                'new_customers'       => $metrics['new_customers'],
                'returning_customers' => $metrics['returning_customers'],
                'aov'                 => $aov,
                'average_order_value' => $aov,
                'metadata_json'       => json_encode($metrics['metadata_json']),
                'created_at'          => $nowStr,
                'updated_at'          => $nowStr,
            ];
        }

        $metricChunks = array_chunk($metricBatch, 500);
        $metricColumnsToUpdate = [
            'revenue', 'orders', 'items_sold', 'new_customers', 'returning_customers',
            'aov', 'average_order_value', 'metadata_json', 'updated_at',
        ];

        foreach ($metricChunks as $chunk) {
            DB::table('commerce_metrics')->upsert(
                $chunk,
                ['client_id', 'date', 'source'],
                $metricColumnsToUpdate
            );
        }

        $integration->update(['last_sync_at' => now()]);

        $this->newLine();
        $this->info('✓ Fast Bulk Sync completed successfully!');
        $this->table(['Metric', 'Value'], [
            ['Total Fetched Orders', number_format($totalFetched)],
            ['Valid Orders Stored', number_format($validOrdersCount)],
            ['Total Net Revenue', '$' . number_format($totalValidNetRevenue, 2)],
            ['Total Items Sold', number_format($totalItemsSold)],
            ['Days Processed', count($dailyAggregates)],
        ]);

        return self::SUCCESS;
    }
}
