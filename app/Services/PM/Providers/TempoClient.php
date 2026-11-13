<?php

namespace App\Services\PM\Providers;

use App\Models\PmConnection;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TempoClient
{
    protected string $baseUrl;
    protected ?string $token;

    public function __construct(?string $token = null, ?string $baseUrl = null)
    {
        $this->token = $token ?: config('services.tempo.token') ?: env('TEMPO_API_TOKEN');
        $this->baseUrl = rtrim($baseUrl ?: config('services.tempo.base_url') ?: 'https://api.tempo.io/4', '/');
    }

    /**
     * Factory helper to instantiate a client for a specific PM connection.
     */
    public static function forConnection(?PmConnection $connection): ?self
    {
        $token = $connection?->tempo_api_token
            ?: config('services.tempo.token')
            ?: env('TEMPO_API_TOKEN');

        if (empty($token)) {
            return null;
        }

        return new self($token);
    }

    /**
     * Check if client has a configured bearer token.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->token);
    }

    /**
     * Map Jira worklog IDs to Tempo worklog IDs.
     * Uses Tempo's bulk endpoint POST /4/worklogs/jira-to-tempo in chunks of 50.
     *
     * @param array<int|string> $jiraWorklogIds
     * @return array<int, int> [jiraWorklogId => tempoWorklogId]
     */
    public function mapJiraWorklogsToTempo(array $jiraWorklogIds): array
    {
        if (empty($jiraWorklogIds) || ! $this->isConfigured()) {
            return [];
        }

        $intIds = array_values(array_unique(array_filter(array_map('intval', $jiraWorklogIds))));
        if (empty($intIds)) {
            return [];
        }

        $mapping = [];
        $chunks = array_chunk($intIds, 50);

        foreach ($chunks as $chunk) {
            try {
                $response = Http::withToken($this->token)
                    ->timeout(15)
                    ->retry(2, 200, throw: false)
                    ->post("{$this->baseUrl}/worklogs/jira-to-tempo", [
                        'jiraWorklogIds' => $chunk,
                    ]);

                if ($response->successful()) {
                    $results = $response->json()['results'] ?? [];
                    foreach ($results as $res) {
                        if (isset($res['jiraWorklogId'], $res['tempoWorklogId'])) {
                            $mapping[(int) $res['jiraWorklogId']] = (int) $res['tempoWorklogId'];
                        }
                    }
                } else {
                    Log::warning('TempoClient: jira-to-tempo mapping failed', [
                        'status' => $response->status(),
                        'chunk_count' => count($chunk),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('TempoClient: Exception in jira-to-tempo mapping', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $mapping;
    }

    /**
     * Fetch a single Tempo worklog by its Tempo ID.
     */
    public function getWorklog(int|string $tempoWorklogId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->retry(2, 200, throw: false)
                ->get("{$this->baseUrl}/worklogs/{$tempoWorklogId}");

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::error('TempoClient: Exception fetching worklog', [
                'tempo_worklog_id' => $tempoWorklogId,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Bulk resolve author account IDs for Jira worklog IDs.
     *
     * @param array<int|string> $jiraWorklogIds
     * @return array<int, array{tempo_worklog_id: int, author_account_id: string, time_spent_seconds: int, start_date: ?string}>
     */
    public function resolveJiraWorklogAuthors(array $jiraWorklogIds): array
    {
        $jiraToTempoMap = $this->mapJiraWorklogsToTempo($jiraWorklogIds);
        if (empty($jiraToTempoMap)) {
            return [];
        }

        $tempoIds = array_values(array_unique($jiraToTempoMap));
        $worklogsByTempoId = [];

        // Concurrently retrieve Tempo worklog records in pools of up to 25
        $chunks = array_chunk($tempoIds, 25);
        foreach ($chunks as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk) {
                foreach ($chunk as $tid) {
                    $pool->as((string) $tid)
                        ->withToken($this->token)
                        ->timeout(10)
                        ->retry(2, 200, throw: false)
                        ->get("{$this->baseUrl}/worklogs/{$tid}");
                }
            });

            foreach ($responses as $tid => $resp) {
                if ($resp instanceof Response && $resp->successful()) {
                    $worklogsByTempoId[(int) $tid] = $resp->json();
                }
            }
        }

        $resolved = [];
        foreach ($jiraToTempoMap as $jiraId => $tempoId) {
            $w = $worklogsByTempoId[$tempoId] ?? null;
            if ($w && ! empty($w['author']['accountId'])) {
                $resolved[$jiraId] = [
                    'tempo_worklog_id'   => (int) $tempoId,
                    'author_account_id'  => (string) $w['author']['accountId'],
                    'time_spent_seconds' => (int) ($w['timeSpentSeconds'] ?? 0),
                    'start_date'         => $w['startDate'] ?? null,
                ];
            }
        }

        return $resolved;
    }
}
