<?php

namespace App\Services\Intelligence\DataQualification;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class DataCompletenessGuard
{
    /**
     * Inspects data completeness, excluding incomplete current days and evaluating quality lineage.
     *
     * @param Carbon $from
     * @param Carbon $to
     * @param Collection|array $metricRecords
     * @param int $expectedDays
     * @return array
     */
    public function evaluate(Carbon $from, Carbon $to, $metricRecords, int $expectedDays = 0): array
    {
        $now = Carbon::now();
        $records = $metricRecords instanceof Collection ? $metricRecords : collect($metricRecords);

        // 1. Current Incomplete Day Guard
        $includesCurrentIncompleteDay = $to->isSameDay($now) || $to->isAfter($now);
        $currentDayNotice = null;
        if ($includesCurrentIncompleteDay) {
            $currentDayNotice = 'Evaluation window includes current partial day (' . $to->toDateString() . '). Partial days should be excluded from trend comparisons.';
        }

        // 2. Backward-compatible Data Quality Audit
        $totalRecords = $records->count();
        $verifiedCount = 0;
        $estimatedCount = 0;
        $rollingAggregateCount = 0;
        $incompleteCount = 0;

        foreach ($records as $record) {
            $meta = is_array($record) ? ($record['metadata_json'] ?? []) : ($record->metadata_json ?? []);
            if (is_string($meta)) {
                $meta = json_decode($meta, true) ?? [];
            }

            // Backward-compatible defaults:
            // If explicit data_status exists, use it; otherwise infer or default to 'observed'
            $status = $meta['data_status'] ?? null;
            $isVerified = $meta['is_verified'] ?? null;
            $isRolling = $meta['is_rolling_aggregate'] ?? false;

            if ($status === 'estimated' || $isVerified === false) {
                $estimatedCount++;
            } elseif ($status === 'incomplete') {
                $incompleteCount++;
            } elseif ($isRolling || ($status === 'rolling_aggregate')) {
                $rollingAggregateCount++;
            } else {
                // If keys are absent, default to verified observed data
                $verifiedCount++;
            }
        }

        $coverageRatio = $expectedDays > 0 ? min(1.0, $totalRecords / $expectedDays) : 1.0;
        $isSufficientCoverage = $expectedDays <= 0 || ($totalRecords >= max(1, (int) ceil($expectedDays * 0.70)));

        $hasEstimatedData = $estimatedCount > 0;
        $confidenceModifier = 1.0;

        if ($hasEstimatedData) {
            // Slight confidence discount for estimated historical data
            $confidenceModifier *= 0.90;
        }

        if (!$isSufficientCoverage) {
            $confidenceModifier *= 0.70;
        }

        if ($includesCurrentIncompleteDay) {
            $confidenceModifier *= 0.80;
        }

        return [
            'is_complete' => $isSufficientCoverage && !$includesCurrentIncompleteDay,
            'includes_current_day' => $includesCurrentIncompleteDay,
            'current_day_notice' => $currentDayNotice,
            'coverage_ratio' => round($coverageRatio, 2),
            'records_found' => $totalRecords,
            'expected_days' => $expectedDays,
            'quality_breakdown' => [
                'verified' => $verifiedCount,
                'estimated' => $estimatedCount,
                'rolling_aggregate' => $rollingAggregateCount,
                'incomplete' => $incompleteCount,
            ],
            'has_estimated_data' => $hasEstimatedData,
            'confidence_modifier' => round($confidenceModifier, 2),
            'rationale' => $this->buildRationale($isSufficientCoverage, $includesCurrentIncompleteDay, $hasEstimatedData, $totalRecords, $expectedDays),
        ];
    }

    private function buildRationale(bool $sufficient, bool $hasToday, bool $hasEstimated, int $found, int $expected): string
    {
        $parts = [];
        if ($hasToday) {
            $parts[] = 'Current day is incomplete';
        }
        if (!$sufficient && $expected > 0) {
            $parts[] = "Insufficient historical coverage ({$found}/{$expected} days)";
        }
        if ($hasEstimated) {
            $parts[] = 'Window contains mathematically estimated records';
        }

        if (empty($parts)) {
            return 'Data coverage and quality are fully complete.';
        }

        return implode('; ', $parts) . '.';
    }
}
