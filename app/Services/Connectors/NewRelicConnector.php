<?php

namespace App\Services\Connectors;

use App\Enums\SyncStatus;
use App\Models\Integration;
use App\Models\PerformanceMetric;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NewRelicConnector
 *
 * Integrates with the New Relic REST API v2 to pull application
 * performance metrics (response time, throughput, errors, Apdex)
 * and store them as PerformanceMetric records.
 *
 * Also supports NerdGraph/NRQL for querying application logs
 * (error, warning, critical) to support AI-driven investigations.
 *
 * API docs: https://docs.newrelic.com/docs/apis/rest-api-v2/
 * NerdGraph docs: https://docs.newrelic.com/docs/apis/nerdgraph/
 */
class NewRelicConnector
{
    private const BASE_URL      = 'https://api.newrelic.com/v2';
    private const NERDGRAPH_URL = 'https://api.newrelic.com/graphql';

    private Integration $integration;
    private array $credentials;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
        $this->credentials = $integration->credentials_json ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run a full sync for this integration.
     *
     * Fetches summarised metrics for yesterday and stores them
     * as a single PerformanceMetric row.
     */
    public function sync(SyncLog $syncLog, int $numOfDays = 1): void
    {
        $apiKey        = $this->credentials['api_key'] ?? null;
        $applicationId = $this->credentials['application_id'] ?? null;

        if (! $apiKey || ! $applicationId) {
            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => 'Missing New Relic api_key or application_id. Please complete the integration setup.',
                'completed_at'  => now(),
            ]);
            return;
        }

        try {
            $recordsProcessed = 0;

            for ($d = $numOfDays; $d >= 1; $d--) {
                $date = Carbon::today()->subDays($d);
                $from = $date->copy()->startOfDay()->toIso8601String();
                $to   = $date->copy()->endOfDay()->toIso8601String();

                $raw    = $this->fetchMetricsData($applicationId, $apiKey, $from, $to);
                $parsed = $this->parseMetrics($raw);

                $this->storeMetrics($parsed, $date->toDateString());
                $recordsProcessed++;
            }

            Log::info('NewRelicConnector: sync complete', [
                'integration_id' => $this->integration->id,
                'num_of_days'    => $numOfDays,
                'records'        => $recordsProcessed,
            ]);

            $syncLog->update([
                'status'            => SyncStatus::Success,
                'records_processed' => $recordsProcessed,
                'completed_at'      => now(),
            ]);

        } catch (\Exception $e) {
            $safe = $this->sanitiseError($e->getMessage(), $apiKey ?? '');

            Log::error('NewRelicConnector: sync error', [
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
     * Fetch New Relic metrics for an arbitrary date range.
     *
     * Unlike sync(), this method does NOT create or update a SyncLog.
     *
     * @return int Number of records stored (0 or 1)
     */
    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        try {
            $apiKey        = $this->credentials['api_key'] ?? null;
            $applicationId = $this->credentials['application_id'] ?? null;

            if (! $apiKey || ! $applicationId) {
                Log::warning('NewRelicConnector::fetchForDateRange — missing credentials', [
                    'integration_id' => $this->integration->id,
                ]);
                return 0;
            }

            $raw    = $this->fetchMetricsData(
                $applicationId,
                $apiKey,
                $from->startOfDay()->toIso8601String(),
                $to->endOfDay()->toIso8601String(),
            );
            $parsed = $this->parseMetrics($raw);

            $this->storeMetrics($parsed, $from->toDateString());

            return 1;

        } catch (\Exception $e) {
            $safe = $this->sanitiseError(
                $e->getMessage(),
                $this->credentials['api_key'] ?? ''
            );

            Log::error('NewRelicConnector::fetchForDateRange failed', [
                'integration_id' => $this->integration->id,
                'from'           => $from->toDateString(),
                'to'             => $to->toDateString(),
                'message'        => $safe,
            ]);

            return 0;
        }
    }

    /**
     * Quick connection test — fetches the application details.
     */
    public function testConnection(): array
    {
        $apiKey        = $this->credentials['api_key'] ?? null;
        $applicationId = $this->credentials['application_id'] ?? null;

        if (! $apiKey || ! $applicationId) {
            return ['success' => false, 'message' => 'Missing API key or Application ID.'];
        }

        try {
            $response = Http::withHeaders(['X-Api-Key' => $apiKey])
                ->timeout(15)
                ->get(self::BASE_URL . "/applications/{$applicationId}.json");

            if ($response->successful()) {
                $app       = $response->json('application') ?? [];
                $appName   = $app['name'] ?? 'Unknown';
                $reporting = ($app['reporting'] ?? false) ? 'reporting' : 'not reporting';

                return [
                    'success' => true,
                    'message' => "Connected — {$appName}, status: {$reporting}",
                ];
            }

            $status = $response->status();

            return [
                'success' => false,
                'message' => match (true) {
                    in_array($status, [401, 403]) => 'Invalid API key.',
                    $status === 404               => 'Application not found. Check the Application ID.',
                    default                       => "New Relic API error (HTTP {$status}).",
                },
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $this->sanitiseError($e->getMessage(), $apiKey ?? ''),
            ];
        }
    }

    /**
     * Fetch error/warning logs via NerdGraph NRQL for the investigation window.
     *
     * Returns structured log entries with timestamp, level, message, and error class.
     * Limited to 100 most recent error/warning/critical logs.
     *
     * @return array{logs: array, error_summary: array, total_errors: int}
     */
    public function fetchLogs(Carbon $from, Carbon $to, int $limit = 100): array
    {
        $apiKey = $this->credentials['api_key'] ?? null;
        if (! $apiKey) {
            return ['logs' => [], 'error_summary' => [], 'total_errors' => 0];
        }

        try {
            $accountId = $this->resolveAccountId($apiKey);
            if (! $accountId) {
                Log::warning('NewRelicConnector::fetchLogs — could not resolve account ID', [
                    'integration_id' => $this->integration->id,
                ]);
                return ['logs' => [], 'error_summary' => [], 'total_errors' => 0];
            }

            $fromTs = $from->startOfDay()->timestamp;
            $toTs   = $to->endOfDay()->timestamp;

            // Query 1: Recent error/warning log entries
            $logsNrql = "SELECT timestamp, level, message, error.class, error.message, entity.name "
                . "FROM Log "
                . "WHERE level IN ('ERROR', 'WARN', 'CRITICAL', 'FATAL', 'error', 'warn', 'critical', 'fatal') "
                . "SINCE {$fromTs} UNTIL {$toTs} "
                . "LIMIT {$limit}";

            $logResults = $this->nrqlQuery($apiKey, $accountId, $logsNrql);

            // Query 2: Error count summary by level
            $summaryNrql = "SELECT count(*) FROM Log "
                . "WHERE level IN ('ERROR', 'WARN', 'CRITICAL', 'FATAL', 'error', 'warn', 'critical', 'fatal') "
                . "FACET level "
                . "SINCE {$fromTs} UNTIL {$toTs}";

            $summaryResults = $this->nrqlQuery($apiKey, $accountId, $summaryNrql);

            // Query 3: Error count by error class (top 10)
            $classNrql = "SELECT count(*) FROM Log "
                . "WHERE level IN ('ERROR', 'CRITICAL', 'FATAL', 'error', 'critical', 'fatal') "
                . "FACET error.class "
                . "SINCE {$fromTs} UNTIL {$toTs} "
                . "LIMIT 10";

            $classResults = $this->nrqlQuery($apiKey, $accountId, $classNrql);

            // Parse logs
            $logs = collect($logResults)
                ->map(fn ($row) => array_filter([
                    'timestamp'    => isset($row['timestamp']) ? Carbon::createFromTimestampMs($row['timestamp'])->format('Y-m-d H:i:s') : null,
                    'level'        => $row['level'] ?? null,
                    'message'      => $this->truncateLogMessage($row['message'] ?? $row['error.message'] ?? ''),
                    'error_class'  => $row['error.class'] ?? null,
                    'entity'       => $row['entity.name'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''))
                ->values()
                ->toArray();

            // Parse error summary
            $errorSummary = collect($summaryResults)
                ->map(fn ($row) => [
                    'level' => $row['level'] ?? $row['facet'] ?? 'unknown',
                    'count' => $row['count'] ?? 0,
                ])
                ->toArray();

            // Parse error classes
            $errorClasses = collect($classResults)
                ->map(fn ($row) => [
                    'error_class' => $row['error.class'] ?? $row['facet'] ?? 'unknown',
                    'count'       => $row['count'] ?? 0,
                ])
                ->toArray();

            $totalErrors = collect($errorSummary)->sum('count');

            Log::info('NewRelicConnector::fetchLogs success', [
                'integration_id' => $this->integration->id,
                'logs_found'     => count($logs),
                'total_errors'   => $totalErrors,
            ]);

            return [
                'logs'           => $logs,
                'error_summary'  => $errorSummary,
                'error_classes'  => $errorClasses,
                'total_errors'   => $totalErrors,
            ];

        } catch (\Exception $e) {
            Log::warning('NewRelicConnector::fetchLogs failed', [
                'integration_id' => $this->integration->id,
                'error'          => $this->sanitiseError($e->getMessage(), $apiKey),
            ]);
            return ['logs' => [], 'error_summary' => [], 'total_errors' => 0];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: NerdGraph / NRQL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Execute an NRQL query via NerdGraph.
     */
    private function nrqlQuery(string $apiKey, int $accountId, string $nrql): array
    {
        $query = <<<'GRAPHQL'
{
  actor {
    nrql(accounts: %d, query: "%s") {
      results
    }
  }
}
GRAPHQL;

        $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'API-Key'      => $apiKey,
            ])
            ->timeout(30)
            ->post(self::NERDGRAPH_URL, [
                'query' => sprintf($query, $accountId, addslashes($nrql)),
            ]);

        $response->throw();

        return $response->json('data.actor.nrql.results') ?? [];
    }

    /**
     * Discover the New Relic account ID via NerdGraph.
     * Caches the result in credentials_json to avoid repeated lookups.
     */
    private function resolveAccountId(string $apiKey): ?int
    {
        // Check if already stored
        if (! empty($this->credentials['account_id'])) {
            return (int) $this->credentials['account_id'];
        }

        $query = '{ actor { accounts { id name } } }';

        $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'API-Key'      => $apiKey,
            ])
            ->timeout(15)
            ->post(self::NERDGRAPH_URL, ['query' => $query]);

        $accounts = $response->json('data.actor.accounts') ?? [];

        if (empty($accounts)) {
            return null;
        }

        $accountId = (int) $accounts[0]['id'];

        // Cache the account ID in credentials for future calls
        $creds = $this->integration->credentials_json ?? [];
        $creds['account_id'] = $accountId;
        $this->integration->update(['credentials_json' => $creds]);
        $this->credentials['account_id'] = $accountId;

        Log::info('NewRelicConnector: discovered and cached account ID', [
            'integration_id' => $this->integration->id,
            'account_id'     => $accountId,
            'account_name'   => $accounts[0]['name'] ?? 'unknown',
        ]);

        return $accountId;
    }

    /**
     * Truncate log messages to avoid sending huge strings to the AI.
     */
    private function truncateLogMessage(string $message, int $maxLength = 300): string
    {
        $message = trim($message);
        if (strlen($message) <= $maxLength) {
            return $message;
        }
        return substr($message, 0, $maxLength) . '…';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: REST API v2 call
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch summarised metric data from the New Relic REST API v2.
     *
     * @param  string  $appId   New Relic application ID
     * @param  string  $apiKey  New Relic User API key
     * @param  string  $from    ISO 8601 start timestamp
     * @param  string  $to      ISO 8601 end timestamp
     * @return array   Raw JSON response body
     */
    private function fetchMetricsData(string $appId, string $apiKey, string $from, string $to): array
    {
        // New Relic REST API requires repeated `names[]=` query params.
        // Laravel Http::get() serialises arrays as `names[0]=...&names[1]=...`
        // which New Relic rejects. Build the URL manually instead.
        $baseUrl = self::BASE_URL . "/applications/{$appId}/metrics/data.json";
        $query   = http_build_query(['from' => $from, 'to' => $to, 'summarize' => 'true']);

        $metricNames = ['HttpDispatcher', 'EndUser', 'Errors/all', 'EndUser/Apdex'];
        $namesQuery  = implode('&', array_map(fn ($n) => 'names[]=' . urlencode($n), $metricNames));

        $fullUrl = "{$baseUrl}?{$query}&{$namesQuery}";

        $response = Http::withHeaders(['X-Api-Key' => $apiKey])
            ->timeout(30)
            ->get($fullUrl);

        $response->throw();

        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Data processing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extract the metric values from the nested New Relic response structure.
     *
     * Response format:
     *   {"metric_data": {"metrics": [
     *       {"name": "HttpDispatcher", "timeslices": [{"values": {"call_count": 12345, "average_response_time": 0.123}}]},
     *       ...
     *   ]}}
     *
     * Returns a flat associative array ready for storage.
     */
    private function parseMetrics(array $raw): array
    {
        $metrics = $raw['metric_data']['metrics'] ?? [];

        $byName = [];
        foreach ($metrics as $metric) {
            $name   = $metric['name'] ?? '';
            $values = $metric['timeslices'][0]['values'] ?? [];
            $byName[$name] = $values;
        }

        $httpDispatcher = $byName['HttpDispatcher'] ?? [];
        $endUser        = $byName['EndUser'] ?? [];
        $errors         = $byName['Errors/all'] ?? [];
        $apdex          = $byName['EndUser/Apdex'] ?? [];

        $callCount           = (int)   ($httpDispatcher['call_count'] ?? 0);
        $serverResponseSec   = (float) ($httpDispatcher['average_response_time'] ?? 0);
        $pageLoadSec         = (float) ($endUser['average_response_time'] ?? 0);
        $errorCount          = (int)   ($errors['error_count'] ?? 0);
        $apdexScore          = (float) ($apdex['score'] ?? 0);

        $errorRate = $callCount > 0
            ? round($errorCount / $callCount, 6)
            : 0.0;

        // Convert seconds → milliseconds for storage
        $serverResponseMs = round($serverResponseSec * 1000, 2);
        $pageLoadMs       = round($pageLoadSec * 1000, 2);

        return [
            'page_load_time'       => $pageLoadMs,
            'server_response_time' => $serverResponseMs,
            'ttfb'                 => $serverResponseMs,
            'throughput'           => $callCount,
            'error_count'          => $errorCount,
            'error_rate'           => $errorRate,
            'apdex'                => $apdexScore,
        ];
    }

    /**
     * Persist parsed metrics into the performance_metrics table.
     */
    private function storeMetrics(array $parsed, string $date): void
    {
        PerformanceMetric::updateOrCreate(
            [
                'client_id' => $this->integration->client_id,
                'date'      => $date,
                'source'    => 'new_relic',
            ],
            [
                'page_load_time'       => $parsed['page_load_time'],
                'server_response_time' => $parsed['server_response_time'],
                'ttfb'                 => $parsed['ttfb'],
                'metadata_json'        => [
                    'throughput'   => $parsed['throughput'],
                    'error_count'  => $parsed['error_count'],
                    'error_rate'   => $parsed['error_rate'],
                    'apdex'        => $parsed['apdex'],
                ],
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Security
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Mask the API key in error messages to prevent credential leakage.
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
