<?php

namespace App\Services\Connectors;

use App\Enums\SyncStatus;
use App\Models\CommerceMetric;
use App\Models\CommerceOrder;
use App\Models\CustomerPurchaseFact;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shopify Connector
 *
 * Integrates with the Shopify Admin REST API to pull orders, revenue, and customer purchase facts.
 */
class ShopifyConnector
{
    private Integration $integration;
    private array $credentials;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
        $this->credentials = $integration->credentials_json ?? [];
    }

    public function testConnection(): array
    {
        $shopDomain  = $this->credentials['shop_domain'] ?? null;
        $accessToken = $this->credentials['access_token'] ?? null;

        if (! $shopDomain || ! $accessToken) {
            return [
                'success' => false,
                'message' => 'Missing shop domain or Admin Access Token.',
            ];
        }

        $shopDomain = $this->normalizeShopDomain($shopDomain);
        $apiVersion = config('services.shopify.api_version', '2025-01');

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type'           => 'application/json',
            ])
            ->timeout(15)
            ->get("https://{$shopDomain}/admin/api/{$apiVersion}/shop.json");

            if ($response->successful()) {
                $shop   = $response->json('shop') ?? [];
                $name   = $shop['name'] ?? 'Unknown Store';
                $domain = $shop['domain'] ?? $shopDomain;

                return [
                    'success' => true,
                    'message' => "Connected — {$name} ({$domain})",
                ];
            }

            $status = $response->status();

            return [
                'success' => false,
                'message' => match (true) {
                    in_array($status, [401, 403]) => 'Invalid Access Token or missing Admin API permissions.',
                    $status === 404               => 'Shop domain not found. Check the shop domain URL.',
                    default                       => "Shopify API error (HTTP {$status}).",
                },
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    public function sync(SyncLog $syncLog, int $numOfDays = 30): void
    {
        $shopDomain  = $this->credentials['shop_domain'] ?? null;
        $accessToken = $this->credentials['access_token'] ?? null;

        if (! $shopDomain || ! $accessToken) {
            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => 'Missing Shopify shop domain or access token.',
                'completed_at'  => now(),
            ]);
            return;
        }

        try {
            $from = now()->subDays($numOfDays)->startOfDay();
            $to   = now()->endOfDay();

            $orders = $this->fetchOrders($from, $to);
            $this->storeOrdersAndFacts($orders);
            $dailyMetrics = $this->aggregateByDay($orders);

            $recordsProcessed = 0;
            foreach ($dailyMetrics as $date => $metrics) {
                CommerceMetric::updateOrCreate(
                    [
                        'client_id' => $this->integration->client_id,
                        'date'      => $date,
                        'source'    => 'shopify',
                    ],
                    [
                        'revenue'             => $metrics['revenue'],
                        'orders'              => $metrics['orders'],
                        'average_order_value' => $metrics['aov'],
                        'new_customers'       => $metrics['new_customers'],
                        'returning_customers' => $metrics['returning_customers'],
                    ]
                );
                $recordsProcessed++;
            }

            $this->integration->update(['last_sync_at' => now()]);

            $syncLog->update([
                'status'            => SyncStatus::Success,
                'records_processed' => count($orders),
                'completed_at'      => now(),
            ]);

        } catch (\Exception $e) {
            Log::error('ShopifyConnector: sync error', [
                'integration_id' => $this->integration->id,
                'error'          => $e->getMessage(),
            ]);

            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
        }
    }

    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        try {
            $orders = $this->fetchOrders($from, $to);
            $this->storeOrdersAndFacts($orders);
            $dailyMetrics = $this->aggregateByDay($orders);

            foreach ($dailyMetrics as $date => $metrics) {
                CommerceMetric::updateOrCreate(
                    [
                        'client_id' => $this->integration->client_id,
                        'date'      => $date,
                        'source'    => 'shopify',
                    ],
                    [
                        'revenue'             => $metrics['revenue'],
                        'orders'              => $metrics['orders'],
                        'average_order_value' => $metrics['aov'],
                        'new_customers'       => $metrics['new_customers'],
                        'returning_customers' => $metrics['returning_customers'],
                    ]
                );
            }

            return count($orders);
        } catch (\Exception $e) {
            Log::error('ShopifyConnector::fetchForDateRange error', [
                'integration_id' => $this->integration->id,
                'error'          => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function fetchOrders(Carbon $from, Carbon $to): array
    {
        $shopDomain  = $this->normalizeShopDomain($this->credentials['shop_domain'] ?? '');
        $accessToken = $this->credentials['access_token'] ?? '';
        $apiVersion  = config('services.shopify.api_version', '2025-01');

        $allOrders = [];
        $url = "https://{$shopDomain}/admin/api/{$apiVersion}/orders.json?status=any&created_at_min={$from->toIso8601String()}&created_at_max={$to->toIso8601String()}&limit=250";

        while ($url) {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type'           => 'application/json',
            ])->timeout(30)->get($url);

            if (! $response->successful()) {
                throw new \RuntimeException("Shopify API error: " . $response->body());
            }

            $orders = $response->json('orders') ?? [];
            $allOrders = array_merge($allOrders, $orders);

            $linkHeader = $response->header('Link');
            $url = null;
            if ($linkHeader && preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
                $url = $matches[1];
            }
        }

        return $allOrders;
    }

    private function storeOrdersAndFacts(array $orders): void
    {
        foreach ($orders as $order) {
            $orderId         = (string) $order['id'];
            $email           = strtolower(trim($order['email'] ?? ''));
            $phone           = trim($order['phone'] ?? $order['customer']['phone'] ?? '');
            $customerId      = isset($order['customer']['id']) ? (string) $order['customer']['id'] : null;
            $total           = (float) ($order['total_price'] ?? 0);
            $tax             = (float) ($order['total_tax'] ?? 0);
            $createdAt       = Carbon::parse($order['created_at']);
            $financialStatus = strtolower($order['financial_status'] ?? '');
            $isCancelled     = ! empty($order['cancelled_at']);
            $isValid         = ! $isCancelled && in_array($financialStatus, ['paid', 'partially_refunded', 'authorized']);

            $hash = CustomerIdentityHasher::hash(['email' => $email, 'phone' => $phone]);

            CommerceOrder::updateOrCreate(
                [
                    'client_id' => $this->integration->client_id,
                    'order_id'  => $orderId,
                    'source'    => 'shopify',
                ],
                [
                    'order_number'           => $order['name'] ?? $order['order_number'] ?? $orderId,
                    'customer_identity_hash' => $hash,
                    'registered_customer_id' => $customerId,
                    'order_status'           => $order['financial_status'] ?? 'pending',
                    'order_timestamp'        => $createdAt,
                    'order_date'             => $createdAt->toDateString(),
                    'gross_total'            => $total,
                    'net_total'              => $isCancelled ? 0.0 : $total,
                    'tax_amount'             => $tax,
                    'discount_amount'        => (float) ($order['total_discounts'] ?? 0),
                    'currency'               => $order['currency'] ?? 'USD',
                    'items_count'            => count($order['line_items'] ?? []),
                    'is_valid_order'         => $isValid,
                ]
            );

            if ($hash && $isValid) {
                CustomerPurchaseFact::updateOrCreate(
                    [
                        'client_id'              => $this->integration->client_id,
                        'customer_identity_hash' => $hash,
                    ],
                    [
                        'first_purchase_at'  => \DB::raw("LEAST(COALESCE(first_purchase_at, '{$createdAt}'), '{$createdAt}')"),
                        'latest_purchase_at' => \DB::raw("GREATEST(COALESCE(latest_purchase_at, '{$createdAt}'), '{$createdAt}')"),
                        'total_valid_orders' => \DB::raw("total_valid_orders + 1"),
                        'total_spend'        => \DB::raw("total_spend + {$total}"),
                    ]
                );
            }
        }
    }

    private function aggregateByDay(array $orders): array
    {
        $byDay = [];
        foreach ($orders as $order) {
            $date = Carbon::parse($order['created_at'])->toDateString();
            if (! isset($byDay[$date])) {
                $byDay[$date] = [
                    'revenue'             => 0.0,
                    'orders'              => 0,
                    'new_customers'       => 0,
                    'returning_customers' => 0,
                    'aov'                 => 0.0,
                ];
            }

            if (empty($order['cancelled_at'])) {
                $rev = (float) ($order['total_price'] ?? 0);
                $byDay[$date]['revenue'] += $rev;
                $byDay[$date]['orders']++;

                $ordersCount = (int) ($order['customer']['orders_count'] ?? 1);
                if ($ordersCount <= 1) {
                    $byDay[$date]['new_customers']++;
                } else {
                    $byDay[$date]['returning_customers']++;
                }
            }
        }

        foreach ($byDay as $date => &$m) {
            $m['aov'] = $m['orders'] > 0 ? round($m['revenue'] / $m['orders'], 2) : 0.0;
        }

        return $byDay;
    }

    private function normalizeShopDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', trim($domain));
        $domain = rtrim($domain, '/');
        if (! str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }
        return $domain;
    }
}
