<?php

namespace App\Services\Intelligence\DataQualification;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DataQualificationService
{
    private SampleSufficiencyEvaluator $sampleEvaluator;
    private DataCompletenessGuard $completenessGuard;
    private TrafficQualityClassifier $trafficClassifier;

    public function __construct(
        ?SampleSufficiencyEvaluator $sampleEvaluator = null,
        ?DataCompletenessGuard $completenessGuard = null,
        ?TrafficQualityClassifier $trafficClassifier = null
    ) {
        $this->sampleEvaluator = $sampleEvaluator ?? new SampleSufficiencyEvaluator();
        $this->completenessGuard = $completenessGuard ?? new DataCompletenessGuard();
        $this->trafficClassifier = $trafficClassifier ?? new TrafficQualityClassifier();
    }

    /**
     * Qualify data before running change detection or rule evaluations.
     *
     * @param Client $client
     * @param string $metricKey
     * @param Carbon $from
     * @param Carbon $to
     * @param float $currentValue
     * @param float $previousValue
     * @param Collection|array $metricRecords
     * @param array $context
     * @return array
     */
    public function qualify(
        Client $client,
        string $metricKey,
        Carbon $from,
        Carbon $to,
        float $currentValue,
        float $previousValue,
        $metricRecords = [],
        array $context = []
    ): array {
        $expectedDays = (int) ($context['expected_days'] ?? max(1, round($from->diffInDays($to)) + 1));

        // 1. Evaluate completeness & data lineage
        $completeness = $this->completenessGuard->evaluate($from, $to, $metricRecords, $expectedDays);

        // 2. Evaluate sample sufficiency
        $sampleSufficiency = $this->sampleEvaluator->evaluate(
            $client,
            $metricKey,
            $currentValue,
            $previousValue,
            $context
        );

        // 3. Evaluate traffic pattern (if session or conversion metric)
        $trafficQuality = null;
        if (str_contains($metricKey, 'session') || str_contains($metricKey, 'conversion') || str_contains($metricKey, 'traffic')) {
            $currSessions = $context['current_sessions'] ?? $currentValue;
            $prevSessions = $context['previous_sessions'] ?? $previousValue;
            $trafficBreakdown = $context['traffic_breakdown'] ?? [];
            $orders = $context['orders'] ?? 0;

            $trafficQuality = $this->trafficClassifier->classify(
                $currSessions,
                $prevSessions,
                $trafficBreakdown,
                $orders
            );
        }

        // Composite decision
        $disqualificationReasons = [];
        if (!$sampleSufficiency['is_sufficient']) {
            $disqualificationReasons[] = $sampleSufficiency['rationale'];
        }
        if ($completeness['includes_current_day']) {
            $disqualificationReasons[] = 'Excluding incomplete current reporting day from trend evaluation.';
        }

        $overallModifier = $sampleSufficiency['confidence_modifier'] * $completeness['confidence_modifier'];
        if ($trafficQuality) {
            $overallModifier *= $trafficQuality['confidence_modifier'];
        }

        $isQualified = empty($disqualificationReasons);
        $status = $isQualified ? 'qualified' : 'disqualified';
        if ($isQualified && ($overallModifier < 0.90 || $completeness['has_estimated_data'])) {
            $status = 'qualified_with_caveats';
        }

        return [
            'is_qualified' => $isQualified,
            'status' => $status,
            'confidence_modifier' => round(max(0.1, min(1.0, $overallModifier)), 2),
            'disqualification_reasons' => $disqualificationReasons,
            'sample_sufficiency' => $sampleSufficiency,
            'completeness' => $completeness,
            'traffic_quality' => $trafficQuality,
        ];
    }
}
