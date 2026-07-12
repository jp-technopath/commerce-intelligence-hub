<?php

namespace App\Services\Intelligence;

use App\Models\BehavioralMetric;
use App\Models\CommerceMetric;
use App\Models\Deployment;
use App\Models\EmailMarketingMetric;
use App\Models\Finding;
use App\Models\Integration;
use App\Models\IntelligenceMemory;
use App\Models\PerformanceMetric;
use App\Services\Connectors\AdobeCommerceConnector;
use App\Services\Connectors\ClarityConnector;
use App\Services\Connectors\GA4Connector;
use App\Services\Connectors\KlaviyoConnector;
use App\Services\Connectors\NewRelicConnector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * DataContextGatherer
 *
 * Shared data-gathering service used by both AIAnalyst and AIInvestigator.
 * Detects data gaps, fetches missing data from integration connectors,
 * and queries all local metric tables to build a complete context window
 * around a finding's detection date.
 */
class DataContextGatherer
{
    private int $lookbackDays;
    private int $lookaheadDays;

    public function __construct(int $lookbackDays = 14, int $lookaheadDays = 7)
    {
        $this->lookbackDays  = $lookbackDays;
        $this->lookaheadDays = $lookaheadDays;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Gather full context for a finding: fill data gaps then query all sources.
     *
     * @return array{commerce: array, behavioral: array, performance: array, email_marketing: array, deployments: array, recommendations: array, notes: array, similar_past: array, data_sources: array}
     */
    public function gatherContext(Finding $finding): array
    {
        $detectedAt = $finding->detected_at ?? $finding->created_at;
        $clientId   = $finding->client_id;

        $rangeStart = $detectedAt->copy()->subDays($this->lookbackDays)->startOfDay();
        $rangeEnd   = $detectedAt->copy()->addDays($this->lookaheadDays)->endOfDay();

        // ── On-demand data fetching: fill gaps before querying ───────────
        $gaps    = $this->detectDataGaps($clientId, $rangeStart, $rangeEnd);
        $hasGaps = collect($gaps)->flatten()->isNotEmpty();
        $fetchedSources = [];

        if ($hasGaps) {
            Log::info('DataContextGatherer: data gaps detected, fetching missing data', [
                'client_id'      => $clientId,
                'ga4_gaps'       => count($gaps['ga4']),
                'adobe_gaps'     => count($gaps['adobe_commerce']),
                'clarity_gaps'   => count($gaps['clarity']),
                'new_relic_gaps' => count($gaps['new_relic']),
                'klaviyo_gaps'   => count($gaps['klaviyo']),
            ]);
            $fetchedSources = $this->fetchMissingData($clientId, $gaps, $rangeStart, $rangeEnd);
        }

        // ── Query all local data ─────────────────────────────────────────
        $context = [
            'commerce'        => $this->queryCommerceData($clientId, $rangeStart, $rangeEnd),
            'behavioral'      => $this->queryBehavioralData($clientId, $rangeStart, $rangeEnd),
            'performance'     => $this->queryPerformanceData($clientId, $rangeStart, $rangeEnd),
            'email_marketing' => $this->queryEmailData($clientId, $rangeStart, $rangeEnd),
            'deployments'     => $this->queryDeployments($clientId, $rangeStart, $rangeEnd),
            'server_logs'     => $this->queryNewRelicLogs($clientId, $rangeStart, $rangeEnd),
            'recommendations' => $this->queryRecommendations($finding),
            'notes'           => $this->queryNotes($finding),
            'similar_past'    => $this->querySimilarPatterns($finding),
            'data_sources'    => $this->identifyDataSources($clientId, $fetchedSources),
        ];

        return $context;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data queries
    // ─────────────────────────────────────────────────────────────────────────

    private function queryCommerceData(int $clientId, Carbon $from, Carbon $to): array
    {
        return CommerceMetric::where('client_id', $clientId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->groupBy('source')
            ->map(fn ($rows) => $rows->map(fn ($r) => array_filter([
                'date'             => $r->date->format('Y-m-d'),
                'sessions'         => $r->sessions,
                'revenue'          => round($r->revenue, 2),
                'orders'           => $r->orders,
                'conversion_rate'  => round($r->conversion_rate, 2),
                'aov'              => round($r->aov, 2),
                'new_customers'    => $r->new_customers,
                'items_sold'       => $r->items_sold,
                'source_breakdown' => $r->source_breakdown_json,
                'device_breakdown' => $r->device_breakdown_json,
                'metadata'         => $r->metadata_json,
            ], fn ($v) => $v !== null && $v !== [] && $v !== ''))->toArray())
            ->toArray();
    }

    private function queryBehavioralData(int $clientId, Carbon $from, Carbon $to): array
    {
        return BehavioralMetric::where('client_id', $clientId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'            => $r->date->format('Y-m-d'),
                'traffic'         => $r->traffic,
                'rage_clicks'     => $r->rage_clicks,
                'dead_clicks'     => $r->dead_clicks,
                'quick_backs'     => $r->quick_backs,
                'script_errors'   => $r->script_errors,
                'error_clicks'    => $r->error_clicks,
                'scroll_depth'    => round($r->scroll_depth, 1),
                'engagement_time' => round($r->engagement_time, 1),
                'friction_score'  => round($r->friction_score, 1),
            ])
            ->toArray();
    }

    private function queryPerformanceData(int $clientId, Carbon $from, Carbon $to): array
    {
        return PerformanceMetric::where('client_id', $clientId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->groupBy('source')
            ->map(fn ($rows) => $rows->map(fn ($r) => array_filter([
                'date'                 => $r->date->format('Y-m-d'),
                'page_load_time'       => $r->page_load_time,
                'server_response_time' => $r->server_response_time,
                'ttfb'                 => $r->ttfb,
                'lcp'                  => $r->lcp,
                'cls'                  => $r->cls,
                'inp'                  => $r->inp,
                'metadata'             => $r->metadata_json,
            ], fn ($v) => $v !== null && $v !== [] && $v !== ''))->toArray())
            ->toArray();
    }

    private function queryEmailData(int $clientId, Carbon $from, Carbon $to): array
    {
        return EmailMarketingMetric::where('client_id', $clientId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->groupBy('type')
            ->map(fn ($rows) => $rows->map(fn ($r) => array_filter([
                'date'          => $r->date->format('Y-m-d'),
                'type'          => $r->type,
                'channel'       => $r->channel,
                'campaign_name' => $r->campaign_name,
                'flow_id'       => $r->flow_id,
                'recipients'    => $r->recipients,
                'opens'         => $r->opens,
                'clicks'        => $r->clicks,
                'conversions'   => $r->conversions,
                'revenue'       => round($r->revenue, 2),
                'open_rate'     => round($r->open_rate, 4),
                'click_rate'    => round($r->click_rate, 4),
                'unsubscribes'  => $r->unsubscribes,
                'bounces'       => $r->bounces,
            ], fn ($v) => $v !== null && $v !== [] && $v !== '' && $v !== 0))->toArray())
            ->toArray();
    }

    private function queryDeployments(int $clientId, Carbon $from, Carbon $to): array
    {
        return Deployment::where('client_id', $clientId)
            ->whereBetween('deployed_at', [$from, $to])
            ->orderBy('deployed_at')
            ->get()
            ->map(fn ($d) => [
                'title'       => $d->title,
                'type'        => is_object($d->deployment_type) ? $d->deployment_type->value : $d->deployment_type,
                'deployed_at' => $d->deployed_at->format('Y-m-d H:i'),
                'deployed_by' => $d->deployed_by,
                'description' => $d->description,
            ])
            ->toArray();
    }

    private function queryRecommendations(Finding $finding): array
    {
        return $finding->recommendations()
            ->with('outcome')
            ->get()
            ->map(fn ($r) => [
                'summary'     => $r->ai_summary,
                'actions'     => $r->recommendation_text,
                'implemented' => $r->outcome?->implemented ?? false,
                'impact'      => $r->outcome?->actual_impact,
            ])
            ->toArray();
    }

    private function queryNotes(Finding $finding): array
    {
        return $finding->investigationNotes()
            ->with('user')
            ->get()
            ->map(fn ($n) => [
                'author'     => $n->user?->name ?? 'Unknown',
                'root_cause' => $n->root_cause,
                'fix'        => $n->fix_implemented,
                'outcome'    => $n->outcome,
                'date'       => $n->created_at->format('Y-m-d H:i'),
            ])
            ->toArray();
    }

    private function querySimilarPatterns(Finding $finding): array
    {
        return IntelligenceMemory::where('client_id', $finding->client_id)
            ->where('finding_type', $finding->finding_type)
            ->where('id', '!=', $finding->id)
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn ($m) => [
                'pattern'    => $m->pattern_description,
                'root_cause' => $m->root_cause,
                'resolution' => $m->resolution,
                'outcome'    => $m->outcome,
            ])
            ->toArray();
    }

    /**
     * Query New Relic server logs (errors/warnings) via NerdGraph NRQL
     * if a New Relic integration is connected for this client.
     */
    private function queryNewRelicLogs(int $clientId, Carbon $from, Carbon $to): array
    {
        $nrIntegration = Integration::where('client_id', $clientId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('integration_type', 'new_relic')
                  ->orWhere('integration_type', \App\Enums\IntegrationType::NewRelic);
            })
            ->first();

        if (! $nrIntegration) {
            return [];
        }

        try {
            $connector = new NewRelicConnector($nrIntegration);
            $logData = $connector->fetchLogs($from->copy(), $to->copy(), 50);

            if (empty($logData['logs']) && $logData['total_errors'] === 0) {
                return [];
            }

            return $logData;
        } catch (\Throwable $e) {
            Log::warning('DataContextGatherer: New Relic log query failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Identify which data sources are available for this client.
     */
    private function identifyDataSources(int $clientId, array $fetchedSources): array
    {
        $integrations = Integration::where('client_id', $clientId)
            ->where('status', 'active')
            ->pluck('integration_type')
            ->map(fn ($t) => is_object($t) ? $t->value : $t)
            ->toArray();

        return [
            'active_integrations' => $integrations,
            'freshly_fetched'     => $fetchedSources,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // On-demand data fetching
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect which dates are missing from the local database for the
     * investigation window.
     */
    public function detectDataGaps(int $clientId, Carbon $from, Carbon $to): array
    {
        $expectedDates = [];
        $cursor = $from->copy()->startOfDay();
        $end    = min($to->copy()->startOfDay(), now()->subDay()->startOfDay());
        while ($cursor->lte($end)) {
            $expectedDates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        if (empty($expectedDates)) {
            return ['ga4' => [], 'adobe_commerce' => [], 'clarity' => [], 'new_relic' => [], 'klaviyo' => []];
        }

        $ga4Dates = CommerceMetric::where('client_id', $clientId)
            ->where('source', 'ga4')
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        $adobeDates = CommerceMetric::where('client_id', $clientId)
            ->where('source', 'adobe_commerce')
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        $clarityDates = BehavioralMetric::where('client_id', $clientId)
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        $newRelicDates = PerformanceMetric::where('client_id', $clientId)
            ->where('source', 'new_relic')
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        $klaviyoDates = EmailMarketingMetric::where('client_id', $clientId)
            ->where('source', 'klaviyo')
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->toArray();

        return [
            'ga4'            => array_values(array_diff($expectedDates, $ga4Dates)),
            'adobe_commerce' => array_values(array_diff($expectedDates, $adobeDates)),
            'clarity'        => array_values(array_diff($expectedDates, $clarityDates)),
            'new_relic'      => array_values(array_diff($expectedDates, $newRelicDates)),
            'klaviyo'        => array_values(array_diff($expectedDates, $klaviyoDates)),
        ];
    }

    /**
     * Fetch missing data by calling the integration connectors directly.
     * Returns list of sources that were fetched.
     */
    public function fetchMissingData(int $clientId, array $gaps, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $integrations = Integration::where('client_id', $clientId)
            ->where('status', 'active')
            ->get();

        $fetched = [];

        foreach ($integrations as $integration) {
            $type = $integration->integration_type?->value ?? $integration->integration_type;

            try {
                $result = match ($type) {
                    'ga4'             => $this->fetchGa4Gaps($integration, $gaps['ga4'] ?? [], $rangeStart, $rangeEnd),
                    'adobe_commerce'  => $this->fetchAdobeGaps($integration, $gaps['adobe_commerce'] ?? [], $rangeStart, $rangeEnd),
                    'clarity'         => $this->fetchClarityGaps($integration, $gaps['clarity'] ?? []),
                    'new_relic'       => $this->fetchNewRelicGaps($integration, $gaps['new_relic'] ?? [], $rangeStart, $rangeEnd),
                    'klaviyo'         => $this->fetchKlaviyoGaps($integration, $gaps['klaviyo'] ?? [], $rangeStart, $rangeEnd),
                    default           => null,
                };

                if ($result !== null) {
                    $fetched[] = $type;
                }
            } catch (\Throwable $e) {
                Log::warning("DataContextGatherer: failed to fetch {$type} data on-demand", [
                    'client_id'      => $clientId,
                    'integration_id' => $integration->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return $fetched;
    }

    private function fetchGa4Gaps(Integration $integration, array $missingDates, Carbon $rangeStart, Carbon $rangeEnd): ?int
    {
        if (empty($missingDates)) return null;

        $connector = new GA4Connector($integration);
        $fetched = $connector->fetchForDateRange($rangeStart, $rangeEnd);

        Log::info('DataContextGatherer: GA4 on-demand fetch', [
            'integration_id'  => $integration->id,
            'missing_dates'   => count($missingDates),
            'records_fetched' => $fetched,
        ]);

        return $fetched;
    }

    private function fetchAdobeGaps(Integration $integration, array $missingDates, Carbon $rangeStart, Carbon $rangeEnd): ?int
    {
        if (empty($missingDates)) return null;

        $connector = new AdobeCommerceConnector($integration);
        $fetched = $connector->fetchForDateRange($rangeStart, $rangeEnd);

        Log::info('DataContextGatherer: Adobe Commerce on-demand fetch', [
            'integration_id'  => $integration->id,
            'missing_dates'   => count($missingDates),
            'records_fetched' => $fetched,
        ]);

        return $fetched;
    }

    private function fetchClarityGaps(Integration $integration, array $missingDates): ?int
    {
        if (empty($missingDates)) return null;

        $connector = new ClarityConnector($integration);
        $recentGapStart = collect($missingDates)
            ->map(fn ($d) => Carbon::parse($d))
            ->filter(fn ($d) => $d->gte(now()->subDays(3)->startOfDay()))
            ->min();

        if (! $recentGapStart) return null;

        $fetched = $connector->fetchForDateRange($recentGapStart, now());

        Log::info('DataContextGatherer: Clarity on-demand fetch', [
            'integration_id'   => $integration->id,
            'missing_dates'    => count($missingDates),
            'recent_fetchable' => $recentGapStart->toDateString(),
            'records_fetched'  => $fetched,
        ]);

        return $fetched;
    }

    private function fetchNewRelicGaps(Integration $integration, array $missingDates, Carbon $rangeStart, Carbon $rangeEnd): ?int
    {
        if (empty($missingDates)) return null;

        $connector = new NewRelicConnector($integration);
        $fetched = $connector->fetchForDateRange($rangeStart, $rangeEnd);

        Log::info('DataContextGatherer: New Relic on-demand fetch', [
            'integration_id'  => $integration->id,
            'missing_dates'   => count($missingDates),
            'records_fetched' => $fetched,
        ]);

        return $fetched;
    }

    private function fetchKlaviyoGaps(Integration $integration, array $missingDates, Carbon $rangeStart, Carbon $rangeEnd): ?int
    {
        if (empty($missingDates)) return null;

        $connector = new KlaviyoConnector($integration);
        $fetched = $connector->fetchForDateRange($rangeStart, $rangeEnd);

        Log::info('DataContextGatherer: Klaviyo on-demand fetch', [
            'integration_id'  => $integration->id,
            'missing_dates'   => count($missingDates),
            'records_fetched' => $fetched,
        ]);

        return $fetched;
    }
}
