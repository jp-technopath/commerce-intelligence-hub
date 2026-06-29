<?php

namespace App\Services\Connectors;

use App\Enums\SyncStatus;
use App\Models\EmailMarketingMetric;
use App\Models\Integration;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * KlaviyoConnector
 *
 * Syncs campaign and flow email-marketing metrics from the Klaviyo API
 * (revision 2024-10-15) into the email_marketing_metrics table.
 *
 * Authentication: Klaviyo-API-Key private key via credentials_json['api_key'].
 *
 * Rate limit: 75 req/s for private API keys.
 * The connector handles 429 responses with a brief sleep + single retry.
 */
class KlaviyoConnector
{
    private const BASE_URL  = 'https://a.klaviyo.com/api';
    private const REVISION  = '2024-10-15';
    private const MAX_RETRIES = 2;

    private const STATISTICS = [
        'opens',
        'clicks',
        'recipients',
        'unsubscribes',
        'conversion_value',
        'conversions',
        'bounced',
    ];

    private array $credentials;

    /** @var array<string, string> In-memory cache of campaign ID → name */
    private array $campaignNameCache = [];

    /** @var array<string, string> In-memory cache of flow ID → name */
    private array $flowNameCache = [];

    /** @var string|null Cached conversion metric ID for 'Placed Order' */
    private ?string $conversionMetricId = null;

    public function __construct(private readonly Integration $integration)
    {
        $this->credentials = $integration->credentials_json ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run a full sync for this integration.
     *
     * Klaviyo rate limits: Burst 1/s, Steady 2/m, Daily 225/d.
     * We split into current + comparison period (4 API calls total)
     * with a 35s pause between pairs to stay under the steady limit.
     */
    public function sync(SyncLog $syncLog, int $numOfDays = 30): void
    {
        $apiKey = $this->credentials['api_key'] ?? null;

        if (! $apiKey) {
            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => 'Missing Klaviyo api_key. Please add your private API key.',
                'completed_at'  => now(),
            ]);
            return;
        }

        try {
            $total = 0;
            $halfDays = (int) ceil($numOfDays / 2);

            // ── Current period ───────────────────────────────────────────
            $currentFrom = now()->subDays($halfDays)->startOfDay();
            $currentTo   = now()->endOfDay();

            $total += $this->fetchCampaignMetrics($currentFrom, $currentTo);
            $total += $this->fetchFlowMetrics($currentFrom, $currentTo);

            // ── Comparison period (only if numOfDays > halfDays) ──────────
            if ($numOfDays > $halfDays) {
                sleep(35); // respect Klaviyo's 2 req/min steady rate limit

                $prevFrom = now()->subDays($numOfDays)->startOfDay();
                $prevTo   = now()->subDays($halfDays)->endOfDay();

                $total += $this->fetchCampaignMetrics($prevFrom, $prevTo);
                $total += $this->fetchFlowMetrics($prevFrom, $prevTo);
            }

            $syncLog->update([
                'status'            => SyncStatus::Success,
                'records_processed' => $total,
                'completed_at'      => now(),
            ]);

            Log::info('KlaviyoConnector: sync complete', [
                'integration_id' => $this->integration->id,
                'num_of_days'    => $numOfDays,
                'total'          => $total,
            ]);

        } catch (\Exception $e) {
            $safe = $this->sanitiseError($e->getMessage(), $apiKey);

            Log::error('KlaviyoConnector: sync error', [
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
     * Fetch campaign-level email metrics from Klaviyo.
     *
     * Aggregates by campaign_id + send_channel before storing.
     *
     * @return int Number of records stored
     */
    public function fetchCampaignMetrics(?Carbon $from = null, ?Carbon $to = null): int
    {
        $from = $from ?? now()->subDays(30)->startOfDay();
        $to   = $to   ?? now()->endOfDay();

        $body = [
            'data' => [
                'type'       => 'campaign-values-report',
                'attributes' => [
                    'statistics'           => self::STATISTICS,
                    'timeframe'            => [
                        'start' => $from->format('Y-m-d\TH:i:s'),
                        'end'   => $to->format('Y-m-d\TH:i:s'),
                    ],
                    'conversion_metric_id' => $this->getConversionMetricId(),
                ],
            ],
        ];

        $response = $this->makeRequest('POST', self::BASE_URL . '/campaign-values-reports/', $body);
        $results  = $response['data']['attributes']['results'] ?? [];

        // Aggregate by campaign_id + send_channel
        $aggregated = [];
        foreach ($results as $result) {
            $campaignId  = $result['groupings']['campaign_id']  ?? null;
            $sendChannel = $result['groupings']['send_channel'] ?? 'email';
            $stats       = $result['statistics']                ?? [];

            if (! $campaignId) {
                continue;
            }

            $key = $campaignId . '|' . $sendChannel;

            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'campaign_id'      => $campaignId,
                    'channel'          => $sendChannel,
                    'recipients'       => 0,
                    'opens'            => 0,
                    'clicks'           => 0,
                    'conversions'      => 0,
                    'conversion_value' => 0,
                    'unsubscribes'     => 0,
                    'bounced'          => 0,
                ];
            }

            $aggregated[$key]['recipients']       += (int)   ($stats['recipients']       ?? 0);
            $aggregated[$key]['opens']             += (int)   ($stats['opens']            ?? 0);
            $aggregated[$key]['clicks']            += (int)   ($stats['clicks']           ?? 0);
            $aggregated[$key]['conversions']       += (int)   ($stats['conversions']      ?? 0);
            $aggregated[$key]['conversion_value']  += (float) ($stats['conversion_value'] ?? 0);
            $aggregated[$key]['unsubscribes']      += (int)   ($stats['unsubscribes']     ?? 0);
            $aggregated[$key]['bounced']           += (int)   ($stats['bounced']          ?? 0);
        }

        // Store aggregated results
        $count = 0;
        foreach ($aggregated as $agg) {
            $campaignName = $this->getCampaignName($agg['campaign_id']);
            $recipients   = $agg['recipients'];
            $opens        = $agg['opens'];
            $clicks       = $agg['clicks'];

            EmailMarketingMetric::updateOrCreate(
                [
                    'client_id'     => $this->integration->client_id,
                    'date'          => $from->toDateString(),
                    'source'        => 'klaviyo',
                    'type'          => 'campaign',
                    'channel'       => $agg['channel'],
                    'campaign_name' => $campaignName,
                ],
                [
                    'recipients'   => $recipients,
                    'opens'        => $opens,
                    'clicks'       => $clicks,
                    'conversions'  => $agg['conversions'],
                    'revenue'      => $agg['conversion_value'],
                    'unsubscribes' => $agg['unsubscribes'],
                    'bounces'      => $agg['bounced'],
                    'open_rate'    => $recipients > 0 ? round($opens  / $recipients, 4) : 0,
                    'click_rate'   => $recipients > 0 ? round($clicks / $recipients, 4) : 0,
                    'metadata_json' => [
                        'campaign_id' => $agg['campaign_id'],
                    ],
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Fetch flow-level email metrics from Klaviyo.
     *
     * The API can return multiple rows per flow (one per flow message/step),
     * so we aggregate by flow_id + send_channel before storing.
     *
     * @return int Number of records stored
     */
    public function fetchFlowMetrics(?Carbon $from = null, ?Carbon $to = null): int
    {
        $from = $from ?? now()->subDays(30)->startOfDay();
        $to   = $to   ?? now()->endOfDay();

        $body = [
            'data' => [
                'type'       => 'flow-values-report',
                'attributes' => [
                    'statistics'           => self::STATISTICS,
                    'timeframe'            => [
                        'start' => $from->format('Y-m-d\TH:i:s'),
                        'end'   => $to->format('Y-m-d\TH:i:s'),
                    ],
                    'conversion_metric_id' => $this->getConversionMetricId(),
                ],
            ],
        ];

        $response = $this->makeRequest('POST', self::BASE_URL . '/flow-values-reports/', $body);
        $results  = $response['data']['attributes']['results'] ?? [];

        // Aggregate by flow_id + send_channel (API returns multiple rows per flow step)
        $aggregated = [];
        foreach ($results as $result) {
            $flowId      = $result['groupings']['flow_id']      ?? null;
            $sendChannel = $result['groupings']['send_channel'] ?? 'email';
            $stats       = $result['statistics']                ?? [];

            if (! $flowId) {
                continue;
            }

            $key = $flowId . '|' . $sendChannel;

            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'flow_id'          => $flowId,
                    'channel'          => $sendChannel,
                    'recipients'       => 0,
                    'opens'            => 0,
                    'clicks'           => 0,
                    'conversions'      => 0,
                    'conversion_value' => 0,
                    'unsubscribes'     => 0,
                    'bounced'          => 0,
                ];
            }

            $aggregated[$key]['recipients']       += (int)   ($stats['recipients']       ?? 0);
            $aggregated[$key]['opens']             += (int)   ($stats['opens']            ?? 0);
            $aggregated[$key]['clicks']            += (int)   ($stats['clicks']           ?? 0);
            $aggregated[$key]['conversions']       += (int)   ($stats['conversions']      ?? 0);
            $aggregated[$key]['conversion_value']  += (float) ($stats['conversion_value'] ?? 0);
            $aggregated[$key]['unsubscribes']      += (int)   ($stats['unsubscribes']     ?? 0);
            $aggregated[$key]['bounced']           += (int)   ($stats['bounced']          ?? 0);
        }

        // Store aggregated results
        $count = 0;
        foreach ($aggregated as $agg) {
            $flowName   = $this->getFlowName($agg['flow_id']);
            $recipients = $agg['recipients'];
            $opens      = $agg['opens'];
            $clicks     = $agg['clicks'];

            EmailMarketingMetric::updateOrCreate(
                [
                    'client_id'     => $this->integration->client_id,
                    'date'          => $from->toDateString(),
                    'source'        => 'klaviyo',
                    'type'          => 'flow',
                    'channel'       => $agg['channel'],
                    'campaign_name' => $flowName,
                ],
                [
                    'flow_id'      => $agg['flow_id'],
                    'recipients'   => $recipients,
                    'opens'        => $opens,
                    'clicks'       => $clicks,
                    'conversions'  => $agg['conversions'],
                    'revenue'      => $agg['conversion_value'],
                    'unsubscribes' => $agg['unsubscribes'],
                    'bounces'      => $agg['bounced'],
                    'open_rate'    => $recipients > 0 ? round($opens  / $recipients, 4) : 0,
                    'click_rate'   => $recipients > 0 ? round($clicks / $recipients, 4) : 0,
                    'metadata_json' => [
                        'flow_id' => $agg['flow_id'],
                    ],
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Fetch Klaviyo metrics for a specific date range (on-demand investigator use).
     *
     * Does NOT create or update a SyncLog. Returns combined count of records stored.
     */
    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        $apiKey = $this->credentials['api_key'] ?? null;

        if (! $apiKey) {
            Log::warning('KlaviyoConnector::fetchForDateRange — missing api_key', [
                'integration_id' => $this->integration->id,
            ]);
            return 0;
        }

        try {
            $campaignCount = $this->fetchCampaignMetrics($from, $to);
            $flowCount     = $this->fetchFlowMetrics($from, $to);

            return $campaignCount + $flowCount;

        } catch (\Exception $e) {
            Log::error('KlaviyoConnector::fetchForDateRange failed', [
                'integration_id' => $this->integration->id,
                'from'           => $from->toDateString(),
                'to'             => $to->toDateString(),
                'class'          => get_class($e),
                'message'        => $this->sanitiseError($e->getMessage(), $apiKey),
            ]);

            return 0;
        }
    }

    /**
     * Quick connection test — fetches the Klaviyo account info.
     */
    public function testConnection(): array
    {
        $apiKey = $this->credentials['api_key'] ?? null;

        if (! $apiKey) {
            return ['success' => false, 'message' => 'Missing Klaviyo API key.'];
        }

        try {
            $response = $this->makeRequest('GET', self::BASE_URL . '/accounts/');

            $publicKey = $response['data'][0]['attributes']['public_api_key']
                      ?? $response['data'][0]['id']
                      ?? 'unknown';

            return [
                'success' => true,
                'message' => "Connected — account: {$publicKey}",
            ];

        } catch (\Exception $e) {
            $safe = $this->sanitiseError($e->getMessage(), $apiKey);

            Log::error('KlaviyoConnector: testConnection failed', [
                'integration_id' => $this->integration->id,
                'message'        => $safe,
            ]);

            return [
                'success' => false,
                'message' => match (true) {
                    str_contains($safe, '401') || str_contains($safe, '403')
                        => 'Authorization failed. Check your Klaviyo private API key.',
                    str_contains($safe, '429')
                        => 'Klaviyo rate limit reached. Please try again shortly.',
                    default
                        => 'Connection error: ' . $safe,
                },
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: HTTP helper
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Make an authenticated request to the Klaviyo API.
     *
     * Includes the required revision header and handles 429 rate-limit
     * responses with a brief sleep + retry.
     */
    private function makeRequest(string $method, string $url, array $body = []): array
    {
        $apiKey = $this->credentials['api_key'];

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $pending = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "Klaviyo-API-Key {$apiKey}",
                    'revision'      => self::REVISION,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ]);

            $response = strtoupper($method) === 'GET'
                ? $pending->get($url)
                : $pending->post($url, $body);

            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 2);
                Log::warning('KlaviyoConnector: 429 rate limited, retrying', [
                    'integration_id' => $this->integration->id,
                    'attempt'        => $attempt,
                    'retry_after'    => $retryAfter,
                    'url'            => $url,
                ]);
                sleep(min($retryAfter, 10));
                continue;
            }

            $response->throw();

            return $response->json() ?? [];
        }

        throw new \RuntimeException('Klaviyo API rate limit exceeded after ' . self::MAX_RETRIES . ' retries.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Name lookups (cached per sync run)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch and cache a campaign name by ID.
     */
    private function getCampaignName(string $campaignId): string
    {
        if (isset($this->campaignNameCache[$campaignId])) {
            return $this->campaignNameCache[$campaignId];
        }

        try {
            $response = $this->makeRequest('GET', self::BASE_URL . "/campaigns/{$campaignId}");
            $name     = $response['data']['attributes']['name'] ?? "Campaign {$campaignId}";
        } catch (\Exception $e) {
            Log::warning('KlaviyoConnector: failed to fetch campaign name', [
                'campaign_id' => $campaignId,
                'message'     => $e->getMessage(),
            ]);
            $name = "Campaign {$campaignId}";
        }

        $this->campaignNameCache[$campaignId] = $name;

        return $name;
    }

    /**
     * Fetch and cache a flow name by ID.
     */
    private function getFlowName(string $flowId): string
    {
        if (isset($this->flowNameCache[$flowId])) {
            return $this->flowNameCache[$flowId];
        }

        try {
            $response = $this->makeRequest('GET', self::BASE_URL . "/flows/{$flowId}");
            $name     = $response['data']['attributes']['name'] ?? "Flow {$flowId}";
        } catch (\Exception $e) {
            Log::warning('KlaviyoConnector: failed to fetch flow name', [
                'flow_id' => $flowId,
                'message' => $e->getMessage(),
            ]);
            $name = "Flow {$flowId}";
        }

        $this->flowNameCache[$flowId] = $name;

        return $name;
    }

    /**
     * Look up the Klaviyo metric ID for 'Placed Order' (cached per sync run).
     *
     * Falls back to 'Ordered Product' if 'Placed Order' is not found.
     * Returns a safe default if the API call fails entirely.
     */
    private function getConversionMetricId(): string
    {
        if ($this->conversionMetricId !== null) {
            return $this->conversionMetricId;
        }

        try {
            $response = $this->makeRequest('GET', self::BASE_URL . '/metrics/');
            $metrics  = $response['data'] ?? [];

            foreach ($metrics as $metric) {
                $name = $metric['attributes']['name'] ?? '';
                $id   = $metric['id'] ?? '';

                if ($name === 'Placed Order') {
                    $this->conversionMetricId = $id;
                    return $id;
                }
            }

            // Fallback: look for 'Ordered Product'
            foreach ($metrics as $metric) {
                $name = $metric['attributes']['name'] ?? '';
                $id   = $metric['id'] ?? '';

                if (stripos($name, 'order') !== false) {
                    $this->conversionMetricId = $id;
                    return $id;
                }
            }

            // Last resort: use the first metric
            if (! empty($metrics)) {
                $this->conversionMetricId = $metrics[0]['id'];
                return $this->conversionMetricId;
            }

        } catch (\Exception $e) {
            Log::warning('KlaviyoConnector: failed to look up conversion metric ID', [
                'message' => $e->getMessage(),
            ]);
        }

        // Absolute fallback — Klaviyo docs say this field is required
        $this->conversionMetricId = 'Placed Order';
        return $this->conversionMetricId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Security
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Strip or mask the API key from error messages to prevent credential leakage.
     */
    private function sanitiseError(string $message, string $apiKey): string
    {
        if (strlen($apiKey) > 8) {
            $masked  = substr($apiKey, 0, 4) . str_repeat('•', max(4, strlen($apiKey) - 8)) . substr($apiKey, -4);
            $message = str_replace($apiKey, $masked, $message);
        }

        return $message;
    }
}
