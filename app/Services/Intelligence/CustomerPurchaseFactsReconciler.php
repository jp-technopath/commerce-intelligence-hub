<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CustomerPurchaseFactsReconciler
 *
 * Rebuilds derived customer purchase facts directly from authoritative, validated
 * order records in commerce_orders. Safely archives orphaned or desynchronized
 * fact records into commerce_customer_purchase_facts_archive before pruning.
 */
class CustomerPurchaseFactsReconciler
{
    protected CustomerPurchaseAnalysisService $analysisService;

    public function __construct(CustomerPurchaseAnalysisService $analysisService)
    {
        $this->analysisService = $analysisService;
    }

    /**
     * Reconcile derived purchase facts for a specific client.
     *
     * @param  Client|int  $client
     * @param  bool  $dryRun  If true, computes audit balances without writing to DB.
     * @return array{
     *   client_id: int,
     *   client_name: string,
     *   dry_run: bool,
     *   initial_fact_count: int,
     *   authoritative_customer_count: int,
     *   authoritative_valid_orders: int,
     *   authoritative_net_revenue: float,
     *   authoritative_repeat_customers: int,
     *   authoritative_repeat_rate_pct: float,
     *   orphaned_facts_to_archive: int,
     *   post_reconciled_fact_count: int,
     *   is_balanced: bool
     * }
     */
    public function reconcileClient(Client|int $client, bool $dryRun = false): array
    {
        $clientModel = $client instanceof Client ? $client : Client::findOrFail($client);
        $clientId = $clientModel->id;

        // 1. Snapshot initial state
        $initialFactCount = CommerceCustomerPurchaseFact::where('client_id', $clientId)->count();

        // 2. Aggregate authoritative derived facts directly from valid commerce_orders
        $authoritative = DB::table('commerce_orders')
            ->where('client_id', $clientId)
            ->where('is_valid', true)
            ->whereNotNull('customer_identity_hash')
            ->selectRaw('
                client_id,
                customer_identity_hash,
                MAX(registered_customer_id) as customer_id,
                MAX(customer_email) as customer_email,
                MAX(customer_name) as customer_name,
                MAX(company_name) as company_name,
                MIN(order_date) as first_valid_order_at,
                MAX(order_date) as latest_valid_order_at,
                COUNT(*) as lifetime_valid_order_count,
                SUM(net_revenue) as lifetime_net_revenue,
                MAX(CASE WHEN registered_customer_id IS NOT NULL THEN 1 ELSE 0 END) as is_registered_customer
            ')
            ->groupBy('client_id', 'customer_identity_hash')
            ->get();

        $authCustomerCount = $authoritative->count();
        $authValidOrders = (int) $authoritative->sum('lifetime_valid_order_count');
        $authNetRevenue = round((float) $authoritative->sum('lifetime_net_revenue'), 2);
        $authRepeatCusts = $authoritative->where('lifetime_valid_order_count', '>=', 2)->count();
        $authRepeatRate = $authCustomerCount > 0 ? round(($authRepeatCusts / $authCustomerCount) * 100, 2) : 0.0;

        // 3. Identify orphaned fact records that do not map to any valid order hash
        $orphanedFacts = DB::table('commerce_customer_purchase_facts')
            ->where('client_id', $clientId)
            ->whereNotIn('customer_identity_hash', function ($query) use ($clientId) {
                $query->select('customer_identity_hash')
                    ->from('commerce_orders')
                    ->where('client_id', $clientId)
                    ->where('is_valid', true)
                    ->whereNotNull('customer_identity_hash');
            })
            ->get();

        $orphanedCount = $orphanedFacts->count();

        if ($dryRun) {
            return [
                'client_id'                      => $clientId,
                'client_name'                    => $clientModel->name,
                'dry_run'                        => true,
                'initial_fact_count'             => $initialFactCount,
                'authoritative_customer_count'   => $authCustomerCount,
                'authoritative_valid_orders'     => $authValidOrders,
                'authoritative_net_revenue'      => $authNetRevenue,
                'authoritative_repeat_customers' => $authRepeatCusts,
                'authoritative_repeat_rate_pct'  => $authRepeatRate,
                'orphaned_facts_to_archive'      => $orphanedCount,
                'post_reconciled_fact_count'     => $authCustomerCount,
                'is_balanced'                    => ($initialFactCount - $orphanedCount) === $authCustomerCount,
            ];
        }

        // 4. Execute safe atomic reconciliation inside a transaction
        $now = Carbon::now();
        $nowStr = $now->toDateTimeString();

        DB::transaction(function () use ($clientId, $authoritative, $orphanedFacts, $nowStr) {
            // A. Archive orphaned facts before pruning
            if ($orphanedFacts->isNotEmpty()) {
                $archiveRecords = [];
                $orphanIds = [];

                foreach ($orphanedFacts as $fact) {
                    $orphanIds[] = $fact->id;
                    $archiveRecords[] = [
                        'original_fact_id'           => $fact->id,
                        'client_id'                  => $fact->client_id,
                        'customer_identity_hash'     => $fact->customer_identity_hash,
                        'customer_id'                => $fact->customer_id,
                        'first_valid_order_at'       => $fact->first_valid_order_at,
                        'latest_valid_order_at'      => $fact->latest_valid_order_at,
                        'lifetime_valid_order_count' => $fact->lifetime_valid_order_count,
                        'lifetime_net_revenue'       => $fact->lifetime_net_revenue,
                        'is_registered_customer'     => $fact->is_registered_customer,
                        'refreshed_at'               => $fact->refreshed_at,
                        'archive_reason'             => 'reconciliation_orphaned_hash',
                        'archived_at'                => $nowStr,
                        'created_at'                 => $nowStr,
                        'updated_at'                 => $nowStr,
                    ];
                }

                foreach (array_chunk($archiveRecords, 500) as $chunk) {
                    DB::table('commerce_customer_purchase_facts_archive')->insert($chunk);
                }

                // Safely prune archived orphans from facts table
                DB::table('commerce_customer_purchase_facts')
                    ->whereIn('id', $orphanIds)
                    ->delete();
            }

            // B. Chunked bulk upsert of authoritative derived facts
            $existingFacts = DB::table('commerce_customer_purchase_facts')
                ->where('client_id', $clientId)
                ->get()
                ->keyBy('customer_identity_hash');

            $factUpsertBatch = [];
            foreach ($authoritative as $row) {
                $existing = $existingFacts->get($row->customer_identity_hash);
                $factUpsertBatch[] = [
                    'client_id'                  => $clientId,
                    'customer_identity_hash'     => $row->customer_identity_hash,
                    'customer_id'                => $row->customer_id ?? $existing?->customer_id,
                    'customer_email'             => $row->customer_email ?: ($existing?->customer_email ?: null),
                    'customer_name'              => $row->customer_name ?: ($existing?->customer_name ?: null),
                    'company_name'               => $row->company_name ?: ($existing?->company_name ?: null),
                    'first_valid_order_at'       => $row->first_valid_order_at,
                    'latest_valid_order_at'      => $row->latest_valid_order_at,
                    'lifetime_valid_order_count' => $row->lifetime_valid_order_count,
                    'lifetime_net_revenue'       => $row->lifetime_net_revenue,
                    'is_registered_customer'     => $row->is_registered_customer,
                    'refreshed_at'               => $nowStr,
                    'created_at'                 => $nowStr,
                    'updated_at'                 => $nowStr,
                ];
            }

            foreach (array_chunk($factUpsertBatch, 500) as $chunk) {
                CommerceCustomerPurchaseFact::upsert(
                    $chunk,
                    ['client_id', 'customer_identity_hash'],
                    [
                        'customer_id',
                        'customer_email',
                        'customer_name',
                        'company_name',
                        'first_valid_order_at',
                        'latest_valid_order_at',
                        'lifetime_valid_order_count',
                        'lifetime_net_revenue',
                        'is_registered_customer',
                        'refreshed_at',
                        'updated_at',
                    ]
                );
            }
        });

        // 5. Invariant balance checks
        $finalFactCount = CommerceCustomerPurchaseFact::where('client_id', $clientId)->count();
        $finalFactOrders = (int) CommerceCustomerPurchaseFact::where('client_id', $clientId)->sum('lifetime_valid_order_count');
        $finalFactRevenue = round((float) CommerceCustomerPurchaseFact::where('client_id', $clientId)->sum('lifetime_net_revenue'), 2);

        $ordersValidCount = (int) CommerceOrder::where('client_id', $clientId)->where('is_valid', true)->count();
        $ordersValidRevenue = round((float) CommerceOrder::where('client_id', $clientId)->where('is_valid', true)->sum('net_revenue'), 2);

        $isBalanced = ($finalFactCount === $authCustomerCount)
            && ($finalFactOrders === $ordersValidCount)
            && (abs($finalFactRevenue - $ordersValidRevenue) < 0.01);

        // 6. Invalidate profile and baseline memory caches
        $this->analysisService->clearCache();

        Log::info('CustomerPurchaseFactsReconciler: completed reconciliation', [
            'client_id'          => $clientId,
            'initial_fact_count' => $initialFactCount,
            'final_fact_count'   => $finalFactCount,
            'archived_orphans'   => $orphanedCount,
            'is_balanced'        => $isBalanced,
        ]);

        return [
            'client_id'                      => $clientId,
            'client_name'                    => $clientModel->name,
            'dry_run'                        => false,
            'initial_fact_count'             => $initialFactCount,
            'authoritative_customer_count'   => $authCustomerCount,
            'authoritative_valid_orders'     => $authValidOrders,
            'authoritative_net_revenue'      => $authNetRevenue,
            'authoritative_repeat_customers' => $authRepeatCusts,
            'authoritative_repeat_rate_pct'  => $authRepeatRate,
            'orphaned_facts_to_archive'      => $orphanedCount,
            'post_reconciled_fact_count'     => $finalFactCount,
            'is_balanced'                    => $isBalanced,
        ];
    }
}
