<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\EmailMarketingMetric;
use Carbon\Carbon;

class FindingMetricRegistry
{
    /**
     * Get all registered metric definitions.
     *
     * @return array<string, array{label: string, source: string, type: string, resolver: callable, availability: callable}>
     */
    public static function getRegisteredMetrics(): array
    {
        return [
            // ─── GA4 Metrics ───────────────────────────────────────────────────
            'ga4.revenue' => [
                'label'        => 'GA4 Total Revenue',
                'source'       => 'ga4',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'ga4', CommerceMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'ga4')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('revenue');
                },
            ],
            'ga4.email_revenue' => [
                'label'        => 'GA4 Email Revenue',
                'source'       => 'ga4',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'ga4', CommerceMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    $metrics = CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'ga4')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->get();

                    $total = 0.0;
                    foreach ($metrics as $metric) {
                        $breakdown = $metric->source_breakdown_json ?? [];
                        $email = $breakdown['email'] ?? $breakdown['Email'] ?? null;
                        if ($email !== null) {
                            if (is_array($email)) {
                                $total += (float) ($email['revenue'] ?? 0.0);
                            } elseif (is_numeric($email)) {
                                $total += (float) $email;
                            }
                        }
                    }
                    return round($total, 2);
                },
            ],
            'ga4.sessions' => [
                'label'        => 'GA4 Sessions',
                'source'       => 'ga4',
                'type'         => 'number',
                'availability' => fn (Client $c) => self::hasSource($c, 'ga4', CommerceMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'ga4')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('sessions');
                },
            ],
            'ga4.users' => [
                'label'        => 'GA4 Active Users',
                'source'       => 'ga4',
                'type'         => 'number',
                'availability' => fn (Client $c) => self::hasSource($c, 'ga4', CommerceMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'ga4')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('active_users');
                },
            ],
            'ga4.conversion_rate' => [
                'label'        => 'GA4 Conversion Rate',
                'source'       => 'ga4',
                'type'         => 'percentage',
                'availability' => fn (Client $c) => self::hasSource($c, 'ga4', CommerceMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    $row = CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'ga4')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->selectRaw('COALESCE(SUM(sessions), 0) as s, COALESCE(SUM(orders), 0) as o')
                        ->first();

                    $sessions = (int) ($row->s ?? 0);
                    $orders   = (int) ($row->o ?? 0);

                    return $sessions > 0 ? round(($orders / $sessions) * 100, 2) : 0.0;
                },
            ],
            'ga4.aov' => [
                'label'        => 'GA4 Average Order Value',
                'source'       => 'ga4',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'ga4', CommerceMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    $row = CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'ga4')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->selectRaw('COALESCE(SUM(revenue), 0) as r, COALESCE(SUM(orders), 0) as o')
                        ->first();

                    $revenue = (float) ($row->r ?? 0);
                    $orders  = (int) ($row->o ?? 0);

                    return $orders > 0 ? round($revenue / $orders, 2) : 0.0;
                },
            ],

            // ─── Klaviyo Metrics ───────────────────────────────────────────────
            'klaviyo.attributed_revenue' => [
                'label'        => 'Klaviyo Attributed Revenue',
                'source'       => 'klaviyo',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'klaviyo', EmailMarketingMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) EmailMarketingMetric::where('client_id', $c->id)
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('revenue');
                },
            ],
            'klaviyo.campaign_revenue' => [
                'label'        => 'Klaviyo Campaign Revenue',
                'source'       => 'klaviyo',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'klaviyo', EmailMarketingMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) EmailMarketingMetric::where('client_id', $c->id)
                        ->where('type', 'campaign')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('revenue');
                },
            ],
            'klaviyo.flow_revenue' => [
                'label'        => 'Klaviyo Flow Revenue',
                'source'       => 'klaviyo',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'klaviyo', EmailMarketingMetric::class),
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) EmailMarketingMetric::where('client_id', $c->id)
                        ->where('type', 'flow')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('revenue');
                },
            ],

            // ─── Shopify Metrics ───────────────────────────────────────────────
            'shopify.revenue' => [
                'label'        => 'Shopify Total Revenue',
                'source'       => 'shopify',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'shopify', CommerceMetric::class) || strcasecmp($c->platform_type ?? '', 'shopify') === 0,
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'shopify')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('revenue');
                },
            ],
            'shopify.orders' => [
                'label'        => 'Shopify Total Orders',
                'source'       => 'shopify',
                'type'         => 'number',
                'availability' => fn (Client $c) => self::hasSource($c, 'shopify', CommerceMetric::class) || strcasecmp($c->platform_type ?? '', 'shopify') === 0,
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'shopify')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('orders');
                },
            ],
            'shopify.aov' => [
                'label'        => 'Shopify Average Order Value',
                'source'       => 'shopify',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'shopify', CommerceMetric::class) || strcasecmp($c->platform_type ?? '', 'shopify') === 0,
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    $row = CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'shopify')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->selectRaw('COALESCE(SUM(revenue), 0) as r, COALESCE(SUM(orders), 0) as o')
                        ->first();

                    $revenue = (float) ($row->r ?? 0);
                    $orders  = (int) ($row->o ?? 0);

                    return $orders > 0 ? round($revenue / $orders, 2) : 0.0;
                },
            ],

            // ─── Adobe Commerce Metrics ────────────────────────────────────────
            'adobe_commerce.revenue' => [
                'label'        => 'Adobe Commerce Total Revenue',
                'source'       => 'adobe_commerce',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'adobe_commerce', CommerceMetric::class) || strcasecmp($c->platform_type ?? '', 'adobe commerce') === 0,
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'adobe_commerce')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('revenue');
                },
            ],
            'adobe_commerce.orders' => [
                'label'        => 'Adobe Commerce Total Orders',
                'source'       => 'adobe_commerce',
                'type'         => 'number',
                'availability' => fn (Client $c) => self::hasSource($c, 'adobe_commerce', CommerceMetric::class) || strcasecmp($c->platform_type ?? '', 'adobe commerce') === 0,
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    return (float) CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'adobe_commerce')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->sum('orders');
                },
            ],
            'adobe_commerce.aov' => [
                'label'        => 'Adobe Commerce Average Order Value',
                'source'       => 'adobe_commerce',
                'type'         => 'currency',
                'availability' => fn (Client $c) => self::hasSource($c, 'adobe_commerce', CommerceMetric::class) || strcasecmp($c->platform_type ?? '', 'adobe commerce') === 0,
                'resolver'     => function (Client $c, Carbon $from, Carbon $to): float {
                    $row = CommerceMetric::where('client_id', $c->id)
                        ->where('source', 'adobe_commerce')
                        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                        ->selectRaw('COALESCE(SUM(revenue), 0) as r, COALESCE(SUM(orders), 0) as o')
                        ->first();

                    $revenue = (float) ($row->r ?? 0);
                    $orders  = (int) ($row->o ?? 0);

                    return $orders > 0 ? round($revenue / $orders, 2) : 0.0;
                },
            ],
        ];
    }

    /**
     * Get available metrics for a specific client based on connected integrations and stored metric data.
     *
     * @return array<int, array{key: string, label: string, source: string, type: string}>
     */
    public static function getAvailableMetricsForClient(Client $client): array
    {
        $all = self::getRegisteredMetrics();
        $available = [];

        foreach ($all as $key => $meta) {
            $isAvail = ($meta['availability'])($client);
            if ($isAvail) {
                $available[] = [
                    'key'    => $key,
                    'label'  => $meta['label'],
                    'source' => $meta['source'],
                    'type'   => $meta['type'],
                ];
            }
        }

        return $available;
    }

    /**
     * Check if a metric key is registered.
     */
    public static function hasMetric(string $key): bool
    {
        return isset(self::getRegisteredMetrics()[$key]);
    }

    /**
     * Check if a metric is available and evaluable for a specific client.
     */
    public static function isMetricAvailableForClient(Client $client, string $key): bool
    {
        $all = self::getRegisteredMetrics();
        if (! isset($all[$key])) {
            return false;
        }

        return (bool) ($all[$key]['availability'])($client);
    }

    /**
     * Resolve a numeric metric value for a client over a given period.
     */
    public static function resolveMetric(Client $client, string $key, Carbon $from, Carbon $to): ?float
    {
        $all = self::getRegisteredMetrics();
        if (! isset($all[$key])) {
            return null;
        }

        return (float) ($all[$key]['resolver'])($client, $from, $to);
    }

    /**
     * Helper to verify if client has an active integration or data records for a source.
     */
    private static function hasSource(Client $client, string $source, string $modelClass): bool
    {
        $activeIntegrations = $client->getActiveIntegrationTypes();
        if (in_array(strtolower($source), array_map('strtolower', $activeIntegrations), true)) {
            return true;
        }

        // Also check if records exist in database
        if ($modelClass === CommerceMetric::class) {
            return CommerceMetric::where('client_id', $client->id)->where('source', $source)->exists();
        }

        if ($modelClass === EmailMarketingMetric::class) {
            return EmailMarketingMetric::where('client_id', $client->id)->exists();
        }

        return false;
    }
}
