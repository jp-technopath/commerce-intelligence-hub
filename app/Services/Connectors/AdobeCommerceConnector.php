<?php

namespace App\Services\Connectors;

use App\Enums\SyncStatus;
use App\Models\CommerceMetric;
use App\Models\Integration;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AdobeCommerceConnector
 *
 * Authenticates via the Magento 2 Admin REST API token endpoint:
 *   POST /rest/V1/integration/admin/token
 *
 * Then uses that token to pull order/revenue data from:
 *   GET /rest/V1/orders (with filters for date range)
 *
 * Credentials stored in credentials_json:
 *   - base_url       (e.g. https://your-store.com)
 *   - admin_username
 *   - admin_password
 */
class AdobeCommerceConnector
{
    public function __construct(private readonly Integration $integration) {}

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
            $token   = $this->getAdminToken($creds);
            $baseUrl = rtrim($creds['base_url'], '/');
            $from    = now()->subDays($numOfDays)->startOfDay()->toIso8601String();
            $to      = now()->endOfDay()->toIso8601String();

            // Fetch orders for the period
            $orders = $this->fetchOrders($baseUrl, $token, $from, $to);

            // Aggregate daily metrics
            $dailyMetrics = $this->aggregateByDay($orders);

            $totalRecords = 0;
            foreach ($dailyMetrics as $date => $metrics) {
                CommerceMetric::updateOrCreate(
                    [
                        'client_id' => $this->integration->client_id,
                        'date'      => $date,
                        'source'    => 'adobe_commerce',
                    ],
                    $metrics
                );
                $totalRecords++;
            }

            $syncLog->update([
                'status'            => SyncStatus::Success,
                'records_processed' => $totalRecords,
                'completed_at'      => now(),
                'metadata_json'     => [
                    'orders_fetched' => count($orders),
                    'days_processed' => $totalRecords,
                    'date_range'     => ['from' => $from, 'to' => $to],
                ],
            ]);

            Log::info('AdobeCommerceConnector: sync complete', [
                'integration_id' => $this->integration->id,
                'orders'         => count($orders),
                'days'           => $totalRecords,
            ]);

        } catch (\Exception $e) {
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

    public function testConnection(): array
    {
        $creds = $this->integration->credentials_json ?? [];

        if (! $this->hasRequiredCredentials($creds)) {
            return ['success' => false, 'message' => 'Missing base URL, admin username, or admin password.'];
        }

        try {
            $token   = $this->getAdminToken($creds);
            $baseUrl = rtrim($creds['base_url'], '/');

            // Quick health check — fetch store info
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$baseUrl}/rest/V1/store/storeConfigs");

            if ($response->successful()) {
                $stores = $response->json();
                $name   = $stores[0]['base_url'] ?? $baseUrl;
                return ['success' => true, 'message' => "Connected — Authenticated to {$name}"];
            }

            return ['success' => false, 'message' => "Authenticated but store API returned HTTP {$response->status()}."];

        } catch (\Exception $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, '401') || str_contains($msg, 'credentials')) {
                return ['success' => false, 'message' => 'Authentication failed. Check admin username and password.'];
            }

            return [
                'success' => false,
                'message' => 'Connection error: ' . $this->sanitiseError($msg, $creds),
            ];
        }
    }

    /**
     * Fetch and store commerce metrics for an explicit date range.
     *
     * Unlike sync(), this method does NOT create or update a SyncLog.
     * Returns the total number of daily records stored, or 0 on failure.
     */
    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        $creds = $this->integration->credentials_json ?? [];

        if (! $this->hasRequiredCredentials($creds)) {
            return 0;
        }

        try {
            $token   = $this->getAdminToken($creds);
            $baseUrl = rtrim($creds['base_url'], '/');

            $orders = $this->fetchOrders(
                $baseUrl,
                $token,
                $from->toIso8601String(),
                $to->toIso8601String()
            );

            $dailyMetrics = $this->aggregateByDay($orders);

            $totalRecords = 0;
            foreach ($dailyMetrics as $date => $metrics) {
                CommerceMetric::updateOrCreate(
                    [
                        'client_id' => $this->integration->client_id,
                        'date'      => $date,
                        'source'    => 'adobe_commerce',
                    ],
                    $metrics
                );
                $totalRecords++;
            }

            return $totalRecords;

        } catch (\Exception $e) {
            Log::error('AdobeCommerceConnector: fetchForDateRange error', [
                'integration_id' => $this->integration->id,
                'message'        => $this->sanitiseError($e->getMessage(), $creds),
            ]);

            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Authentication
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /rest/V1/integration/admin/token
     * Returns a bearer token string valid for ~4 hours.
     * Tokens are cached for 3 hours to avoid unnecessary re-auth.
     */
    private function getAdminToken(array $creds): string
    {
        $cacheKey = 'adobe_token:' . $this->integration->id;

        return Cache::remember($cacheKey, now()->addHours(3), function () use ($creds) {
            $baseUrl  = rtrim($creds['base_url'], '/');
            $response = Http::timeout(15)
                ->post("{$baseUrl}/rest/V1/integration/admin/token", [
                    'username' => $creds['admin_username'],
                    'password' => $creds['admin_password'],
                ]);

            if (! $response->successful()) {
                $body = $response->json('message') ?? $response->body();
                throw new \RuntimeException(
                    "Adobe Commerce auth failed (HTTP {$response->status()}): {$body}"
                );
            }

            // Response is a quoted string token
            $token = trim($response->body(), '"');

            if (empty($token)) {
                throw new \RuntimeException('Adobe Commerce returned empty token.');
            }

            return $token;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Data fetching
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /rest/V1/orders with date range filter.
     * Uses searchCriteria to filter by created_at.
     */
    private function fetchOrders(string $baseUrl, string $token, string $from, string $to): array
    {
        $allOrders  = [];
        $page       = 1;
        $pageSize   = 100;

        do {
            $response = Http::withToken($token)
                ->timeout(30)
                ->get("{$baseUrl}/rest/V1/orders", [
                    'searchCriteria[filter_groups][0][filters][0][field]'          => 'created_at',
                    'searchCriteria[filter_groups][0][filters][0][value]'          => $from,
                    'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'gteq',
                    'searchCriteria[filter_groups][1][filters][0][field]'          => 'created_at',
                    'searchCriteria[filter_groups][1][filters][0][value]'          => $to,
                    'searchCriteria[filter_groups][1][filters][0][condition_type]' => 'lteq',
                    'searchCriteria[pageSize]'    => $pageSize,
                    'searchCriteria[currentPage]' => $page,
                    'fields' => 'items[entity_id,created_at,grand_total,total_qty_ordered,status,customer_is_guest,customer_email,base_currency_code],total_count',
                ]);

            $response->throw();

            $data       = $response->json();
            $items      = $data['items'] ?? [];
            $totalCount = $data['total_count'] ?? 0;

            $allOrders = array_merge($allOrders, $items);
            $page++;

        } while (count($allOrders) < $totalCount && ! empty($items));

        return $allOrders;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Aggregation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Group orders by date and aggregate into commerce_metrics columns.
     */
    private function aggregateByDay(array $orders): array
    {
        $daily = [];

        foreach ($orders as $order) {
            $date = substr($order['created_at'] ?? '', 0, 10); // YYYY-MM-DD

            if (! $date) {
                continue;
            }

            if (! isset($daily[$date])) {
                $daily[$date] = [
                    'revenue'             => 0.0,
                    'orders'              => 0,
                    'items_sold'          => 0,
                    'new_customers'       => 0,
                    'returning_customers' => 0,
                    'sessions'            => 0,
                    'conversion_rate'     => 0.0,
                    'aov'                 => 0.0,
                    'metadata_json'       => ['statuses' => []],
                    '_emails'             => [],
                ];
            }

            $grandTotal = (float) ($order['grand_total'] ?? 0);
            $qty        = (int) ($order['total_qty_ordered'] ?? 0);
            $status     = $order['status'] ?? 'unknown';
            $email      = $order['customer_email'] ?? '';
            $isGuest    = (bool) ($order['customer_is_guest'] ?? false);

            // Skip cancelled/closed orders for revenue
            if (! in_array($status, ['canceled', 'closed', 'holded'])) {
                $daily[$date]['revenue'] += $grandTotal;
                $daily[$date]['items_sold'] += $qty;
            }

            $daily[$date]['orders']++;

            // Track unique emails for new vs returning
            if ($email && ! in_array($email, $daily[$date]['_emails'])) {
                $daily[$date]['_emails'][] = $email;
                if ($isGuest) {
                    $daily[$date]['new_customers']++;
                } else {
                    $daily[$date]['returning_customers']++;
                }
            }

            // Count order statuses
            $daily[$date]['metadata_json']['statuses'][$status] =
                ($daily[$date]['metadata_json']['statuses'][$status] ?? 0) + 1;
        }

        // Compute AOV and clean up
        foreach ($daily as $date => &$metrics) {
            $validOrders = $metrics['orders'] - ($metrics['metadata_json']['statuses']['canceled'] ?? 0);
            $metrics['aov'] = $validOrders > 0
                ? round($metrics['revenue'] / $validOrders, 2)
                : 0.0;

            unset($metrics['_emails']);
        }

        return $daily;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function hasRequiredCredentials(array $creds): bool
    {
        return ! empty($creds['base_url'])
            && ! empty($creds['admin_username'])
            && ! empty($creds['admin_password']);
    }

    private function sanitiseError(string $message, array $creds): string
    {
        foreach (['admin_password', 'admin_username'] as $key) {
            $val = $creds[$key] ?? '';
            if (strlen($val) > 4) {
                $masked  = substr($val, 0, 2) . str_repeat('•', strlen($val) - 4) . substr($val, -2);
                $message = str_replace($val, $masked, $message);
            }
        }
        return $message;
    }
}
