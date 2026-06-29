<?php

namespace App\Services\Connectors;

use App\Models\Integration;
use App\Models\SyncLog;
use App\Enums\SyncStatus;
use Google\Client as GoogleClient;
use Google\Service\AnalyticsData;
use Google\Service\AnalyticsData\DateRange;
use Google\Service\AnalyticsData\Dimension;
use Google\Service\AnalyticsData\Metric;
use Google\Service\AnalyticsData\RunReportRequest;
use Google\Service\AnalyticsData\FilterExpression;
use Google\Service\AnalyticsData\Filter;
use Google\Service\AnalyticsData\InListFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GA4Connector
{
    private Integration $integration;
    private array $credentials;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
        $this->credentials = $integration->credentials_json ?? [];
    }

    /**
     * Run a full sync for this integration.
     */
    public function sync(SyncLog $syncLog, int $numOfDays = 30): void
    {
        $propertyId   = $this->credentials['property_id'] ?? null;
        $refreshToken = $this->credentials['refresh_token'] ?? null;

        if (! $propertyId || ! $refreshToken) {
            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => 'GA4 integration is missing property_id or refresh_token. Please complete authorization.',
                'completed_at'  => now(),
            ]);
            return;
        }

        try {
            $service = $this->buildService($refreshToken);
            $data    = $this->fetchReport($service, $propertyId, "{$numOfDays}daysAgo");

            $this->storeMetrics($data);

            // Fetch ecommerce funnel events
            $funnelData = $this->fetchFunnelReport($service, $propertyId, "{$numOfDays}daysAgo");
            $this->storeFunnelMetrics($funnelData);

            Log::info('GA4Connector: sync complete', [
                'integration_id' => $this->integration->id,
                'num_of_days'    => $numOfDays,
                'date_range'     => "{$numOfDays}daysAgo → yesterday",
                'rows_returned'  => count($data),
            ]);

            $syncLog->update([
                'status'            => SyncStatus::Success,
                'records_processed' => count($data),
                'completed_at'      => now(),
            ]);

        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e, $syncLog);
        } catch (\Exception $e) {
            Log::error('GA4Connector: unexpected sync error', [
                'integration_id' => $this->integration->id,
                'class'          => get_class($e),
                'message'        => $e->getMessage(),
            ]);

            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => 'Unexpected error during GA4 sync. Check application logs.',
                'completed_at'  => now(),
            ]);
        }
    }

    /**
     * Build an authenticated Google AnalyticsData service using REST (not gRPC).
     * Google\Client handles Web Application OAuth2 refresh tokens natively.
     */
    private function buildService(string $refreshToken): AnalyticsData
    {
        $client = new GoogleClient();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setAccessType('offline');
        $client->setScopes([AnalyticsData::ANALYTICS_READONLY]);

        // Set the full token array — Google\Client needs expires_in + created
        // to know if it needs to refresh. Setting them to 0 forces a refresh.
        $client->setAccessToken([
            'access_token'  => 'placeholder',
            'refresh_token' => $refreshToken,
            'expires_in'    => 0,
            'created'       => 0,
        ]);

        // Force refresh to get a valid access token
        $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($newToken['error'])) {
            throw new \RuntimeException(
                'Failed to refresh Google access token: ' . ($newToken['error_description'] ?? $newToken['error'])
            );
        }

        // Validate that the Analytics scope was granted
        $grantedScope = $newToken['scope'] ?? '';
        if (! str_contains($grantedScope, 'analytics.readonly')) {
            throw new \RuntimeException(
                'SCOPE_MISSING: The authorized Google account does not have the Analytics scope. '
                . 'Please disconnect and re-authorize, making sure to check the Analytics checkbox on the Google consent screen. '
                . 'Granted scopes: ' . $grantedScope
            );
        }

        return new AnalyticsData($client);
    }

    /**
     * Fetch a GA4 report broken down by date + channel.
     *
     * @param  string  $startDate  GA4 date string (e.g. '30daysAgo' or 'YYYY-MM-DD')
     * @param  string  $endDate    GA4 date string (e.g. 'yesterday' or 'YYYY-MM-DD')
     */
    private function fetchReport(AnalyticsData $service, string $propertyId, string $startDate = '30daysAgo', string $endDate = 'yesterday'): array
    {
        $request = new RunReportRequest();

        $dateRange = new DateRange();
        $dateRange->setStartDate($startDate);
        $dateRange->setEndDate($endDate);
        $request->setDateRanges([$dateRange]);

        $dateDimension = new Dimension();
        $dateDimension->setName('date');
        $channelDimension = new Dimension();
        $channelDimension->setName('sessionDefaultChannelGroup');
        $request->setDimensions([$dateDimension, $channelDimension]);

        $metrics = [];
        foreach ([
            'sessions', 'activeUsers', 'newUsers', 'totalUsers',
            'screenPageViews', 'bounceRate', 'averageSessionDuration',
            'purchaseRevenue', 'ecommercePurchases', 'sessionConversionRate',
        ] as $name) {
            $m = new Metric();
            $m->setName($name);
            $metrics[] = $m;
        }
        $request->setMetrics($metrics);

        $response = $service->properties->runReport("properties/{$propertyId}", $request);

        $byDate = [];

        foreach ($response->getRows() as $row) {
            $dimensions = $row->getDimensionValues();
            $metrics    = $row->getMetricValues();

            $date    = $dimensions[0]->getValue();  // YYYYMMDD
            $channel = $dimensions[1]->getValue();

            $dateKey = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);

            if (! isset($byDate[$dateKey])) {
                $byDate[$dateKey] = [
                    'date'                 => $dateKey,
                    'sessions'             => 0,
                    'active_users'         => 0,
                    'new_users'            => 0,
                    'total_users'          => 0,
                    'page_views'           => 0,
                    'bounce_rate'          => 0.0,
                    'avg_session_duration' => 0.0,
                    'revenue'              => 0.0,
                    'transactions'         => 0,
                    'conversion_rate'      => 0.0,
                    'source_breakdown'     => [],
                    '_rows'                => 0,
                ];
            }

            $r = &$byDate[$dateKey];
            $n = $r['_rows'];

            $sessions    = (int)   $metrics[0]->getValue();
            $activeUsers = (int)   $metrics[1]->getValue();
            $newUsers    = (int)   $metrics[2]->getValue();
            $totalUsers  = (int)   $metrics[3]->getValue();
            $pageViews   = (int)   $metrics[4]->getValue();
            $bounceRate  = (float) $metrics[5]->getValue();
            $avgDuration = (float) $metrics[6]->getValue();
            $revenue     = (float) $metrics[7]->getValue();
            $purchases   = (int)   $metrics[8]->getValue();
            $convRate    = (float) $metrics[9]->getValue();

            $r['sessions']             += $sessions;
            $r['active_users']         += $activeUsers;
            $r['new_users']            += $newUsers;
            $r['total_users']          += $totalUsers;
            $r['page_views']           += $pageViews;
            $r['bounce_rate']           = ($r['bounce_rate'] * $n + $bounceRate) / ($n + 1);
            $r['avg_session_duration']  = ($r['avg_session_duration'] * $n + $avgDuration) / ($n + 1);
            $r['revenue']              += $revenue;
            $r['transactions']         += $purchases;
            $r['conversion_rate']       = ($r['conversion_rate'] * $n + $convRate) / ($n + 1);
            $r['_rows']++;

            $channelKey = $this->normalizeChannel($channel);
            if (! isset($r['source_breakdown'][$channelKey])) {
                $r['source_breakdown'][$channelKey] = [
                    'sessions'     => 0,
                    'new_users'    => 0,
                    'revenue'      => 0.0,
                    'transactions' => 0,
                ];
            }
            $r['source_breakdown'][$channelKey]['sessions']     += $sessions;
            $r['source_breakdown'][$channelKey]['new_users']    += $newUsers;
            $r['source_breakdown'][$channelKey]['revenue']      += $revenue;
            $r['source_breakdown'][$channelKey]['transactions'] += $purchases;
        }

        return $byDate;
    }

    /**
     * Upsert fetched data into commerce_metrics.
     */
    private function storeMetrics(array $byDate): void
    {
        foreach ($byDate as $dateKey => $data) {
            $aov = $data['transactions'] > 0
                ? round($data['revenue'] / $data['transactions'], 2)
                : 0.0;

            $this->integration->client->commerceMetrics()->updateOrCreate(
                ['date' => $dateKey, 'source' => 'ga4'],
                [
                    'sessions'              => $data['sessions'],
                    'active_users'          => $data['active_users'],
                    'new_customers'         => $data['new_users'],
                    'returning_customers'   => max(0, $data['total_users'] - $data['new_users']),
                    'revenue'               => round($data['revenue'], 2),
                    'orders'                => $data['transactions'],
                    'conversion_rate'       => round($data['conversion_rate'] * 100, 4),
                    'average_order_value'   => $aov,
                    'source_breakdown_json' => $data['source_breakdown'],
                    'device_breakdown_json' => [],
                ]
            );
        }
    }

    /**
     * Fetch GA4 data for a specific date range (on-demand investigator use).
     *
     * Does NOT create a SyncLog. Returns the count of date-rows fetched,
     * or 0 on failure.
     */
    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        $propertyId   = $this->credentials['property_id'] ?? null;
        $refreshToken = $this->credentials['refresh_token'] ?? null;

        if (! $propertyId || ! $refreshToken) {
            Log::warning('GA4Connector::fetchForDateRange — missing credentials', [
                'integration_id' => $this->integration->id,
            ]);
            return 0;
        }

        try {
            $service = $this->buildService($refreshToken);

            $data = $this->fetchReport(
                $service,
                $propertyId,
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            );

            $this->storeMetrics($data);

            return count($data);
        } catch (\Exception $e) {
            Log::error('GA4Connector::fetchForDateRange failed', [
                'integration_id' => $this->integration->id,
                'from'           => $from->toDateString(),
                'to'             => $to->toDateString(),
                'class'          => get_class($e),
                'message'        => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Fetch ecommerce funnel event counts by date.
     * Events: view_item, add_to_cart, begin_checkout, purchase
     */
    private function fetchFunnelReport(AnalyticsData $service, string $propertyId, string $startDate = '30daysAgo', string $endDate = 'yesterday'): array
    {
        $request = new RunReportRequest();

        $dateRange = new DateRange();
        $dateRange->setStartDate($startDate);
        $dateRange->setEndDate($endDate);
        $request->setDateRanges([$dateRange]);

        // Dimensions: date, eventName
        $dateDim = new Dimension();
        $dateDim->setName('date');
        $eventDim = new Dimension();
        $eventDim->setName('eventName');
        $request->setDimensions([$dateDim, $eventDim]);

        // Metric: eventCount
        $metric = new Metric();
        $metric->setName('eventCount');
        $request->setMetrics([$metric]);

        // Filter to only our funnel events
        $inList = new InListFilter();
        $inList->setValues(['view_item', 'add_to_cart', 'begin_checkout', 'purchase']);

        $filter = new Filter();
        $filter->setFieldName('eventName');
        $filter->setInListFilter($inList);

        $filterExpr = new FilterExpression();
        $filterExpr->setFilter($filter);
        $request->setDimensionFilter($filterExpr);

        $response = $service->properties->runReport("properties/{$propertyId}", $request);

        $byDate = [];

        foreach ($response->getRows() ?? [] as $row) {
            $dims = $row->getDimensionValues();
            $mets = $row->getMetricValues();

            $rawDate   = $dims[0]->getValue();
            $eventName = $dims[1]->getValue();
            $count     = (int) $mets[0]->getValue();

            $dateKey = substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2);

            if (! isset($byDate[$dateKey])) {
                $byDate[$dateKey] = [
                    'view_item'      => 0,
                    'add_to_cart'    => 0,
                    'begin_checkout' => 0,
                    'purchase'       => 0,
                ];
            }

            if (isset($byDate[$dateKey][$eventName])) {
                $byDate[$dateKey][$eventName] += $count;
            }
        }

        return $byDate;
    }

    /**
     * Merge funnel event counts into the metadata_json of existing commerce_metrics rows.
     */
    private function storeFunnelMetrics(array $byDate): void
    {
        foreach ($byDate as $dateKey => $events) {
            $metric = $this->integration->client->commerceMetrics()
                ->where('date', $dateKey)
                ->where('source', 'ga4')
                ->first();

            if ($metric) {
                $meta = $metric->metadata_json ?? [];
                $meta['funnel'] = $events;
                $metric->update(['metadata_json' => $meta]);
            }
        }
    }

    /**
     * Normalize GA4 channel group names into short keys.
     */
    private function normalizeChannel(string $channel): string
    {
        return match (true) {
            str_contains($channel, 'Organic')  => 'organic',
            str_contains($channel, 'Paid')     => 'paid',
            str_contains($channel, 'Email')    => 'email',
            str_contains($channel, 'Social')   => 'social',
            str_contains($channel, 'Direct')   => 'direct',
            str_contains($channel, 'Referral') => 'referral',
            default                            => strtolower(preg_replace('/\s+/', '_', $channel)),
        };
    }

    /**
     * Handle Google API service exceptions — never expose credentials in logs.
     */
    private function handleGoogleException(\Google\Service\Exception $e, SyncLog $syncLog): void
    {
        $code = $e->getCode();

        $message = match (true) {
            in_array($code, [401, 403]) => 'GA4 authorization failed. Please re-authorize the Google account.',
            $code === 404               => 'GA4 property not found. Check the Property ID.',
            $code === 429               => 'GA4 API quota exceeded. Sync will retry tomorrow.',
            default                     => 'GA4 API error (HTTP ' . $code . '). Check application logs.',
        };

        Log::error('GA4Connector: Google API exception', [
            'integration_id' => $this->integration->id,
            'code'           => $code,
            'message'        => $e->getMessage(),
        ]);

        $syncLog->update([
            'status'        => SyncStatus::Failed,
            'error_message' => $message,
            'completed_at'  => now(),
        ]);
    }

    /**
     * Quick connection test — fetches sessions for yesterday only.
     */
    public function testConnection(): array
    {
        $propertyId   = $this->credentials['property_id'] ?? null;
        $refreshToken = $this->credentials['refresh_token'] ?? null;

        if (! $propertyId || ! $refreshToken) {
            return ['success' => false, 'message' => 'Missing property ID or authorization.'];
        }

        try {
            $service = $this->buildService($refreshToken);

            $request = new RunReportRequest();

            $dateRange = new DateRange();
            $dateRange->setStartDate('yesterday');
            $dateRange->setEndDate('yesterday');
            $request->setDateRanges([$dateRange]);

            $metric = new Metric();
            $metric->setName('sessions');
            $request->setMetrics([$metric]);

            $response = $service->properties->runReport("properties/{$propertyId}", $request);

            $sessions = 0;
            foreach ($response->getRows() ?? [] as $row) {
                $sessions += (int) $row->getMetricValues()[0]->getValue();
            }

            return [
                'success' => true,
                'message' => "Connection successful. Yesterday: {$sessions} sessions.",
            ];

        } catch (\Google\Service\Exception $e) {
            Log::error('GA4Connector: testConnection failed', [
                'integration_id' => $this->integration->id,
                'code'           => $e->getCode(),
                'message'        => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => match (true) {
                    $e->getCode() === 401 => 'Authorization expired. Click "Disconnect" then "Authorize Google Account" to re-authorize.',
                    $e->getCode() === 403 => 'Permission denied. The authorized Google account may not have access to this GA4 property (ID: ' . ($this->credentials['property_id'] ?? '?') . '). Check GA4 Admin → Property Access Management.',
                    $e->getCode() === 404 => 'Property not found. Check the Property ID is correct.',
                    default               => 'API error (HTTP ' . $e->getCode() . '): ' . $e->getMessage(),
                },
            ];

        } catch (\RuntimeException $e) {
            Log::error('GA4Connector: testConnection runtime error', [
                'integration_id' => $this->integration->id,
                'message'        => $e->getMessage(),
            ]);

            // Detect scope-related errors
            if (str_contains($e->getMessage(), 'SCOPE_MISSING')) {
                return [
                    'success' => false,
                    'message' => 'The Google account is connected but missing Analytics permissions. Click "Disconnect" below, then click "Authorize Google Account" again. On the Google consent screen, make sure to allow Analytics access.',
                ];
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];

        } catch (\Exception $e) {
            Log::error('GA4Connector: testConnection generic error', [
                'integration_id' => $this->integration->id,
                'class'          => get_class($e),
                'message'        => $e->getMessage(),
                'file'           => $e->getFile() . ':' . $e->getLine(),
            ]);

            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }
}
