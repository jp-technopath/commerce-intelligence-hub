<?php

namespace App\Services\Intelligence\DataQualification;

use App\Models\BehavioralMetric;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\CommerceOrder;
use App\Models\EmailMarketingMetric;
use App\Models\Finding;
use App\Models\Integration;
use App\Models\SyncLog;
use Carbon\Carbon;

/**
 * Evaluates authoritative data freshness for Findings strictly from source
 * observation windows and integration synchronization records — never from
 * database `updated_at` or detector execution timestamps alone.
 */
class FindingFreshnessService
{
    /**
     * Request-scoped cache of client source telemetry status keyed by client_id.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $clientSourceCache = [];

    /**
     * Maximum acceptable reporting lag (in days) before a source itself is flagged stale.
     */
    private const MAX_SOURCE_LAG_DAYS = [
        'ga4'            => 2, // GA4 Data API closes yesterday (1-2d lag normal)
        'adobe_commerce' => 2,
        'shopify'        => 2,
        'klaviyo'        => 2,
        'clarity'        => 2,
    ];

    /**
     * Preload integration sync and latest data dates for a client once per request.
     *
     * @return array<string, mixed>
     */
    public function getClientSourceSummary(int $clientId): array
    {
        if (isset($this->clientSourceCache[$clientId])) {
            return $this->clientSourceCache[$clientId];
        }

        $integrations = Integration::where('client_id', $clientId)->get()->keyBy('integration_type');
        $integrationIds = $integrations->pluck('id')->all();

        $latestLogsByInt = [];
        $latestSuccessLogsByInt = [];
        if (! empty($integrationIds)) {
            $logs = SyncLog::whereIn('integration_id', $integrationIds)
                ->orderByDesc('id')
                ->get();

            foreach ($logs as $log) {
                if (! isset($latestLogsByInt[$log->integration_id])) {
                    $latestLogsByInt[$log->integration_id] = $log;
                }
                $statusVal = $log->status instanceof \App\Enums\SyncStatus ? $log->status->value : (string) $log->status;
                if ($statusVal === 'success' && ! isset($latestSuccessLogsByInt[$log->integration_id])) {
                    $latestSuccessLogsByInt[$log->integration_id] = $log;
                }
            }
        }

        $commerceMaxBySource = CommerceMetric::where('client_id', $clientId)
            ->selectRaw('source, MAX(date) as max_date')
            ->groupBy('source')
            ->pluck('max_date', 'source')
            ->toArray();

        $maxOrderDate = CommerceOrder::where('client_id', $clientId)->max('order_date');
        $maxBehDate = BehavioralMetric::where('client_id', $clientId)->max('date');
        $maxEmailDate = EmailMarketingMetric::where('client_id', $clientId)->max('date');

        $sources = [
            'ga4'            => $commerceMaxBySource['ga4'] ?? null,
            'adobe_commerce' => $commerceMaxBySource['adobe_commerce'] ?? $maxOrderDate,
            'shopify'        => $commerceMaxBySource['shopify'] ?? $maxOrderDate,
            'clarity'        => $maxBehDate,
            'klaviyo'        => $maxEmailDate,
        ];

        $now = now();
        $summary = [];

        foreach ($sources as $sourceKey => $maxDataDate) {
            /** @var Integration|null $integration */
            $integration = $integrations->get($sourceKey);
            $latestLog = $integration ? ($latestLogsByInt[$integration->id] ?? null) : null;
            $latestSuccess = $integration ? ($latestSuccessLogsByInt[$integration->id] ?? null) : null;

            $latestLogStatus = $latestLog
                ? ($latestLog->status instanceof \App\Enums\SyncStatus ? $latestLog->status->value : (string) $latestLog->status)
                : null;

            $dataDateCarbon = $maxDataDate ? Carbon::parse($maxDataDate)->startOfDay() : null;
            $lagDays = $dataDateCarbon ? (int) $dataDateCarbon->diffInDays($now->copy()->startOfDay()) : null;
            $maxAllowedLag = self::MAX_SOURCE_LAG_DAYS[$sourceKey] ?? 2;

            $isSourceStale = $dataDateCarbon === null
                || $lagDays > $maxAllowedLag
                || ($latestLogStatus === 'failed' && ($lagDays === null || $lagDays >= $maxAllowedLag));

            $summary[$sourceKey] = [
                'source'                  => $sourceKey,
                'has_integration'         => $integration !== null,
                'latest_data_date'        => $dataDateCarbon?->toDateString(),
                'data_lag_days'           => $lagDays,
                'last_successful_sync_at' => $latestSuccess?->completed_at?->toIso8601String()
                    ?? $integration?->last_sync_at?->toIso8601String(),
                'last_sync_status'        => $latestLogStatus,
                'last_sync_error'         => $latestLogStatus === 'failed' ? ($latestLog->error_message ?? 'Sync failed') : null,
                'is_source_stale'         => $isSourceStale,
            ];
        }

        return $this->clientSourceCache[$clientId] = $summary;
    }

    /**
     * Evaluate the 6-timestamp freshness contract for an operational or diagnostic finding.
     *
     * @return array{
     *   state: string,
     *   badge: string,
     *   color: string,
     *   note: string,
     *   is_outdated: bool,
     *   requires_revalidation: bool,
     *   first_detected_at: ?string,
     *   last_observed_at: ?string,
     *   source_window_start: ?string,
     *   source_window_end: ?string,
     *   last_successful_sync_at: ?string,
     *   latest_source_data_date: ?string,
     *   evaluated_at: ?string,
     *   record_updated_at: ?string,
     *   observation_period_label: string,
     *   source_statuses: array
     * }
     */
    public function evaluateFinding(Finding $finding): array
    {
        $meta = is_array($finding->metadata_json)
            ? $finding->metadata_json
            : (json_decode($finding->metadata_json ?? '[]', true) ?: []);
        $ev = is_array($finding->evidence_json)
            ? $finding->evidence_json
            : (json_decode($finding->evidence_json ?? '[]', true) ?: []);

        $firstDetectedAt = $finding->detected_at?->toIso8601String()
            ?? ($meta['primary_signal']['detected_at'] ?? null)
            ?? $finding->created_at?->toIso8601String();

        $evaluatedAt = $ev['evaluated_at']
            ?? $meta['evaluated_at']
            ?? $meta['primary_signal']['detected_at']
            ?? $firstDetectedAt;

        // Genuine observation window: prioritize explicit source_window_end / evidence window,
        // NEVER fall back to $finding->updated_at.
        $windowStart = $ev['source_window_start']
            ?? $meta['source_window_start']
            ?? $meta['primary_signal']['evidence']['source_window_start']
            ?? null;
        $windowEnd = $ev['source_window_end']
            ?? $meta['source_window_end']
            ?? $meta['primary_signal']['evidence']['source_window_end']
            ?? null;

        // For legacy findings created before source_window_* tracking, infer the closed observation window
        // from primary_signal detected_at (not DB updated_at) and rule period_days.
        $periodDays = (int) ($meta['period_days'] ?? $ev['period_days'] ?? 0);
        if (! $windowEnd && $firstDetectedAt) {
            try {
                $detDate = Carbon::parse($firstDetectedAt);
                $inferredEnd = $detDate->copy()->subDay()->startOfDay();
                $windowEnd = $inferredEnd->toDateString();
                if (! $windowStart && $periodDays > 0) {
                    $windowStart = $inferredEnd->copy()->subDays(max(0, $periodDays - 1))->toDateString();
                }
            } catch (\Throwable) {
                // Keep nulls
            }
        }

        // Genuine observation date: if metadata['last_observed_at'] was recorded alongside refreshed evidence
        // or backfilled cleanly, use it; otherwise anchor to $windowEnd or $firstDetectedAt (NEVER updated_at).
        $evidenceLastObserved = $ev['last_observed_at']
            ?? (! empty($ev['observation_refreshed']) ? ($meta['last_observed_at'] ?? null) : null)
            ?? (! empty($meta['source_window_end']) ? ($meta['last_observed_at'] ?? null) : null)
            ?? $windowEnd
            ?? $firstDetectedAt;

        $requiredSources = $this->resolveRequiredSources($finding, $meta);
        $clientSources = $finding->client_id ? $this->getClientSourceSummary((int) $finding->client_id) : [];

        $relevantStatuses = [];
        $staleSources = [];
        $latestSharedDataDate = null;
        $lastSyncAt = null;

        foreach ($requiredSources as $srcKey) {
            if (! isset($clientSources[$srcKey])) {
                continue;
            }
            $st = $clientSources[$srcKey];
            $relevantStatuses[$srcKey] = $st;

            if ($st['latest_data_date']) {
                if ($latestSharedDataDate === null || $st['latest_data_date'] < $latestSharedDataDate) {
                    $latestSharedDataDate = $st['latest_data_date'];
                }
            }
            if ($st['last_successful_sync_at']) {
                if ($lastSyncAt === null || $st['last_successful_sync_at'] > $lastSyncAt) {
                    $lastSyncAt = $st['last_successful_sync_at'];
                }
            }
            if (! empty($st['is_source_stale'])) {
                $staleSources[] = strtoupper($srcKey) . ($st['latest_data_date'] ? " (through {$st['latest_data_date']})" : ' (no synced data)');
            }
        }

        $windowEndCarbon = $windowEnd ? Carbon::parse($windowEnd)->startOfDay() : null;
        $latestSourceCarbon = $latestSharedDataDate ? Carbon::parse($latestSharedDataDate)->startOfDay() : null;
        $referenceCarbon = $latestSourceCarbon ?? now()->startOfDay();
        $windowLagDays = $windowEndCarbon ? (int) $windowEndCarbon->diffInDays($referenceCarbon) : null;

        $observationPeriodLabel = ($windowStart && $windowEnd)
            ? (Carbon::parse($windowStart)->format('M j') . ' – ' . Carbon::parse($windowEnd)->format('M j, Y'))
            : ($windowEnd
                ? ('Window ended ' . Carbon::parse($windowEnd)->format('M j, Y'))
                : ($periodDays > 0 ? "Trailing {$periodDays} days" : 'Monitoring episode'));

        // Evaluate 3-state freshness contract strictly from source data & window
        if (! empty($staleSources)) {
            $state = 'source_stale';
            $color = 'amber';
            $isOutdated = true;
            $requiresRevalidation = true;
            $badge = $latestSharedDataDate
                ? ('Source Sync Delayed (Data through ' . Carbon::parse($latestSharedDataDate)->format('M j, Y') . ')')
                : 'Source Data Unavailable';
            $note = 'Upstream source integration requires attention before acting on this observation: ' . implode(', ', $staleSources) . '.';
        } elseif ($windowEndCarbon && $windowLagDays !== null && $windowLagDays > 7) {
            $state = 'historical_observation';
            $color = 'amber';
            $isOutdated = true;
            $requiresRevalidation = true;
            $badge = 'Historical Observation (' . $windowEndCarbon->format('M j, Y') . ')';
            $note = "Source evidence covers through {$windowEndCarbon->format('M j, Y')} ({$windowLagDays} days behind latest synced data" . ($latestSharedDataDate ? " on {$latestSharedDataDate}" : '') . "); requires revalidation against current telemetry before action.";
        } elseif ($windowEndCarbon) {
            $state = 'current';
            $color = 'emerald';
            $isOutdated = false;
            $requiresRevalidation = false;
            $badge = 'Current Evidence (Through ' . $windowEndCarbon->format('M j, Y') . ')';
            $note = "Source evidence verified against latest available telemetry ({$observationPeriodLabel}).";
        } else {
            $state = 'unverified_window';
            $color = 'amber';
            $isOutdated = true;
            $requiresRevalidation = true;
            $badge = 'Observation Window Unverified';
            $note = 'Source observation window timestamps were not recorded; revalidate against live telemetry before action.';
        }

        return [
            'state'                    => $state,
            'badge'                    => $badge,
            'color'                    => $color,
            'note'                     => $note,
            'is_outdated'              => $isOutdated,
            'requires_revalidation'    => $requiresRevalidation,
            'first_detected_at'        => $firstDetectedAt,
            'last_observed_at'         => $evidenceLastObserved,
            'source_window_start'      => $windowStart,
            'source_window_end'        => $windowEnd,
            'last_successful_sync_at'  => $lastSyncAt,
            'latest_source_data_date'  => $latestSharedDataDate,
            'evaluated_at'             => $evaluatedAt,
            'record_updated_at'        => $finding->updated_at?->toIso8601String(),
            'observation_period_label' => $observationPeriodLabel,
            'source_statuses'          => $relevantStatuses,
        ];
    }

    /**
     * Determine which integration sources back a finding.
     *
     * @return array<int, string>
     */
    private function resolveRequiredSources(Finding $finding, array $meta): array
    {
        if (! empty($meta['sources']) && is_array($meta['sources'])) {
            return array_values(array_unique(array_map('strtolower', $meta['sources'])));
        }

        $type = strtolower((string) ($finding->finding_type ?? ''));
        $rule = strtolower((string) ($meta['rule_key'] ?? ''));

        if (str_contains($type, 'script_error') || str_contains($type, 'clarity') || str_contains($type, 'friction') || str_contains($type, 'click')) {
            return ['clarity'];
        }
        if (str_contains($type, 'klaviyo') || str_contains($type, 'campaign_tagging') || str_contains($rule, 'klaviyo')) {
            return ['ga4', 'klaviyo'];
        }
        if (str_contains($type, 'platform_variance') || str_contains($type, 'revenue_mismatch')) {
            return ['ga4', 'adobe_commerce'];
        }
        if (str_contains($type, 'adobe_commerce') || str_contains($rule, 'commerce')) {
            return ['adobe_commerce'];
        }
        if (str_contains($type, 'volume_surge') || str_contains($type, 'conversion') || str_contains($type, 'ga4')) {
            return ['ga4'];
        }

        return ['ga4'];
    }
}
