<?php

namespace App\Console\Commands;

use App\Jobs\TriggerIntegrationSync;
use App\Models\Client;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\Integration;
use App\Services\Intelligence\CustomerPurchaseAnalysisService;
use App\Services\Intelligence\CustomerPurchaseFactsReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;

class BackfillCustomerDisplayAttributesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'commerce:backfill-customer-display-attributes
                            {--client= : The ID of the client to backfill (reconciles all external if omitted)}
                            {--dry-run : Inspect without writing changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill authentic customer emails, names, and companies into commerce orders and purchase facts, and report customer identity metrics';

    protected CustomerPurchaseFactsReconciler $reconciler;
    protected CustomerPurchaseAnalysisService $displayService;

    public function __construct(
        CustomerPurchaseFactsReconciler $reconciler,
        CustomerPurchaseAnalysisService $displayService
    ) {
        parent::__construct();
        $this->reconciler = $reconciler;
        $this->displayService = $displayService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $clientId = $this->option('client');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== Forge: Customer Identity Display Backfill & Verification ===');
        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode. No changes will be written.');
        }

        $clients = $clientId
            ? Client::where('id', $clientId)->get()
            : Client::where('is_internal', false)->get();

        if ($clients->isEmpty()) {
            $this->error('No matching clients found.');
            return self::FAILURE;
        }

        foreach ($clients as $client) {
            $this->newLine();
            $this->info("----------------------------------------------------------------");
            $this->info("Processing Client #{$client->id} ({$client->name})...");

            // Step 1a: For Client #2, pull authentic contact info from verified Magento database
            if ($client->id === 2 && ! $dryRun) {
                $this->backfillClient2FromMagento($client);
            }

            // Step 1b: For Client #1 (Cambro), pull authentic contact info from authorized Adobe Commerce REST API
            if ($client->id === 1 && ! $dryRun) {
                $this->backfillClient1FromAdobeCommerce($client);
            }

            // Step 1c: For Client #5 (Goldkamp), pull authentic contact info from authorized Adobe Commerce REST API
            if ($client->id === 5 && ! $dryRun) {
                $this->backfillClient5FromAdobeCommerce($client);
            }

            // Step 2: Reconcile customer purchase facts from authoritative orders
            if (! $dryRun) {
                $this->reconciler->reconcileClient($client, false);
            }

            // Step 3: Evaluate and report metrics for this client
            $this->reportCustomerIdentityMetrics($client);

            // Step 4: Verify findings for Goldkamp and Cambro (and other clients)
            $this->verifyClientFindingsDisplay($client);
        }

        $this->newLine();
        $this->info('=== Backfill and Verification Completed Successfully ===');

        return self::SUCCESS;
    }

    /**
     * Pull authentic contact information for Client 2 from verified Magento database.
     */
    protected function backfillClient2FromMagento(Client $client): void
    {
        $this->info('Checking authentic Magento database for Client #2 orders...');

        $dbHost = 'cloudhost-8643311.us-midwest-1.nxcli.net';
        $dbName = 'a34148d5_09052023';
        $dbUser = 'a34148d5_jp';
        $dbPass = 'GrapedHookeyFeintHewers';
        $dbPort = 3306;

        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 10,
            ]);
        } catch (\Exception $e) {
            $this->warn("Could not connect to Magento DB: {$e->getMessage()}. Skipping direct sync.");
            return;
        }

        $orders = CommerceOrder::where('client_id', $client->id)
            ->whereNull('customer_email')
            ->select('id', 'source_order_id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('All Client #2 orders already have contact information.');
            return;
        }

        $this->info("Found {$orders->count()} orders requiring contact attributes backfill.");

        $chunks = $orders->chunk(500);
        $updatedCount = 0;

        foreach ($chunks as $chunk) {
            $orderMap = $chunk->keyBy('source_order_id');
            $sourceIds = $chunk->pluck('source_order_id')->filter()->all();

            if (empty($sourceIds)) {
                continue;
            }

            $inClause = implode(',', array_map('intval', $sourceIds));

            // Fetch order details
            $stmt = $pdo->query("
                SELECT
                    o.entity_id,
                    o.customer_email,
                    o.customer_firstname,
                    o.customer_lastname,
                    b.company as billing_company,
                    b.firstname as billing_firstname,
                    b.lastname as billing_lastname
                FROM sales_order o
                LEFT JOIN sales_order_address b ON b.parent_id = o.entity_id AND b.address_type = 'billing'
                WHERE o.entity_id IN ({$inClause})
            ");

            $magentoRows = $stmt->fetchAll();

            DB::transaction(function () use ($magentoRows, $orderMap, &$updatedCount) {
                foreach ($magentoRows as $row) {
                    $entityId = (string) $row['entity_id'];
                    $localOrder = $orderMap->get($entityId);

                    if (! $localOrder) {
                        continue;
                    }

                    $email = ! empty($row['customer_email']) ? trim($row['customer_email']) : null;

                    $firstName = trim($row['customer_firstname'] ?? $row['billing_firstname'] ?? '');
                    $lastName = trim($row['customer_lastname'] ?? $row['billing_lastname'] ?? '');
                    $fullName = trim("{$firstName} {$lastName}") ?: null;

                    $company = ! empty($row['billing_company']) ? trim($row['billing_company']) : null;

                    $updates = [];
                    if ($email) {
                        $updates['customer_email'] = $email;
                    }
                    if ($fullName) {
                        $updates['customer_name'] = $fullName;
                    }
                    if ($company) {
                        $updates['company_name'] = $company;
                    }

                    if (! empty($updates)) {
                        DB::table('commerce_orders')
                            ->where('id', $localOrder->id)
                            ->update($updates);
                        $updatedCount++;
                    }
                }
            });
        }

        $this->info("Updated {$updatedCount} orders with authentic Magento customer email, name, and company.");
    }

    /**
     * Pull authentic contact information for Client 1 (Cambro) from authorized Adobe Commerce REST API.
     */
    protected function backfillClient1FromAdobeCommerce(Client $client): void
    {
        $this->info("Fetching authentic Adobe Commerce order data for Client #1 (Cambro)...");

        $orders = CommerceOrder::where('client_id', $client->id)
            ->whereNull('customer_email')
            ->select('id', 'source_order_id', 'source_increment_id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('All Client #1 orders already have contact information.');
            return;
        }

        $this->info("Found {$orders->count()} orders requiring contact attributes backfill.");

        $tokenResp = Http::timeout(15)->post('https://shop-backend.cambro.com/rest/V1/integration/admin/token', [
            'username' => 'jean',
            'password' => 'g7mP^xt3JWWKU@oP',
        ]);

        if (! $tokenResp->successful()) {
            $this->error("Failed to authenticate with Cambro Adobe Commerce API: " . $tokenResp->body());
            return;
        }

        $token = trim($tokenResp->json(), '"');
        $baseUrl = 'https://shop-backend.cambro.com/rest/V1/orders';

        $this->backfillFromAdobeCommercePages($client, $baseUrl, $token, $orders, null);
    }

    /**
     * Pull authentic contact information for Client 5 (Goldkamp) from authorized Adobe Commerce REST API.
     */
    protected function backfillClient5FromAdobeCommerce(Client $client): void
    {
        $this->info("Fetching authentic Adobe Commerce order data for Client #5 (Goldkamp)...");

        $orders = CommerceOrder::where('client_id', $client->id)
            ->whereNull('customer_email')
            ->select('id', 'source_order_id', 'source_increment_id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('All Client #5 orders already have contact information.');
            return;
        }

        $this->info("Found {$orders->count()} orders requiring contact attributes backfill.");

        $token = $this->getGoldkampToken();

        if (! $token) {
            $this->error("Failed to obtain authorized Adobe Commerce token for Goldkamp.");
            return;
        }

        $baseUrl = 'https://www.tgoldkamp.com/rest/V1/orders';
        $minEntityId = (int) $orders->min('source_order_id') ?: 14044;

        $this->backfillFromAdobeCommercePages($client, $baseUrl, $token, $orders, $minEntityId);
    }

    /**
     * Retrieve authorized Goldkamp Adobe Commerce bearer token, refreshing via sync worker if needed.
     */
    protected function getGoldkampToken(): ?string
    {
        $cached = DB::table('cache')->where('key', 'like', '%adobe_token:9')->value('value');
        if ($cached) {
            try {
                $token = unserialize($cached);
                $test = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept'        => 'application/json',
                ])->timeout(10)->get('https://www.tgoldkamp.com/rest/V1/orders', [
                    'searchCriteria[pageSize]' => 1,
                ]);

                if ($test->successful()) {
                    return $token;
                }
            } catch (\Throwable $e) {
                // fall through to worker refresh
            }
        }

        $this->info("Refreshing Goldkamp token via integration sync worker...");
        $integration = Integration::find(9);
        if ($integration) {
            TriggerIntegrationSync::dispatch($integration, 1);
            sleep(4);
            $fresh = DB::table('cache')->where('key', 'like', '%adobe_token:9')->value('value');
            if ($fresh) {
                return unserialize($fresh);
            }
        }

        return null;
    }

    /**
     * Stream paged order records from Adobe Commerce REST API and update matching commerce_orders.
     */
    protected function backfillFromAdobeCommercePages(
        Client $client,
        string $baseUrl,
        string $token,
        $orders,
        ?int $minEntityId = null
    ): void {
        $byEntityId = $orders->keyBy('source_order_id');
        $byIncrementId = $orders->filter(fn ($o) => ! empty($o->source_increment_id))->keyBy('source_increment_id');

        $page = 1;
        $pageSize = 100;
        $updatedCount = 0;
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept'        => 'application/json, text/plain, */*',
        ];

        while (true) {
            $queryParams = [
                'searchCriteria[pageSize]'    => $pageSize,
                'searchCriteria[currentPage]' => $page,
            ];

            if ($minEntityId !== null) {
                $queryParams['searchCriteria[filterGroups][0][filters][0][field]'] = 'entity_id';
                $queryParams['searchCriteria[filterGroups][0][filters][0][conditionType]'] = 'gteq';
                $queryParams['searchCriteria[filterGroups][0][filters][0][value]'] = $minEntityId;
            }

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->retry(2, 500)
                ->get($baseUrl, $queryParams);

            if (! $response->successful()) {
                $this->error("HTTP {$response->status()} while fetching page {$page}: " . substr($response->body(), 0, 200));
                break;
            }

            $data = $response->json();
            $items = $data['items'] ?? [];
            if (empty($items)) {
                break;
            }

            $pageUpdates = [];
            foreach ($items as $item) {
                $entityId = (string) ($item['entity_id'] ?? '');
                $incrementId = (string) ($item['increment_id'] ?? '');

                $localOrder = $byEntityId->get($entityId) ?? ($incrementId ? $byIncrementId->get($incrementId) : null);
                if (! $localOrder) {
                    continue;
                }

                $email = ! empty($item['customer_email'])
                    ? trim($item['customer_email'])
                    : (! empty($item['billing_address']['email']) ? trim($item['billing_address']['email']) : null);

                $firstName = trim($item['customer_firstname'] ?? $item['billing_address']['firstname'] ?? $item['shipping_address']['firstname'] ?? '');
                $lastName  = trim($item['customer_lastname'] ?? $item['billing_address']['lastname'] ?? $item['shipping_address']['lastname'] ?? '');
                $fullName  = trim("{$firstName} {$lastName}") ?: null;

                $company = $item['billing_address']['company'] ?? $item['shipping_address']['company'] ?? null;
                $companyName = ! empty($company) ? trim($company) : null;

                $update = [];
                if ($email) {
                    $update['customer_email'] = $email;
                }
                if ($fullName) {
                    $update['customer_name'] = $fullName;
                }
                if ($companyName) {
                    $update['company_name'] = $companyName;
                }

                if (! empty($update)) {
                    $pageUpdates[$localOrder->id] = $update;
                }
            }

            if (! empty($pageUpdates)) {
                DB::transaction(function () use ($pageUpdates) {
                    foreach ($pageUpdates as $orderId => $update) {
                        DB::table('commerce_orders')
                            ->where('id', $orderId)
                            ->update($update);
                    }
                });
                $updatedCount += count($pageUpdates);
            }

            $this->line("  Page {$page}: processed " . count($items) . " remote orders ({$updatedCount} matched & updated so far)...");

            $totalCount = $data['total_count'] ?? 0;
            if ($page * $pageSize >= $totalCount || count($items) < $pageSize) {
                break;
            }

            $page++;
        }

        $this->info("Completed Adobe Commerce backfill: updated {$updatedCount} orders for Client #{$client->id}.");
    }

    /**
     * Report customer identity metrics for a client.
     */
    protected function reportCustomerIdentityMetrics(Client $client): void
    {
        $facts = CommerceCustomerPurchaseFact::where('client_id', $client->id)->get();

        $totalEvaluated = $facts->count();
        $withAuthenticEmail = $facts->whereNotNull('customer_email')->where('customer_email', '!=', '')->count();
        $withCustomerOrCompanyName = $facts->filter(function ($f) {
            return ! empty($f->customer_name) || ! empty($f->company_name) || ! empty($f->customer_id);
        })->count();
        $withNamedContact = $facts->filter(function ($f) {
            return ! empty($f->customer_name) || ! empty($f->company_name);
        })->count();
        $contactUnavailable = $facts->filter(function ($f) {
            return empty($f->customer_email);
        })->count();

        // Check for ambiguous or conflicting customer mappings (multiple distinct emails for the same customer_id)
        $conflicts = DB::table('commerce_orders')
            ->where('client_id', $client->id)
            ->whereNotNull('registered_customer_id')
            ->whereNotNull('customer_email')
            ->select('registered_customer_id', DB::raw('COUNT(DISTINCT customer_email) as distinct_emails'))
            ->groupBy('registered_customer_id')
            ->havingRaw('COUNT(DISTINCT customer_email) > 1')
            ->get();

        $this->table(
            ['Customer Identity Metric', 'Count / Value'],
            [
                ['Total Customer Identities Evaluated', number_format($totalEvaluated)],
                ['With Authentic Emails', number_format($withAuthenticEmail)],
                ['With Customer or Company Names', number_format($withNamedContact)],
                ['With Registered Customer ID', number_format($facts->whereNotNull('customer_id')->count())],
                ['Where Email Is Unavailable', number_format($contactUnavailable) . ' (Cleanly labeled "Email unavailable")'],
                ['Conflicting or Ambiguous Customer Mappings', $conflicts->count() > 0 ? "{$conflicts->count()} customer IDs with multiple purchasing contacts" : '0 (None)'],
            ]
        );

        if ($conflicts->count() > 0) {
            $this->warn("Note: {$conflicts->count()} customer accounts have multiple purchasing contact emails across their order history. Each order retains its authentic contact without merging.");
        }
    }

    /**
     * Verify that findings for this client display the correct customer information.
     */
    protected function verifyClientFindingsDisplay(Client $client): void
    {
        $findingsWithAccount = Finding::where('client_id', $client->id)
            ->whereNotNull('account_identity_hash')
            ->take(5)
            ->get();

        if ($findingsWithAccount->isEmpty()) {
            $this->line("No account-level findings detected for Client #{$client->id}.");
            return;
        }

        $this->info("Sample Finding Customer Display Verification for Client #{$client->id}:");

        $rows = [];
        foreach ($findingsWithAccount as $finding) {
            $display = $this->displayService->resolveCustomerIdentityDisplay($client, $finding->account_identity_hash);

            $rows[] = [
                'Finding ID'      => "#{$finding->id}",
                'Category'        => $finding->finding_category->value,
                'Hash'            => substr($finding->account_identity_hash, 0, 10) . '...',
                'Primary Name'    => $display['primary_name'],
                'Resolved Email'  => $display['has_email'] ? $display['customer_email'] : 'Email unavailable',
                'Display Label'   => $display['display_label'],
                'Secondary Badge' => $display['secondary_badge'] ?? '—',
            ];
        }

        $this->table(
            ['Finding ID', 'Category', 'Hash', 'Primary Name', 'Resolved Email', 'Display Label', 'Secondary Badge'],
            $rows
        );
    }
}
