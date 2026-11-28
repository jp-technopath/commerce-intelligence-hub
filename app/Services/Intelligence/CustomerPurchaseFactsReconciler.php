<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\CommerceCustomerIdentityMapping;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceOrder;
use App\Services\Security\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CustomerPurchaseFactsReconciler
 *
 * Rebuilds derived customer purchase facts directly from authoritative, validated
 * order records in commerce_orders, consolidated by canonical purchasing account.
 * Safely archives orphaned or merged fact records into commerce_customer_purchase_facts_archive
 * before pruning. Protected by a per-client cache lock against concurrent sync writes.
 */
class CustomerPurchaseFactsReconciler
{
    protected CustomerPurchaseAnalysisService $analysisService;
    protected CustomerIdentityHasher $identityHasher;

    public function __construct(
        CustomerPurchaseAnalysisService $analysisService,
        ?CustomerIdentityHasher $identityHasher = null
    ) {
        $this->analysisService = $analysisService;
        $this->identityHasher = $identityHasher ?? new CustomerIdentityHasher();
    }

    /**
     * Reconcile derived purchase facts for a specific client across all valid orders.
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

        if ($dryRun) {
            return $this->executeReconcileClient($clientModel, true);
        }

        return Cache::lock("reconcile_purchase_facts:{$clientId}", 60)->block(15, function () use ($clientModel) {
            return $this->executeReconcileClient($clientModel, false);
        });
    }

    /**
     * Targeted atomic reconciliation for a set of canonical accounts / identity hashes
     * touched by an incremental order sync or manual order state transition.
     *
     * Always recomputes lifetime counts, spend, and first/latest dates from ALL valid
     * commerce_orders belonging to each canonical account — never from the batch alone.
     *
     * @param  array<int, ?string>  $canonicalAccountIds
     * @param  array<int, ?string>  $identityHashes
     * @return array{
     *   client_id: int,
     *   canonical_accounts_processed: int,
     *   upserted_facts: int,
     *   archived_facts: int
     * }
     */
    public function reconcileForCanonicalAccounts(
        Client|int $client,
        array $canonicalAccountIds,
        array $identityHashes = []
    ): array {
        $clientModel = $client instanceof Client ? $client : Client::findOrFail($client);
        $clientId = $clientModel->id;

        $seedCanonicals = array_values(array_unique(array_filter($canonicalAccountIds)));
        $seedHashes = array_values(array_unique(array_filter($identityHashes)));

        if (empty($seedCanonicals) && empty($seedHashes)) {
            return [
                'client_id'                    => $clientId,
                'canonical_accounts_processed' => 0,
                'upserted_facts'               => 0,
                'archived_facts'               => 0,
            ];
        }

        return Cache::lock("reconcile_purchase_facts:{$clientId}", 60)->block(15, function () use ($clientId, $seedCanonicals, $seedHashes) {
            // Expand scope via active identity mappings and existing facts so linked guest/registered rows move together
            $mappedCanonicals = empty($seedHashes) ? [] : CommerceCustomerIdentityMapping::where('client_id', $clientId)
                ->where('is_active', true)
                ->where('identity_type', 'source_identity_hash')
                ->whereIn('identity_value', $seedHashes)
                ->pluck('canonical_account_id')
                ->filter()
                ->all();

            $existingScopedFacts = DB::table('commerce_customer_purchase_facts')
                ->where('client_id', $clientId)
                ->where(function ($q) use ($seedCanonicals, $mappedCanonicals, $seedHashes) {
                    $allCanonicals = array_values(array_unique(array_merge($seedCanonicals, $mappedCanonicals)));
                    if (! empty($allCanonicals)) {
                        $q->whereIn('canonical_account_id', $allCanonicals);
                    }
                    if (! empty($seedHashes)) {
                        $method = ! empty($allCanonicals) ? 'orWhereIn' : 'whereIn';
                        $q->{$method}('customer_identity_hash', $seedHashes);
                    }
                })
                ->get();

            $scopeCanonicals = array_values(array_unique(array_filter(array_merge(
                $seedCanonicals,
                $mappedCanonicals,
                $existingScopedFacts->pluck('canonical_account_id')->all()
            ))));

            $scopeHashes = array_values(array_unique(array_filter(array_merge(
                $seedHashes,
                $existingScopedFacts->pluck('customer_identity_hash')->all()
            ))));

            $mappedEmailsByCanonical = [];
            if (! empty($scopeCanonicals)) {
                $mappedHashes = CommerceCustomerIdentityMapping::where('client_id', $clientId)
                    ->where('is_active', true)
                    ->where('identity_type', 'source_identity_hash')
                    ->whereIn('canonical_account_id', $scopeCanonicals)
                    ->pluck('identity_value')
                    ->filter()
                    ->all();

                $scopeHashes = array_values(array_unique(array_merge($scopeHashes, $mappedHashes)));

                $emailMappings = CommerceCustomerIdentityMapping::where('client_id', $clientId)
                    ->where('is_active', true)
                    ->where('identity_type', 'normalized_email')
                    ->whereIn('canonical_account_id', $scopeCanonicals)
                    ->get(['identity_value', 'canonical_account_id']);

                foreach ($emailMappings as $em) {
                    $norm = $this->identityHasher->normalizeEmail($em->identity_value);
                    if ($norm !== '' && ! empty($em->canonical_account_id)) {
                        $mappedEmailsByCanonical[$norm] = $em->canonical_account_id;
                    }
                }
            }

            $scopeEmails = array_keys($mappedEmailsByCanonical);

            // Recompute from ALL valid orders in scope (including exact email matches for guest-to-registered promotion)
            $rawBuckets = DB::table('commerce_orders')
                ->where('client_id', $clientId)
                ->where('is_valid', true)
                ->whereNotNull('customer_identity_hash')
                ->where(function ($q) use ($scopeCanonicals, $scopeHashes, $scopeEmails) {
                    $hasClause = false;
                    if (! empty($scopeCanonicals)) {
                        $q->whereIn('canonical_account_id', $scopeCanonicals);
                        $hasClause = true;
                    }
                    if (! empty($scopeHashes)) {
                        $method = $hasClause ? 'orWhereIn' : 'whereIn';
                        $q->{$method}('customer_identity_hash', $scopeHashes);
                        $hasClause = true;
                    }
                    if (! empty($scopeEmails)) {
                        $method = $hasClause ? 'orWhereIn' : 'whereIn';
                        $q->{$method}(DB::raw('LOWER(TRIM(customer_email))'), $scopeEmails);
                    }
                })
                ->selectRaw($this->bucketSelectRaw())
                ->groupBy('client_id', 'customer_identity_hash', DB::raw("COALESCE(canonical_account_id, '')"))
                ->get();

            // Promote bucket raw_canonical_id in memory for guest orders matching a verified registered email before consolidation
            foreach ($rawBuckets as $bucket) {
                $norm = $this->identityHasher->normalizeEmail($bucket->customer_email ?? null);
                if ($norm !== '' && isset($mappedEmailsByCanonical[$norm])) {
                    $targetCanonical = $mappedEmailsByCanonical[$norm];
                    if (
                        $bucket->raw_canonical_id === ''
                        || str_contains((string) $bucket->raw_canonical_id, ':guest_')
                        || str_contains((string) $bucket->raw_canonical_id, ':hash_')
                    ) {
                        $bucket->raw_canonical_id = $targetCanonical;
                    }
                }
            }

            $consolidated = $this->consolidateBucketsByCanonicalAccount($clientId, $rawBuckets);
            $survivingHashes = $consolidated->pluck('customer_identity_hash')->all();
            $survivingCanonicals = $consolidated->pluck('canonical_account_id')->filter()->unique()->all();

            // Re-fetch all facts touching surviving hashes, canonical IDs, or verified emails so primary-hash transitions stay clean
            $allAffectedFacts = DB::table('commerce_customer_purchase_facts')
                ->where('client_id', $clientId)
                ->where(function ($q) use ($scopeCanonicals, $scopeHashes, $scopeEmails, $survivingCanonicals, $survivingHashes) {
                    $allC = array_values(array_unique(array_merge($scopeCanonicals, $survivingCanonicals)));
                    $allH = array_values(array_unique(array_merge($scopeHashes, $survivingHashes)));
                    $hasClause = false;
                    if (! empty($allC)) {
                        $q->whereIn('canonical_account_id', $allC);
                        $hasClause = true;
                    }
                    if (! empty($allH)) {
                        $method = $hasClause ? 'orWhereIn' : 'whereIn';
                        $q->{$method}('customer_identity_hash', $allH);
                        $hasClause = true;
                    }
                    if (! empty($scopeEmails)) {
                        $method = $hasClause ? 'orWhereIn' : 'whereIn';
                        $q->{$method}(DB::raw('LOWER(TRIM(customer_email))'), $scopeEmails);
                    }
                })
                ->get();

            $factsToArchive = $allAffectedFacts->filter(
                fn ($f) => ! in_array($f->customer_identity_hash, $survivingHashes, true)
            );

            $nowStr = Carbon::now()->toDateTimeString();

            DB::transaction(function () use ($clientId, $consolidated, $factsToArchive, $allAffectedFacts, $survivingCanonicals, $mappedEmailsByCanonical, $nowStr) {
                // Promote guest/null commerce_orders matching verified registered emails to the canonical account
                foreach ($mappedEmailsByCanonical as $normEmail => $targetCanonical) {
                    DB::table('commerce_orders')
                        ->where('client_id', $clientId)
                        ->where(DB::raw('LOWER(TRIM(customer_email))'), $normEmail)
                        ->where(function ($q) {
                            $q->whereNull('canonical_account_id')
                                ->orWhere('canonical_account_id', '')
                                ->orWhere('canonical_account_id', 'like', '%:guest_%')
                                ->orWhere('canonical_account_id', 'like', '%:hash_%');
                        })
                        ->update([
                            'canonical_account_id' => $targetCanonical,
                            'updated_at'           => $nowStr,
                        ]);
                }

                if ($factsToArchive->isNotEmpty()) {
                    $this->archiveAndDeleteFacts(
                        $factsToArchive,
                        $nowStr,
                        function ($fact) use ($survivingCanonicals, $mappedEmailsByCanonical) {
                            $emailCanonical = $fact->customer_email
                                ? ($mappedEmailsByCanonical[$this->identityHasher->normalizeEmail($fact->customer_email)] ?? null)
                                : null;

                            return (
                                in_array($fact->canonical_account_id, $survivingCanonicals, true)
                                || ($emailCanonical !== null && in_array($emailCanonical, $survivingCanonicals, true))
                            )
                                ? 'reconciliation_canonical_merged'
                                : 'reconciliation_orphaned_hash';
                        }
                    );
                }

                if ($consolidated->isNotEmpty()) {
                    $existingByHash = $allAffectedFacts->keyBy('customer_identity_hash');
                    $this->upsertConsolidatedFacts($clientId, $consolidated, $existingByHash, $nowStr);
                }
            });

            $this->analysisService->clearCache();

            return [
                'client_id'                    => $clientId,
                'canonical_accounts_processed' => $consolidated->count(),
                'upserted_facts'               => $consolidated->count(),
                'archived_facts'               => $factsToArchive->count(),
            ];
        });
    }

    protected function executeReconcileClient(Client $clientModel, bool $dryRun): array
    {
        $clientId = $clientModel->id;

        // 1. Snapshot initial state
        $initialFactCount = CommerceCustomerPurchaseFact::where('client_id', $clientId)->count();

        // 2. Aggregate valid orders by identity hash + canonical_account_id, then consolidate by canonical account
        $rawBuckets = DB::table('commerce_orders')
            ->where('client_id', $clientId)
            ->where('is_valid', true)
            ->whereNotNull('customer_identity_hash')
            ->selectRaw($this->bucketSelectRaw())
            ->groupBy('client_id', 'customer_identity_hash', DB::raw("COALESCE(canonical_account_id, '')"))
            ->get();

        $authoritative = $this->consolidateBucketsByCanonicalAccount($clientId, $rawBuckets);

        $authCustomerCount = $authoritative->count();
        $authValidOrders = (int) $authoritative->sum('lifetime_valid_order_count');
        $authNetRevenue = round((float) $authoritative->sum('lifetime_net_revenue'), 2);
        $authRepeatCusts = $authoritative->where('lifetime_valid_order_count', '>=', 2)->count();
        $authRepeatRate = $authCustomerCount > 0 ? round(($authRepeatCusts / $authCustomerCount) * 100, 2) : 0.0;

        // 3. Identify orphaned or secondary-merged fact records not matching surviving primary hashes
        $survivingHashes = array_flip($authoritative->pluck('customer_identity_hash')->all());
        $survivingCanonicals = array_flip($authoritative->pluck('canonical_account_id')->filter()->all());

        $existingFactsAll = DB::table('commerce_customer_purchase_facts')
            ->where('client_id', $clientId)
            ->get();

        $factsToArchive = $existingFactsAll->filter(
            fn ($fact) => ! isset($survivingHashes[$fact->customer_identity_hash])
        );

        $archivedCount = $factsToArchive->count();

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
                'orphaned_facts_to_archive'      => $archivedCount,
                'post_reconciled_fact_count'     => $authCustomerCount,
                'is_balanced'                    => ($initialFactCount - $archivedCount) === $authCustomerCount,
            ];
        }

        // 4. Execute safe atomic reconciliation inside a transaction
        $nowStr = Carbon::now()->toDateTimeString();

        DB::transaction(function () use ($clientId, $authoritative, $factsToArchive, $existingFactsAll, $survivingCanonicals, $nowStr) {
            if ($factsToArchive->isNotEmpty()) {
                $this->archiveAndDeleteFacts(
                    $factsToArchive,
                    $nowStr,
                    fn ($fact) => ($fact->canonical_account_id && isset($survivingCanonicals[$fact->canonical_account_id]))
                        ? 'reconciliation_canonical_merged'
                        : 'reconciliation_orphaned_hash'
                );
            }

            if ($authoritative->isNotEmpty()) {
                $existingByHash = $existingFactsAll->keyBy('customer_identity_hash');
                $this->upsertConsolidatedFacts($clientId, $authoritative, $existingByHash, $nowStr);
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
            'archived_facts'     => $archivedCount,
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
            'orphaned_facts_to_archive'      => $archivedCount,
            'post_reconciled_fact_count'     => $finalFactCount,
            'is_balanced'                    => $isBalanced,
        ];
    }

    protected function bucketSelectRaw(): string
    {
        return "
            client_id,
            customer_identity_hash,
            COALESCE(canonical_account_id, '') as raw_canonical_id,
            MAX(registered_customer_id) as customer_id,
            MAX(customer_email) as customer_email,
            MAX(customer_name) as customer_name,
            MAX(company_name) as company_name,
            MIN(order_date) as first_valid_order_at,
            MAX(order_date) as latest_valid_order_at,
            COUNT(*) as lifetime_valid_order_count,
            SUM(net_revenue) as lifetime_net_revenue,
            MAX(CASE WHEN registered_customer_id IS NOT NULL AND registered_customer_id != '' THEN 1 ELSE 0 END) as is_registered_customer
        ";
    }

    /**
     * Consolidate SQL order buckets by canonical purchasing account within client scope.
     * Resolves canonical IDs deterministically via CID, active identity mappings, or
     * exact normalized email (never by company name or email domain).
     */
    protected function consolidateBucketsByCanonicalAccount(int $clientId, Collection $rawBuckets): Collection
    {
        if ($rawBuckets->isEmpty()) {
            return collect();
        }

        // Load active identity mappings for exact email/hash -> canonical resolution
        $mappings = CommerceCustomerIdentityMapping::where('client_id', $clientId)
            ->where('is_active', true)
            ->get(['identity_type', 'identity_value', 'canonical_account_id']);

        $emailToCanonical = [];
        $hashToCanonical = [];
        $cidToCanonical = [];

        foreach ($mappings as $m) {
            if ($m->identity_type === 'normalized_email') {
                $emailToCanonical[$m->identity_value] = $m->canonical_account_id;
            } elseif ($m->identity_type === 'source_identity_hash') {
                $hashToCanonical[$m->identity_value] = $m->canonical_account_id;
            } elseif ($m->identity_type === 'registered_customer_id') {
                $cidToCanonical[$m->identity_value] = $m->canonical_account_id;
            }
        }

        // Also build exact registered-email -> canonical map when an email uniquely maps to one registered account
        $registeredEmailCounts = [];
        $registeredEmailTarget = [];
        foreach ($rawBuckets as $b) {
            $cid = trim((string) ($b->customer_id ?? ''));
            $email = $this->identityHasher->normalizeEmail($b->customer_email ?? null);
            if ($cid !== '' && $email !== '') {
                $targetCanonical = $b->raw_canonical_id !== ''
                    ? $b->raw_canonical_id
                    : ($cidToCanonical[$cid] ?? "client_{$clientId}:cid_{$cid}");
                $registeredEmailCounts[$email][$targetCanonical] = true;
                $registeredEmailTarget[$email] = $targetCanonical;
            }
        }

        // Identify hashes currently referenced by Findings so consolidations keep existing links stable
        $findingHashes = DB::table('findings')
            ->where('client_id', $clientId)
            ->whereNotNull('account_identity_hash')
            ->where('account_identity_hash', '!=', '')
            ->pluck('account_identity_hash')
            ->flip()
            ->all();

        $grouped = [];

        foreach ($rawBuckets as $b) {
            $cid = trim((string) ($b->customer_id ?? ''));
            $email = $this->identityHasher->normalizeEmail($b->customer_email ?? null);
            $rawCanonical = trim((string) ($b->raw_canonical_id ?? ''));

            if ($rawCanonical !== '') {
                $canonicalId = $rawCanonical;
            } elseif ($cid !== '') {
                $canonicalId = $cidToCanonical[$cid] ?? "client_{$clientId}:cid_{$cid}";
            } elseif ($email !== '' && isset($emailToCanonical[$email])) {
                $canonicalId = $emailToCanonical[$email];
            } elseif ($email !== '' && isset($registeredEmailCounts[$email]) && count($registeredEmailCounts[$email]) === 1) {
                $canonicalId = $registeredEmailTarget[$email];
            } elseif (isset($hashToCanonical[$b->customer_identity_hash])) {
                $canonicalId = $hashToCanonical[$b->customer_identity_hash];
            } elseif ($email !== '') {
                $canonicalId = "client_{$clientId}:guest_" . hash('sha256', $email);
            } else {
                $canonicalId = "client_{$clientId}:hash_{$b->customer_identity_hash}";
            }

            $grouped[$canonicalId][] = $b;
        }

        $result = [];

        foreach ($grouped as $canonicalId => $buckets) {
            // Sort buckets to pick deterministic primary hash & best identity display
            usort($buckets, function ($a, $b) use ($findingHashes) {
                $aHasFinding = isset($findingHashes[$a->customer_identity_hash]) ? 1 : 0;
                $bHasFinding = isset($findingHashes[$b->customer_identity_hash]) ? 1 : 0;
                if ($aHasFinding !== $bHasFinding) {
                    return $bHasFinding <=> $aHasFinding;
                }

                $aReg = (int) $a->is_registered_customer;
                $bReg = (int) $b->is_registered_customer;
                if ($aReg !== $bReg) {
                    return $bReg <=> $aReg;
                }

                $cmpDate = strcmp((string) $a->first_valid_order_at, (string) $b->first_valid_order_at);
                if ($cmpDate !== 0) {
                    return $cmpDate;
                }

                return strcmp($a->customer_identity_hash, $b->customer_identity_hash);
            });

            $primaryHash = $buckets[0]->customer_identity_hash;

            $firstDate = null;
            $latestDate = null;
            $orderCount = 0;
            $netRevenue = 0.0;
            $isRegistered = 0;

            $bestCid = null;
            $bestEmail = null;
            $bestName = null;
            $bestCompany = null;

            // Prefer registered/most-recent bucket attributes
            $attrBuckets = $buckets;
            usort($attrBuckets, function ($a, $b) {
                $regCmp = ((int) $b->is_registered_customer) <=> ((int) $a->is_registered_customer);
                if ($regCmp !== 0) {
                    return $regCmp;
                }
                return strcmp((string) $b->latest_valid_order_at, (string) $a->latest_valid_order_at);
            });

            foreach ($attrBuckets as $b) {
                if (empty($bestCid) && ! empty($b->customer_id)) {
                    $bestCid = $b->customer_id;
                }
                if (empty($bestEmail) && ! empty($b->customer_email)) {
                    $bestEmail = $b->customer_email;
                }
                if (empty($bestName) && ! empty($b->customer_name)) {
                    $bestName = $b->customer_name;
                }
                if (empty($bestCompany) && ! empty($b->company_name)) {
                    $bestCompany = $b->company_name;
                }
            }

            foreach ($buckets as $b) {
                $orderCount += (int) $b->lifetime_valid_order_count;
                $netRevenue += (float) $b->lifetime_net_revenue;
                $isRegistered = max($isRegistered, (int) $b->is_registered_customer);

                if ($firstDate === null || strcmp((string) $b->first_valid_order_at, $firstDate) < 0) {
                    $firstDate = (string) $b->first_valid_order_at;
                }
                if ($latestDate === null || strcmp((string) $b->latest_valid_order_at, $latestDate) > 0) {
                    $latestDate = (string) $b->latest_valid_order_at;
                }
            }

            $result[] = (object) [
                'client_id'                  => $clientId,
                'customer_identity_hash'     => $primaryHash,
                'canonical_account_id'       => $canonicalId,
                'customer_id'                => $bestCid,
                'customer_email'             => $bestEmail,
                'customer_name'              => $bestName,
                'company_name'               => $bestCompany,
                'first_valid_order_at'       => $firstDate,
                'latest_valid_order_at'      => $latestDate,
                'lifetime_valid_order_count' => $orderCount,
                'lifetime_net_revenue'       => round($netRevenue, 2),
                'is_registered_customer'     => $isRegistered,
            ];
        }

        return collect($result);
    }

    protected function archiveAndDeleteFacts(Collection $facts, string $nowStr, callable $reasonResolver): void
    {
        $archiveRecords = [];
        $factIds = [];

        foreach ($facts as $fact) {
            $factIds[] = $fact->id;
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
                'archive_reason'             => $reasonResolver($fact),
                'archived_at'                => $nowStr,
                'created_at'                 => $nowStr,
                'updated_at'                 => $nowStr,
            ];
        }

        foreach (array_chunk($archiveRecords, 500) as $chunk) {
            DB::table('commerce_customer_purchase_facts_archive')->insert($chunk);
        }

        DB::table('commerce_customer_purchase_facts')
            ->whereIn('id', $factIds)
            ->delete();
    }

    protected function upsertConsolidatedFacts(
        int $clientId,
        Collection $consolidated,
        Collection $existingByHash,
        string $nowStr
    ): void {
        $factUpsertBatch = [];

        foreach ($consolidated as $row) {
            $existing = $existingByHash->get($row->customer_identity_hash);
            $factUpsertBatch[] = [
                'client_id'                  => $clientId,
                'customer_identity_hash'     => $row->customer_identity_hash,
                'canonical_account_id'       => $row->canonical_account_id ?? $existing?->canonical_account_id,
                'customer_id'                => $row->customer_id ?: ($existing?->customer_id ?: null),
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
                    'canonical_account_id',
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
    }
}
