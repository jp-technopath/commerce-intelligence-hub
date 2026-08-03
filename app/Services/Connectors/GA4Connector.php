<?php

namespace App\Services\Connectors;

use App\Enums\SyncStatus;
use App\Models\AnalyticsPurchaseEvent;
use App\Models\Integration;
use App\Models\SyncLog;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\AnalyticsData;
use Google\Service\AnalyticsData\DateRange;
use Google\Service\AnalyticsData\Dimension;
use Google\Service\AnalyticsData\Filter;
use Google\Service\AnalyticsData\FilterExpression;
use Google\Service\AnalyticsData\InListFilter;
use Google\Service\AnalyticsData\Metric;
use Google\Service\AnalyticsData\RunReportRequest;
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
            $startDate = "{$numOfDays}daysAgo";
            $endDate   = "yesterday";

            $data = $this->fetchReport($service, $propertyId, $startDate, $endDate);
            $this->storeMetrics($data);

            // Fetch transaction-level purchase events for reconciliation
            $this->fetchPurchaseTransactions($service, $propertyId, $startDate, $endDate);

            // Fetch ecommerce funnel participation events
            $funnelData = $this->fetchFunnelReport($service, $propertyId, $startDate, $endDate);
            $this->storeFunnelMetrics($funnelData);

            Log::info('GA4Connector: sync complete', [
                'integration_id' => $this->integration->id,
                'num_of_days'    => $numOfDays,
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

    private function buildService(string $refreshToken): AnalyticsData
    {
        $client = new GoogleClient();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setAccessType('offline');
        $client->setScopes([AnalyticsData::ANALYTICS_READONLY]);

        $client->setAccessToken([
            'access_token'  => 'placeholder',
            'refresh_token' => $refreshToken,
            'expires_in'    => 0,
            'created'       => 0,
        ]);

        $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($newToken['error'])) {
            throw new \RuntimeException(
                'Failed to refresh Google access token: ' . ($newToken['error_description'] ?? $newToken['error'])
            );
        }

        $grantedScope = $newToken['scope'] ?? '';
        if (! str_contains($grantedScope, 'analytics.readonly')) {
            throw new \RuntimeException(
                'SCOPE_MISSING: The authorized Google account does not have the Analytics scope.'
            );
        }

        return new AnalyticsData($client);
    }

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

        foreach ($response->getRows() ?? [] as $row) {
            $dimensions = $row->getDimensionValues();
            $metrics    = $row->getMetricValues();

            $date    = $dimensions[0]->getValue();
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
     * Fetch purchase transactions for reconciliation and store in analytics_purchase_events.
     */
    private function fetchPurchaseTransactions(AnalyticsData $service, string $propertyId, string $startDate, string $endDate): void
    {
        try {
            $request = new RunReportRequest();

            $dateRange = new DateRange();
            $dateRange->setStartDate($startDate);
            $dateRange->setEndDate($endDate);
            $request->setDateRanges([$dateRange]);

            $dateDim = new Dimension();
            $dateDim->setName('date');
            $txDim = new Dimension();
            $txDim->setName('transactionId');
            $request->setDimensions([$dateDim, $txDim]);

            $revenueMetric = new Metric();
            $revenueMetric->setName('purchaseRevenue');
            $request->setMetrics([$revenueMetric]);

            $response = $service->properties->runReport("properties/{$propertyId}", $request);

            $seenInRun = [];

            foreach ($response->getRows() ?? [] as $row) {
                $dims = $row->getDimensionValues();
                $mets = $row->getMetricValues();

                $rawDate = $dims[0]->getValue();
                $txId    = trim($dims[1]->getValue());
                $rev     = (float) $mets[0]->getValue();

                if (! $txId || $txId === '(not set)') continue;

                $dateKey = substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2);
                $isDup   = isset($seenInRun[$txId]);
                $seenInRun[$txId] = true;

                AnalyticsPurchaseEvent::updateOrCreate(
                    [
                        'client_id'      => $this->integration->client_id,
                        'integration_id' => $this->integration->id,
                        'source'         => 'ga4',
                        'transaction_id' => $txId,
                    ],
                    [
                        'event_date'       => $dateKey,
                        'event_timestamp'  => Carbon::parse($dateKey . ' 12:00:00'),
                        'tracked_revenue'  => $rev,
                        'currency'         => $this->integration->client->currency ?? 'USD',
                        'is_duplicate'     => $isDup,
                        'duplicate_reason' => $isDup ? 'multiple_events_for_same_transaction_id' : null,
                        'collected_at'     => now(),
                    ]
                );
            }
        } catch (\Exception $e) {
            Log::warning('GA4Connector: fetchPurchaseTransactions warning', [
                'integration_id' => $this->integration->id,
                'message'        => $e->getMessage(),
            ]);
        }
    }

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

    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        $propertyId   = $this->credentials['property_id'] ?? null;
        $refreshToken = $this->credentials['refresh_token'] ?? null;

        if (! $propertyId || ! $refreshToken) return 0;

        try {
            $service = $this->buildService($refreshToken);
            $fromStr = $from->format('Y-m-d');
            $toStr   = $to->format('Y-m-d');

            $data = $this->fetchReport($service, $propertyId, $fromStr, $toStr);
            $this->storeMetrics($data);
            $this->fetchPurchaseTransactions($service, $propertyId, $fromStr, $toStr);

            return count($data);
        } catch (\Exception $e) {
            Log::error('GA4Connector::fetchForDateRange failed', [
                'integration_id' => $this->integration->id,
                'message'        => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function fetchFunnelReport(AnalyticsData $service, string $propertyId, string $startDate = '30daysAgo', string $endDate = 'yesterday'): array
    {
        $request = new RunReportRequest();

        $dateRange = new DateRange();
        $dateRange->setStartDate($startDate);
        $dateRange->setEndDate($endDate);
        $request->setDateRanges([$dateRange]);

        $dateDim = new Dimension();
        $dateDim->setName('date');
        $eventDim = new Dimension();
        $eventDim->setName('eventName');
        $request->setDimensions([$dateDim, $eventDim]);

        $metric = new Metric();
        $metric->setName('activeUsers'); // Unique active users per funnel event stage
        $request->setMetrics([$metric]);

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
                $meta['funnel_type'] = 'user_participation';
                $metric->update(['metadata_json' => $meta]);
            }
        }
    }

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

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }
}
