<?php

namespace App\Services\Intelligence\DataQualification;

class TrafficQualityClassifier
{
    /**
     * Classify traffic patterns without prematurely labeling suspicious traffic as bots.
     * Preserves contractual conversion metric definitions while providing auxiliary qualified metrics.
     *
     * @param float $currentSessions
     * @param float $previousSessions
     * @param array $trafficBreakdown e.g., channel breakdown, bounce rate, avg duration
     * @param int $orders Total orders in period
     * @return array
     */
    public function classify(
        float $currentSessions,
        float $previousSessions,
        array $trafficBreakdown = [],
        int $orders = 0
    ): array {
        $trafficChangePct = $previousSessions > 0
            ? (($currentSessions - $previousSessions) / $previousSessions) * 100
            : 0;

        $directShare = $trafficBreakdown['direct_share_pct'] ?? 0;
        $directSessions = $trafficBreakdown['direct_sessions'] ?? 0;
        $avgDuration = $trafficBreakdown['avg_engagement_time'] ?? null;

        $isSurge = $trafficChangePct >= 75.0 && $currentSessions >= 100;
        $isDisproportionateDirect = $directShare >= 70.0 || ($isSurge && $directSessions >= ($currentSessions * 0.65));

        // Contractual Conversion Rate (standard contractual definition MUST NOT be modified)
        $contractualCvr = $currentSessions > 0 ? ($orders / $currentSessions) * 100 : 0.0;

        $classification = 'normal_traffic';
        $requiresInvestigation = false;
        $rationale = 'Traffic pattern is consistent with expected customer behavior.';
        $confidenceModifier = 1.0;

        if ($isSurge && $isDisproportionateDirect) {
            $classification = 'suspicious_traffic_surge_requiring_investigation';
            $requiresInvestigation = true;
            $confidenceModifier = 0.75;
            $rationale = sprintf(
                'Sudden traffic increase (+%.1f%%) dominated by direct visits (%.1f%%). Requires investigation into source and referral parameters; NOT confirmed automated/bot activity.',
                $trafficChangePct,
                $directShare > 0 ? $directShare : ($directSessions / max(1, $currentSessions)) * 100
            );
        } elseif ($isSurge) {
            $classification = 'unverified_traffic_expansion';
            $requiresInvestigation = true;
            $confidenceModifier = 0.85;
            $rationale = sprintf(
                'Traffic expanded by +%.1f%% across multiple channels. Conversion changes during this period should be interpreted alongside audience intent.',
                $trafficChangePct
            );
        }

        // Auxiliary Qualified Traffic Metric (non-contractual diagnostic aid)
        $suspiciousVolumeEstimate = $requiresInvestigation ? max(0, $currentSessions - $previousSessions) : 0;
        $qualifiedSessions = max(1, $currentSessions - $suspiciousVolumeEstimate);
        $qualifiedCvrAuxiliary = ($orders / $qualifiedSessions) * 100;

        return [
            'classification' => $classification,
            'requires_investigation' => $requiresInvestigation,
            'confidence_modifier' => $confidenceModifier,
            'rationale' => $rationale,
            'traffic_change_pct' => round($trafficChangePct, 2),
            // Contractual metric:
            'contractual_conversion_rate' => round($contractualCvr, 4),
            // Auxiliary diagnostic metrics:
            'auxiliary_metrics' => [
                'has_auxiliary_metrics' => $requiresInvestigation,
                'estimated_anomalous_sessions' => (int) $suspiciousVolumeEstimate,
                'qualified_baseline_sessions' => (int) $qualifiedSessions,
                'qualified_conversion_rate_diagnostic' => round($qualifiedCvrAuxiliary, 4),
                'notice' => 'Contractual conversion rate uses total sessions. Qualified CVR is an auxiliary diagnostic aid.',
            ],
        ];
    }
}
