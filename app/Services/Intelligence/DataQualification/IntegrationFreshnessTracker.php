<?php

namespace App\Services\Intelligence\DataQualification;

use App\Models\BehavioralMetric;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\CommerceOrder;
use App\Models\Integration;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class IntegrationFreshnessTracker
{
    /**
     * Inspect source-specific data freshness and business day completeness for a client.
     *
     * @param Client $client
     * @param Carbon|string|null $targetDate
     * @param Carbon|null $evaluationTime
     * @return array
     */
    public function evaluateFreshness(Client $client, $targetDate = null, ?Carbon $evaluationTime = null): array
    {
        $evalTime = $evaluationTime ? $evaluationTime->copy() : Carbon::now('UTC');
        $clientTimezone = $client->timezone ?? 'America/New_York';
        $clientLocalTime = $evalTime->copy()->setTimezone($clientTimezone);

        // Determine target evaluation date
        if ($targetDate instanceof Carbon) {
            $evalTargetDate = $targetDate->copy()->toDateString();
        } elseif (is_string($targetDate)) {
            $evalTargetDate = Carbon::parse($targetDate)->toDateString();
        } else {
            // Default target is yesterday in client timezone
            $evalTargetDate = $clientLocalTime->copy()->subDay()->toDateString();
        }

        // 1. Business Day Completeness Check in Client Timezone
        $targetEndOfDay = Carbon::parse($evalTargetDate . ' 23:59:59', $clientTimezone);
        $isBusinessDayComplete = $clientLocalTime->greaterThanOrEqualTo($targetEndOfDay);
        $hoursUntilClose = $isBusinessDayComplete ? 0 : round($clientLocalTime->diffInMinutes($targetEndOfDay) / 60, 1);

        // 2. Platform-by-Platform Freshness & Synchronization Audit
        $activeIntegrations = Integration::where('client_id', $client->id)
            ->where('status', 'active')
            ->get();

        $platformStatuses = [];
        $delayedPlatforms = [];

        foreach ($activeIntegrations as $integration) {
            $type = $integration->integration_type?->value;
            $platformData = $this->inspectPlatformCoverage($client, $integration, $evalTargetDate, $clientTimezone);
            $platformStatuses[$type] = $platformData;

            if (!$platformData['is_synchronized']) {
                $delayedPlatforms[] = $type;
            }
        }

        $allSynced = empty($delayedPlatforms);
        $isEvaluationReady = $isBusinessDayComplete && $allSynced;

        $gatingRationale = null;
        if (!$isBusinessDayComplete) {
            $gatingRationale = sprintf(
                'Reporting day %s has not yet completed in client timezone (%s). Local time is %s (%s hours remaining until day close).',
                $evalTargetDate,
                $clientTimezone,
                $clientLocalTime->format('Y-m-d H:i:s T'),
                $hoursUntilClose
            );
        } elseif (!$allSynced) {
            $gatingRationale = sprintf(
                'Data synchronization delayed for platform(s): %s for evaluation date %s. Deferred to prevent false findings.',
                implode(', ', $delayedPlatforms),
                $evalTargetDate
            );
        }

        return [
            'client_id' => $client->id,
            'client_name' => $client->name,
            'client_timezone' => $clientTimezone,
            'timezone_offset' => $clientLocalTime->format('P'),
            'is_daylight_saving_time' => $clientLocalTime->dst,
            'evaluation_time_utc' => $evalTime->toIso8601String(),
            'client_local_time' => $clientLocalTime->format('Y-m-d H:i:s T'),
            'target_evaluation_date' => $evalTargetDate,
            'is_business_day_complete' => $isBusinessDayComplete,
            'hours_until_day_close' => $hoursUntilClose,
            'all_platforms_synchronized' => $allSynced,
            'is_evaluation_ready' => $isEvaluationReady,
            'gating_rationale' => $gatingRationale,
            'delayed_platforms' => $delayedPlatforms,
            'platform_coverage' => $platformStatuses,
        ];
    }

    /**
     * Inspect coverage and freshness for a specific integration.
     */
    private function inspectPlatformCoverage(Client $client, Integration $integration, string $targetDate, string $timezone): array
    {
        $type = $integration->integration_type?->value;
        $lastSync = SyncLog::where('integration_id', $integration->id)
            ->latest('id')
            ->first();

        $latestDataDate = null;
        $isSyncedThroughTarget = false;

        $dataLineage = 'directly_observed';
        $isDirectlyObserved = true;
        $qualificationNotes = 'Directly observed from platform sync.';

        switch ($type) {
            case 'adobe_commerce':
                $latestOrder = CommerceOrder::where('client_id', $client->id)->max('order_date');
                if ($latestOrder) {
                    $latestDataDate = Carbon::parse($latestOrder)->setTimezone($timezone)->toDateString();
                    $isSyncedThroughTarget = $latestDataDate >= $targetDate;
                }
                $dataLineage = 'directly_observed';
                $isDirectlyObserved = true;
                $qualificationNotes = 'Production commerce_orders records (status confirmed/paid_in_full).';
                break;

            case 'ga4':
                $latestMetric = CommerceMetric::where('client_id', $client->id)
                    ->where('source', 'ga4')
                    ->max('date');
                if ($latestMetric) {
                    $latestDataDate = Carbon::parse($latestMetric)->toDateString();
                    $isSyncedThroughTarget = $latestDataDate >= $targetDate;
                }
                $dataLineage = 'directly_observed';
                $isDirectlyObserved = true;
                $qualificationNotes = 'GA4 daily batch metrics and purchase events.';
                break;

            case 'clarity':
                $targetBehavioral = BehavioralMetric::where('client_id', $client->id)
                    ->whereDate('date', $targetDate)
                    ->first();
                $latestBehavioral = BehavioralMetric::where('client_id', $client->id)->latest('date')->first();
                if ($latestBehavioral) {
                    $latestDataDate = Carbon::parse($latestBehavioral->date)->toDateString();
                    $isSyncedThroughTarget = $latestDataDate >= $targetDate;
                }

                $recordToInspect = $targetBehavioral ?? $latestBehavioral;
                if ($recordToInspect) {
                    $meta = is_array($recordToInspect->metadata_json)
                        ? $recordToInspect->metadata_json
                        : (json_decode($recordToInspect->metadata_json ?? '[]', true) ?? []);

                    $status = $meta['data_status'] ?? 'directly_observed';
                    if ($status === 'estimated') {
                        $dataLineage = 'recovered_estimated';
                        $isDirectlyObserved = false;
                        $qualificationNotes = 'Estimated via overlapping 2-day rolling window disaggregation due to upstream Clarity API timeout (cURL error 28).';
                    } elseif ($status === 'derived_subtraction') {
                        $dataLineage = 'derived_exact';
                        $isDirectlyObserved = true;
                        $qualificationNotes = 'Derived via exact subtraction of 2-day downstream window from 3-day aggregate.';
                    } else {
                        $dataLineage = 'directly_observed';
                        $isDirectlyObserved = true;
                        $qualificationNotes = 'Directly observed from native Clarity API daily sync.';
                    }
                }
                break;

            case 'klaviyo':
                if ($lastSync && ($lastSync->status?->value === 'success' || (string) $lastSync->status === 'success')) {
                    $latestDataDate = $lastSync->completed_at ? $lastSync->completed_at->toDateString() : null;
                    $isSyncedThroughTarget = $latestDataDate >= $targetDate || $lastSync->completed_at?->isAfter(Carbon::parse($targetDate)->endOfDay());
                }
                $dataLineage = 'directly_observed';
                $isDirectlyObserved = true;
                $qualificationNotes = 'Klaviyo campaign and attribution sync.';
                break;

            default:
                $latestDataDate = $integration->last_sync_at?->toDateString();
                $isSyncedThroughTarget = $latestDataDate >= $targetDate;
                break;
        }

        $syncStatus = $lastSync?->status?->value ?? (string) $lastSync?->status ?? 'unknown';
        $syncCompletedAt = $lastSync?->completed_at?->toIso8601String();
        $isSuccessfulSync = $syncStatus === 'success';

        return [
            'integration_id' => $integration->id,
            'type' => $type,
            'latest_data_date' => $latestDataDate,
            'target_date' => $targetDate,
            'is_synchronized' => $isSyncedThroughTarget && $isSuccessfulSync,
            'data_lineage' => $dataLineage,
            'is_directly_observed' => $isDirectlyObserved,
            'qualification_notes' => $qualificationNotes,
            'last_sync_status' => $syncStatus,
            'last_sync_at' => $syncCompletedAt,
            'latency_hours' => $lastSync?->completed_at ? round(now()->diffInMinutes($lastSync->completed_at) / 60, 1) : null,
        ];
    }
}
