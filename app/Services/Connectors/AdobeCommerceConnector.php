<?php

namespace App\Services\Connectors;

use App\Enums\SyncStatus;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceMetric;
use App\Models\CommerceOrder;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\Metrics\ValidOrderFilter;
use App\Services\Security\CustomerIdentityHasher;
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
 */
class AdobeCommerceConnector
{
    private ValidOrderFilter $orderFilter;
    private CustomerIdentityHasher $identityHasher;

    public function __construct(private readonly Integration $integration)
    {
        $this->orderFilter    = new ValidOrderFilter();
        $this->identityHasher = new CustomerIdentityHasher();
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
            $token   = $this->getAdminToken($creds);
            $baseUrl = rtrim($creds['base_url'], '/');
            $from    = now()->subDays($numOfDays)->startOfDay()->toIso8601String();
            $to      = now()->endOfDay()->toIso8601String();

            // Fetch orders for the period
            $orders = $this->fetchOrders($baseUrl, $token, $from, $to);

            // Persist order line items & facts
            $this->storeOrdersAndFacts($orders);

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
     * Persist order records into commerce_orders and update customer purchase facts.
     */
    private function storeOrdersAndFacts(array $orders): void
    {
        $client = $this->integration->client;

        foreach ($orders as $order) {
            $entityId   = (string) ($order['entity_id'] ?? $order['increment_id'] ?? '');
            if (! $entityId) continue;

            $status     = strtolower($order['status'] ?? 'unknown');
            $grandTotal = (float) ($order['grand_total'] ?? 0);
            $totalRef   = (float) ($order['total_refunded'] ?? 0);
            $tax        = (float) ($order['tax_amount'] ?? 0);
            $shipping   = (float) ($order['shipping_amount'] ?? 0);
            $discount   = (float) (abs($order['discount_amount'] ?? 0));
            $createdAt  = Carbon::parse($order['created_at'] ?? now());
            $custEmail  = $order['customer_email'] ?? null;
            $custId     = isset($order['customer_id']) ? (string) $order['customer_id'] : null;

            $identityHash = $this->identityHasher->hashCustomerIdentity($client, $custId, $custEmail);

            $isFullyRefunded = ($grandTotal > 0 && $totalRef >= $grandTotal);
            $orderPayload = [
                'status'            => $status,
                'is_fully_refunded' => $isFullyRefunded,
            ];

            $isValid = $this->orderFilter->isValidOrder($orderPayload, $this->integration);
            $exclusionReason = $this->orderFilter->getExclusionReason($orderPayload, $this->integration);

            $netRevenue = $isValid ? max(0.0, $grandTotal - $totalRef) : 0.0;

            CommerceOrder::updateOrCreate(
                [
                    'client_id'          => $this->integration->client_id,
                    'integration_id'     => $this->integration->id,
                    'source'             => 'adobe_commerce',
                    'source_order_id'    => $entityId,
                ],
                [
                    'source_increment_id'     => $order['increment_id'] ?? null,
                    'order_status'            => $status,
                    'customer_identity_hash'  => $identityHash,
                    'registered_customer_id'  => $custId,
                    'order_date'              => $createdAt,
                    'refund_date'             => $totalRef > 0 ? now() : null,
                    'gross_revenue'           => $grandTotal,
                    'refunded_revenue'        => $totalRef,
                    'net_revenue'             => $netRevenue,
                    'tax_amount'              => $tax,
                    'shipping_amount'         => $shipping,
                    'discount_amount'         => $discount,
                    'currency'                => $order['order_currency_code'] ?? 'USD',
                    'base_currency'           => $order['base_currency_code'] ?? 'USD',
                    'reporting_currency'      => $client->currency ?? 'USD',
                    'exchange_rate'           => (float) ($order['store_to_base_rate'] ?? 1.0),
                    'is_valid'                => $isValid,
                    'exclusion_reason'        => $exclusionReason,
                    'source_updated_at'       => isset($order['updated_at']) ? Carbon::parse($order['updated_at']) : null,
                    'financial_last_changed_at' => now(),
                    'collected_at'            => now(),
                    'metadata_json'           => [
                        'customer_group_id' => $order['customer_group_id'] ?? null,
                    ],
                ]
            );

            // Update customer purchase facts for valid orders
            if ($isValid && $identityHash) {
                $fact = CommerceCustomerPurchaseFact::firstOrNew([
                    'client_id'              => $client->id,
                    'customer_identity_hash' => $identityHash,
                ]);

                if (! $fact->exists) {
                    $fact->customer_id                = $custId;
                    $fact->first_valid_order_at       = $createdAt;
                    $fact->latest_valid_order_at      = $createdAt;
                    $fact->lifetime_valid_order_count = 1;
                    $fact->lifetime_net_revenue       = $netRevenue;
                    $fact->is_registered_customer     = ($custId !== null);
                } else {
                    if ($createdAt->lt($fact->first_valid_order_at)) {
                        $fact->first_valid_order_at = $createdAt;
                    }
                    if ($createdAt->gt($fact->latest_valid_order_at)) {
                        $fact->latest_valid_order_at = $createdAt;
                    }
                    $fact->lifetime_valid_order_count += 1;
                    $fact->lifetime_net_revenue       += $netRevenue;
                }

                $fact->refreshed_at = now();
                $fact->save();
            }
        }
    }

    /**
     * Group orders by date and aggregate into commerce_metrics columns.
     */
    private function aggregateByDay(array $orders): array
    {
        $daily = [];

        foreach ($orders as $order) {
            $date = substr($order['created_at'] ?? '', 0, 10); // YYYY-MM-DD

            if (! $date) continue;

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
            $totalRef   = (float) ($order['total_refunded'] ?? 0);
            $qty        = (int) ($order['total_qty_ordered'] ?? 0);
            $status     = strtolower($order['status'] ?? 'unknown');
            $email      = $order['customer_email'] ?? '';
            $isGuest    = (bool) ($order['customer_is_guest'] ?? false);

            $orderPayload = [
                'status'            => $status,
                'is_fully_refunded' => ($grandTotal > 0 && $totalRef >= $grandTotal),
            ];

            $isValid = $this->orderFilter->isValidOrder($orderPayload, $this->integration);

            if ($isValid) {
                $daily[$date]['revenue']    += max(0.0, $grandTotal - $totalRef);
                $daily[$date]['items_sold'] += $qty;
                $daily[$date]['orders']++;
            }

            if ($email && ! in_array($email, $daily[$date]['_emails'])) {
                $daily[$date]['_emails'][] = $email;
                if ($isGuest) {
                    $daily[$date]['new_customers']++;
                } else {
                    $daily[$date]['returning_customers']++;
                }
            }

            $daily[$date]['metadata_json']['statuses'][$status] =
                ($daily[$date]['metadata_json']['statuses'][$status] ?? 0) + 1;
        }

        // Clean internal keys and calculate daily AOV
        foreach ($daily as $date => &$row) {
            unset($row['_emails']);
            $row['aov'] = $row['orders'] > 0 ? round($row['revenue'] / $row['orders'], 2) : 0.0;
        }

        return $daily;
    }

    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        $creds = $this->integration->credentials_json ?? [];

        if (! $this->hasRequiredCredentials($creds)) return 0;

        try {
            $token   = $this->getAdminToken($creds);
            $baseUrl = rtrim($creds['base_url'], '/');

            $orders = $this->fetchOrders($baseUrl, $token, $from->toIso8601String(), $to->toIso8601String());

            $this->storeOrdersAndFacts($orders);

            $dailyMetrics = $this->aggregateByDay($orders);

            foreach ($dailyMetrics as $date => $metrics) {
                CommerceMetric::updateOrCreate(
                    [
                        'client_id' => $this->integration->client_id,
                        'date'      => $date,
                        'source'    => 'adobe_commerce',
                    ],
                    $metrics
                );
            }

            return count($orders);

        } catch (\Exception $e) {
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

            $orders = $this->fetchOrders($baseUrl, $token, $from, $to, limit: 5);

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
                    $response = Http::timeout(30)
                        ->withHeaders([
                            'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Content-Type' => 'application/json',
                            'Accept'       => 'application/json',
                        ])
                        ->post($endpoint, [
                            'username' => $creds['admin_username'] ?? '',
                            'password' => $creds['admin_password'] ?? '',
                        ]);

                    if ($response->successful()) {
                        $token = trim($response->body(), " \t\n\r\0\x0B\"");
                        if (! empty($token)) {
                            return $token;
                        }
                    }

                    $status  = $response->status();
                    $jsonMsg = $response->json('message');
                    $detail  = is_string($jsonMsg) ? $jsonMsg : $response->body();

                    if ($status === 403 || $status === 401) {
                        $lastError = "Adobe Commerce authentication failed (HTTP {$status}). {$detail}";
                    } else {
                        $lastError = "Adobe Commerce token error (HTTP {$status}): {$detail}";
                    }
                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                }
            }

            throw new \RuntimeException($lastError ?? 'Adobe Commerce authentication failed. Check credentials.');
        });
    }

    private function fetchOrders(string $baseUrl, string $token, string $from, string $to, int $limit = 100, bool $isRetry = false): array
    {
        $allOrders = [];
        $page      = 1;

        $endpoints = [
            $baseUrl . '/rest/V1/orders',
            $baseUrl . '/rest/all/V1/orders',
            $baseUrl . '/rest/default/V1/orders',
        ];

        do {
            $queryParams = [
                'searchCriteria[filter_groups][0][filters][0][field]'        => 'created_at',
                'searchCriteria[filter_groups][0][filters][0][value]'        => $from,
                'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'gteq',
                'searchCriteria[filter_groups][1][filters][0][field]'        => 'created_at',
                'searchCriteria[filter_groups][1][filters][0][value]'        => $to,
                'searchCriteria[filter_groups][1][filters][0][condition_type]' => 'lteq',
                'searchCriteria[pageSize]'                                   => $limit,
                'searchCriteria[currentPage]'                                => $page,
            ];

            $response = null;
            $lastException = null;

            foreach ($endpoints as $endpointUrl) {
                $url = $endpointUrl . '?' . http_build_query($queryParams);

                $resp = Http::timeout(30)
                    ->withHeaders([
                        'Authorization' => "Bearer {$token}",
                        'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept'        => 'application/json',
                    ])
                    ->get($url);

                if ($resp->successful()) {
                    $response = $resp;
                    break;
                }

                $status = $resp->status();
                $body   = $resp->body();

                if ($status === 401 && ! $isRetry) {
                    Log::warning('AdobeCommerceConnector: 401 token expired, clearing token cache and retrying');
                    $creds    = $this->integration->credentials_json ?? [];
                    $newToken = $this->getAdminToken($creds, forceRefresh: true);
                    return $this->fetchOrders($baseUrl, $newToken, $from, $to, $limit, isRetry: true);
                }

                $jsonMsg = $resp->json('message');
                $errMsg  = is_string($jsonMsg) ? $jsonMsg : (strlen($body) < 200 ? $body : "HTTP {$status}");
                $lastException = new \RuntimeException("Adobe Commerce API error (HTTP {$status}): {$errMsg}");
            }

            if (! $response || ! $response->successful()) {
                throw $lastException ?? new \RuntimeException('Adobe Commerce API request failed.');
            }

            $data       = $response->json();
            $items      = $data['items'] ?? [];
            $totalCount = $data['total_count'] ?? 0;

            $allOrders = array_merge($allOrders, $items);
            $page++;

        } while (count($allOrders) < $totalCount && ! empty($items));

        return $allOrders;
    }

    private function hasRequiredCredentials(array $creds): bool
    {
        if (empty($creds['base_url'])) {
            return false;
        }

        $hasToken    = ! empty($creds['access_token']) || ! empty($creds['bearer_token']);
        $hasUserPass = ! empty($creds['admin_username']) && ! empty($creds['admin_password']);

        return $hasToken || $hasUserPass;
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
