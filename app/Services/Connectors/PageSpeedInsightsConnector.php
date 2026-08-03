<?php

namespace App\Services\Connectors;

use App\Models\Integration;
use App\Models\PerformanceMetric;
use App\Models\SyncLog;
use App\Enums\SyncStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PageSpeedInsightsConnector
 *
 * Fetches 75th percentile (p75) real-user Core Web Vitals from the
 * Google PageSpeed Insights / Chrome UX Report (CrUX) API.
 */
class PageSpeedInsightsConnector
{
    private const BASE_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function __construct(private readonly Integration $integration) {}

    public function sync(SyncLog $syncLog, int $numOfDays = 1): void
    {
        $creds  = $this->integration->credentials_json ?? [];
        $url    = $creds['site_url'] ?? $creds['url'] ?? null;
        $apiKey = config('services.pagespeed.api_key') ?? $creds['api_key'] ?? null;

        if (! $url) {
            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => 'Missing site_url in integration credentials.',
                'completed_at'  => now(),
            ]);
            return;
        }

        try {
            $metrics = $this->fetchCruxMetrics($url, $apiKey);

            if ($metrics) {
                PerformanceMetric::updateOrCreate(
                    [
                        'client_id' => $this->integration->client_id,
                        'date'      => now()->toDateString(),
                        'source'    => 'crux',
                    ],
                    [
                        'lcp'           => $metrics['lcp_p75'],
                        'inp'           => $metrics['inp_p75'],
                        'cls'           => $metrics['cls_p75'],
                        'metadata_json' => [
                            'origin'            => $url,
                            'lcp_p75_seconds'   => $metrics['lcp_p75'],
                            'inp_p75_ms'        => $metrics['inp_p75'],
                            'cls_p75_score'     => $metrics['cls_p75'],
                            'overall_category'  => $metrics['overall_category'],
                            'data_type'         => 'field_crux_p75',
                        ],
                    ]
                );
            }

            $syncLog->update([
                'status'            => SyncStatus::Success,
                'records_processed' => $metrics ? 1 : 0,
                'completed_at'      => now(),
            ]);

        } catch (\Exception $e) {
            Log::error('PageSpeedInsightsConnector: sync error', [
                'integration_id' => $this->integration->id,
                'message'        => $e->getMessage(),
            ]);

            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
        }
    }

    /**
     * Fetch CrUX p75 field experience metrics from Google PSI API.
     */
    public function fetchCruxMetrics(string $url, ?string $apiKey = null): ?array
    {
        $params = [
            'url'      => $url,
            'category' => 'performance',
            'strategy' => 'mobile',
        ];

        if ($apiKey) {
            $params['key'] = $apiKey;
        }

        $response = Http::timeout(25)->get(self::BASE_URL, $params);

        if (! $response->successful()) {
            Log::warning('PageSpeedInsightsConnector API request unsuccessful', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 200),
            ]);
            return null;
        }

        $json  = $response->json();
        $field = $json['loadingExperience']['metrics'] ?? [];

        if (empty($field)) {
            return null;
        }

        $lcpMs = $field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? null;
        $inpMs = $field['INTERACTION_TO_NEXT_PAINT']['percentile'] ?? null;
        $clsRaw = $field['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ?? null;

        return [
            'lcp_p75'          => $lcpMs ? round($lcpMs / 1000, 2) : null, // convert ms -> seconds
            'inp_p75'          => $inpMs ? (float) $inpMs : null,          // ms
            'cls_p75'          => $clsRaw ? round($clsRaw / 100, 3) : null, // score
            'overall_category' => $json['loadingExperience']['overall_category'] ?? 'UNKNOWN',
        ];
    }
}
