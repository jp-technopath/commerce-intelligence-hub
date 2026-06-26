<?php

namespace App\Services\Connectors;

use App\Enums\SyncStatus;
use App\Models\BehavioralMetric;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\Metrics\FrictionScoreCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ClarityConnector
 *
 * Captures ALL available Clarity Data Export API metrics:
 *
 * Behavioral signals:
 *   - DeadClickCount, RageClickCount, QuickbackClick
 *   - ExcessiveScroll, ScriptErrorCount, ErrorClickCount
 *
 * Engagement/traffic:
 *   - Scroll Depth (avg %), Engagement Time (avg seconds)
 *   - Traffic (totalSessionCount, totalBotSessionCount,
 *             distantUserCount, PagesPerSessionPercentage)
 *
 * Strategy:
 *   The API is limited to 1–3 day lookback. We cannot pull 30 days at once.
 *   Instead, we sync daily and accumulate rows over time — each sync creates
 *   a record for today's date. After 30 days of daily syncs, we have 30 days
 *   of Clarity data matching the GA4/Adobe Commerce scope.
 *
 * Rate limit: max 10 API requests per project per day.
 */
class ClarityConnector
{
    private const ENDPOINT = 'project-live-insights';

    /**
     * Clarity metric names → behavioral_metrics columns (count-based).
     */
    private const METRIC_MAP = [
        'DeadClickCount'   => 'dead_clicks',
        'RageClickCount'   => 'rage_clicks',
        'QuickbackClick'   => 'quick_backs',
        'ExcessiveScroll'  => 'excessive_scrolling',
        'ScriptErrorCount' => 'script_errors',
        'ErrorClickCount'  => 'error_clicks',
    ];

    private string $baseUrl;
    private int    $dailyLimit;

    public function __construct(private readonly Integration $integration)
    {
        $config           = config('intelligence.clarity');
        $this->baseUrl    = rtrim($config['base_url'], '/');
        $this->dailyLimit = $config['daily_request_limit'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    public function sync(SyncLog $syncLog, int $numOfDays = 1): void
    {
        $creds     = $this->integration->credentials_json ?? [];
        $token     = $creds['bearer_token'] ?? null;
        $projectId = $creds['project_id']   ?? null;

        if (! $token || ! $projectId) {
            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => 'Missing Clarity bearer_token or project_id.',
                'completed_at'  => now(),
            ]);
            return;
        }

        // Clarity API accepts numOfDays 1, 2, or 3 only
        $numOfDays = min(max(1, $numOfDays), 3);

        if (! $this->checkRateLimit(1)) {
            $syncLog->update([
                'status'        => SyncStatus::Skipped,
                'error_message' => "Clarity daily API request limit ({$this->dailyLimit}/day) reached. Sync skipped.",
                'completed_at'  => now(),
            ]);
            return;
        }

        try {
            // Single call returns all metrics
            $raw = $this->fetchMetrics($projectId, $token, $numOfDays);
            $this->incrementRateLimit();

            $parsed        = $this->parseResponse($raw);
            $aggregated    = $this->aggregateMetrics($parsed);
            $frictionScore = FrictionScoreCalculator::calculate($aggregated);

            // Store one record per day — accumulates over time for 30-day coverage
            BehavioralMetric::updateOrCreate(
                [
                    'client_id' => $this->integration->client_id,
                    'date'      => now()->startOfDay()->toDateTimeString(),
                ],
                array_merge($aggregated, [
                    'friction_score' => $frictionScore,
                    'metadata_json'  => $this->buildMetadata($parsed, $numOfDays),
                ])
            );

            $remaining = $this->getRateLimitRemaining();

            $syncLog->update([
                'status'            => SyncStatus::Success,
                'records_processed' => count($parsed),
                'completed_at'      => now(),
                'metadata_json'     => [
                    'requested_num_of_days' => $numOfDays,
                    'metrics_returned'      => count($parsed),
                    'rate_limit_remaining'  => $remaining,
                    'friction_score'        => $frictionScore,
                    'sessions'              => $aggregated['traffic'],
                    'bot_sessions'          => $aggregated['bot_sessions'] ?? 0,
                    'unique_users'          => $aggregated['unique_users'] ?? 0,
                    'pages_per_session'     => $aggregated['pages_per_session'] ?? 0,
                    'scroll_depth'          => $aggregated['scroll_depth'],
                    'engagement_time'       => $aggregated['engagement_time'],
                ],
            ]);

            Log::info('ClarityConnector: sync complete', [
                'integration_id'   => $this->integration->id,
                'metrics_returned' => count($parsed),
                'sessions'         => $aggregated['traffic'],
                'bot_sessions'     => $aggregated['bot_sessions'] ?? 0,
                'friction_score'   => $frictionScore,
                'scroll_depth'     => $aggregated['scroll_depth'],
                'engagement_time'  => $aggregated['engagement_time'],
                'rate_remaining'   => $remaining,
            ]);

        } catch (\Exception $e) {
            $safe = $this->sanitiseErrorMessage($e->getMessage(), $token ?? '');

            Log::error('ClarityConnector: sync error', [
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
        $creds     = $this->integration->credentials_json ?? [];
        $token     = $creds['bearer_token'] ?? null;
        $projectId = $creds['project_id']   ?? null;

        if (! $token || ! $projectId) {
            return ['success' => false, 'message' => 'Missing bearer token or project ID.'];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get($this->baseUrl . '/' . self::ENDPOINT, [
                    'projectId' => $projectId,
                    'numOfDays' => 1,
                ]);

            if ($response->successful()) {
                $parsed   = $this->parseResponse($response->json());
                $sessions = $this->extractSessions($parsed);
                $metrics  = array_keys($parsed);
                return [
                    'success' => true,
                    'message' => "Connected ✓ — {$sessions} sessions, " . count($parsed) . " metrics (" . implode(', ', $metrics) . ").",
                ];
            }

            $status = $response->status();
            return [
                'success' => false,
                'message' => match ($status) {
                    401, 403 => 'Authorization failed. Check your Clarity Bearer token.',
                    404      => 'Project not found. Check the Clarity Project ID.',
                    429      => 'Clarity rate limit reached (10/day). Try again tomorrow.',
                    default  => "Clarity API error (HTTP {$status}).",
                },
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $this->sanitiseErrorMessage($e->getMessage(), $token ?? ''),
            ];
        }
    }

    /**
     * Fetch Clarity metrics for an arbitrary date range.
     *
     * Because the Clarity API only supports a 1–3 day lookback from today,
     * this method calculates how many days back `$from` is and, if within
     * range, fetches and stores the data.  For ranges older than 3 days
     * it returns 0 immediately — Clarity cannot serve historical data.
     *
     * Unlike sync(), this method does NOT create or update a SyncLog.
     *
     * @return int Number of records stored (0 or 1)
     */
    public function fetchForDateRange(Carbon $from, Carbon $to): int
    {
        try {
            $creds     = $this->integration->credentials_json ?? [];
            $token     = $creds['bearer_token'] ?? null;
            $projectId = $creds['project_id']   ?? null;

            if (! $token || ! $projectId) {
                Log::warning('ClarityConnector::fetchForDateRange: Missing bearer_token or project_id.', [
                    'integration_id' => $this->integration->id,
                ]);
                return 0;
            }

            // How many days back is $from from today?
            $daysBack = (int) $from->startOfDay()->diffInDays(now()->startOfDay());

            // Clarity only supports numOfDays 1, 2, or 3 (lookback from today)
            if ($daysBack > 2) {
                Log::info('ClarityConnector::fetchForDateRange: Date range too old for Clarity API.', [
                    'integration_id' => $this->integration->id,
                    'from'           => $from->toDateString(),
                    'to'             => $to->toDateString(),
                    'days_back'      => $daysBack,
                ]);
                return 0;
            }

            // Map days-back to the numOfDays parameter:
            //   daysBack 0 (today)     → numOfDays 1
            //   daysBack 1 (yesterday) → numOfDays 2
            //   daysBack 2             → numOfDays 3
            $numOfDays = $daysBack + 1;

            if (! $this->checkRateLimit(1)) {
                Log::warning('ClarityConnector::fetchForDateRange: Daily rate limit reached.', [
                    'integration_id' => $this->integration->id,
                ]);
                return 0;
            }

            $raw = $this->fetchMetrics($projectId, $token, $numOfDays);
            $this->incrementRateLimit();

            $parsed        = $this->parseResponse($raw);
            $aggregated    = $this->aggregateMetrics($parsed);
            $frictionScore = FrictionScoreCalculator::calculate($aggregated);

            BehavioralMetric::updateOrCreate(
                [
                    'client_id' => $this->integration->client_id,
                    'date'      => $from->startOfDay()->toDateTimeString(),
                ],
                array_merge($aggregated, [
                    'friction_score' => $frictionScore,
                    'metadata_json'  => $this->buildMetadata($parsed, $numOfDays),
                ])
            );

            Log::info('ClarityConnector::fetchForDateRange: stored metric.', [
                'integration_id' => $this->integration->id,
                'from'           => $from->toDateString(),
                'to'             => $to->toDateString(),
                'num_of_days'    => $numOfDays,
                'friction_score' => $frictionScore,
            ]);

            return 1;

        } catch (\Exception $e) {
            $creds = $this->integration->credentials_json ?? [];
            $safe  = $this->sanitiseErrorMessage($e->getMessage(), $creds['bearer_token'] ?? '');

            Log::error('ClarityConnector::fetchForDateRange error', [
                'integration_id' => $this->integration->id,
                'message'        => $safe,
            ]);

            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: API call
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Single call — returns all metrics for the period.
     * Response is a root-level JSON array.
     */
    private function fetchMetrics(string $projectId, string $token, int $numOfDays): array
    {
        $response = Http::withToken($token)
            ->timeout(30)
            ->get($this->baseUrl . '/' . self::ENDPOINT, [
                'projectId' => $projectId,
                'numOfDays' => $numOfDays,
            ]);

        if ($response->status() === 429) {
            throw new \RuntimeException('Clarity daily rate limit reached (10/day). Sync will resume tomorrow.');
        }

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Parse the root-level array response into a keyed array.
     *
     * Each API item has:
     *   - metricName: string (e.g. "Traffic", "DeadClickCount", "ScrollDepth")
     *   - information: array of objects with varying keys per metric
     *
     * We extract ALL metrics including Traffic details and engagement.
     *
     * Returns: [
     *   'DeadClickCount'  => ['sessions' => 203, 'count' => 20, 'pct' => 9.85, 'pages' => 0],
     *   'Traffic'         => ['sessions' => 203, 'bot_sessions' => 50, 'users' => 180, 'pages_per_session' => 2.1],
     *   'ScrollDepth'     => ['sessions' => 203, 'avg_value' => 52.3],
     *   'EngagementTime'  => ['sessions' => 203, 'avg_value' => 45.7],
     *   ...
     * ]
     */
    private function parseResponse(array $raw): array
    {
        if (! is_array($raw) || empty($raw)) {
            return [];
        }

        $parsed = [];

        foreach ($raw as $item) {
            $metricName = $item['metricName'] ?? null;
            $infoList   = $item['information'] ?? [];

            if (! $metricName || empty($infoList)) {
                continue;
            }

            // For non-dimensioned requests, first info row is the aggregate
            $info = $infoList[0] ?? [];

            if ($metricName === 'Traffic') {
                // Actual fields: totalSessionCount, totalBotSessionCount,
                //                 distinctUserCount, pagesPerSessionPercentage
                $parsed['Traffic'] = [
                    'sessions'          => (int)   ($info['totalSessionCount']         ?? $info['sessionsCount'] ?? 0),
                    'bot_sessions'      => (int)   ($info['totalBotSessionCount']      ?? 0),
                    'users'             => (int)   ($info['distinctUserCount']         ?? $info['distantUserCount'] ?? 0),
                    'pages_per_session' => round((float) ($info['pagesPerSessionPercentage'] ?? $info['PagesPerSessionPercentage'] ?? 0), 2),
                ];
            } elseif (in_array($metricName, ['ScrollDepth', 'Scroll Depth'])) {
                // Actual field: averageScrollDepth (percentage, e.g. 64.38)
                $parsed['ScrollDepth'] = [
                    'sessions'  => (int)   ($info['sessionsCount'] ?? 0),
                    'avg_value' => (float) ($info['averageScrollDepth'] ?? $info['subTotal'] ?? 0),
                ];
            } elseif (in_array($metricName, ['EngagementTime', 'Engagement Time'])) {
                // Actual fields: totalTime (total seconds), activeTime (active seconds)
                $totalTime  = (float) ($info['totalTime']  ?? 0);
                $activeTime = (float) ($info['activeTime'] ?? 0);
                $parsed['EngagementTime'] = [
                    'sessions'    => (int) ($info['sessionsCount'] ?? 0),
                    'total_time'  => $totalTime,
                    'active_time' => $activeTime,
                    'avg_value'   => $activeTime,  // Use active time as the engagement metric
                ];
            } else {
                // Standard count-based metric (DeadClickCount, RageClickCount, etc.)
                // Also covers Browser, Device, OS, Country, PageTitle, ReferrerUrl, PopularPages
                $parsed[$metricName] = [
                    'sessions' => (int)   ($info['sessionsCount']               ?? 0),
                    'count'    => (int)   ($info['subTotal']                    ?? 0),
                    'pct'      => (float) ($info['sessionsWithMetricPercentage'] ?? 0.0),
                    'pages'    => (int)   ($info['pagesViews']                  ?? 0),
                    'name'     =>          $info['name']                        ?? null,
                    'url'      =>          $info['url']                         ?? null,
                ];
            }
        }

        return $parsed;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Data processing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Map Clarity metric names → behavioral_metrics column names.
     * Now includes scroll_depth, engagement_time, and traffic sub-fields.
     */
    private function aggregateMetrics(array $parsed): array
    {
        $traffic = $parsed['Traffic'] ?? [];

        $totals = [
            'rage_clicks'         => 0,
            'dead_clicks'         => 0,
            'quick_backs'         => 0,
            'excessive_scrolling' => 0,
            'script_errors'       => 0,
            'error_clicks'        => 0,
            'scroll_depth'        => (float) ($parsed['ScrollDepth']['avg_value']     ?? 0),
            'engagement_time'     => (float) ($parsed['EngagementTime']['avg_value']  ?? 0),
            'traffic'             => (int)   ($traffic['sessions']                    ?? $this->extractSessions($parsed)),
        ];

        // Map count-based behavioral metrics
        foreach (self::METRIC_MAP as $clarityName => $column) {
            if (isset($parsed[$clarityName])) {
                $totals[$column] = $parsed[$clarityName]['count'];
            }
        }

        return $totals;
    }

    private function extractSessions(array $parsed): int
    {
        // Prefer Traffic metric, fallback to any metric's sessionsCount
        if (isset($parsed['Traffic']['sessions']) && $parsed['Traffic']['sessions'] > 0) {
            return $parsed['Traffic']['sessions'];
        }

        foreach ($parsed as $data) {
            if (isset($data['sessions']) && $data['sessions'] > 0) {
                return $data['sessions'];
            }
        }
        return 0;
    }

    /**
     * Build rich metadata including ALL parsed metrics + traffic details.
     */
    private function buildMetadata(array $parsed, int $numOfDays): array
    {
        $traffic = $parsed['Traffic'] ?? [];

        // Context metrics with name/url fields (Browser, Device, OS, etc.)
        $contextMetrics = ['Browser', 'Device', 'OS', 'Country', 'PageTitle', 'ReferrerUrl', 'PopularPages'];

        $metricsDetail = [];
        foreach ($parsed as $metric => $data) {
            if ($metric === 'Traffic') {
                $metricsDetail[$metric] = $data;
            } elseif ($metric === 'ScrollDepth') {
                $metricsDetail[$metric] = [
                    'avg_value' => $data['avg_value'] ?? 0,
                ];
            } elseif ($metric === 'EngagementTime') {
                $metricsDetail[$metric] = [
                    'active_time' => $data['active_time'] ?? 0,
                    'total_time'  => $data['total_time']  ?? 0,
                ];
            } elseif (in_array($metric, $contextMetrics)) {
                // Dimension-like metrics — top value with session count
                $entry = ['sessions' => $data['sessions'] ?? 0];
                if (! empty($data['name'])) $entry['name'] = $data['name'];
                if (! empty($data['url']))  $entry['url']  = $data['url'];
                $metricsDetail[$metric] = $entry;
            } else {
                // Standard count-based behavioral metrics
                $metricsDetail[$metric] = [
                    'count' => $data['count'] ?? 0,
                    'pct'   => $data['pct'] ?? 0,
                    'pages' => $data['pages'] ?? 0,
                ];
            }
        }

        return [
            'num_of_days'      => $numOfDays,
            'sessions'         => $traffic['sessions']          ?? $this->extractSessions($parsed),
            'bot_sessions'     => $traffic['bot_sessions']      ?? 0,
            'unique_users'     => $traffic['users']             ?? 0,
            'pages_per_session' => $traffic['pages_per_session'] ?? 0,
            'metrics'          => $metricsDetail,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Rate limiting
    // ─────────────────────────────────────────────────────────────────────────

    private function checkRateLimit(int $requestsNeeded = 1): bool
    {
        $used = (int) Cache::get($this->rateLimitKey(), 0);
        return ($used + $requestsNeeded) <= $this->dailyLimit;
    }

    private function incrementRateLimit(): void
    {
        $key = $this->rateLimitKey();
        $ttl = (int) now()->endOfDay()->diffInSeconds(now()) + 60;
        Cache::add($key, 0, $ttl);
        Cache::increment($key);
    }

    private function getRateLimitRemaining(): int
    {
        return max(0, $this->dailyLimit - (int) Cache::get($this->rateLimitKey(), 0));
    }

    private function rateLimitKey(): string
    {
        return 'clarity_requests:' . $this->integration->id . ':' . now()->toDateString();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: Security
    // ─────────────────────────────────────────────────────────────────────────

    private function sanitiseErrorMessage(string $message, string $token): string
    {
        if (strlen($token) > 8) {
            $masked  = substr($token, 0, 4) . str_repeat('•', max(4, strlen($token) - 8)) . substr($token, -4);
            $message = str_replace($token, $masked, $message);
        }
        return $message;
    }
}
