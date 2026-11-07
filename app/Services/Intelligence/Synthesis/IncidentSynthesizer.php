<?php

namespace App\Services\Intelligence\Synthesis;

use Carbon\Carbon;

class IncidentSynthesizer
{
    private IncidentFingerprintGenerator $fingerprintGenerator;
    private RelationshipClassifier $relationshipClassifier;

    public function __construct(
        ?IncidentFingerprintGenerator $fingerprintGenerator = null,
        ?RelationshipClassifier $relationshipClassifier = null
    ) {
        $this->fingerprintGenerator = $fingerprintGenerator ?? new IncidentFingerprintGenerator();
        $this->relationshipClassifier = $relationshipClassifier ?? new RelationshipClassifier();
    }

    /**
     * Synthesize raw finding signals into structured incidents without assuming causation.
     *
     * @param int $clientId
     * @param array $rawSignals List of raw detected signals/anomalies
     * @param Carbon $detectedDate
     * @param array $existingEpisodes Active episodes for deduplication
     * @return array Array of synthesized incidents and cross-incident relationships
     */
    public function synthesize(
        int $clientId,
        array $rawSignals,
        Carbon $detectedDate,
        array $existingEpisodes = []
    ): array {
        if (empty($rawSignals)) {
            return [
                'incidents' => [],
                'cross_relationships' => [],
                'deduplication_summary' => [
                    'raw_signals_count' => 0,
                    'incidents_emitted' => 0,
                    'duplicates_suppressed' => 0,
                ],
            ];
        }

        // 1. Assign stable fingerprints to each signal
        $fingerprinted = [];
        $seenFingerprints = [];
        $duplicatesSuppressed = 0;

        foreach ($rawSignals as $signal) {
            $family = $signal['family'] ?? 'general';
            $scopeType = $signal['scope_type'] ?? 'global';
            $scopeTarget = $signal['scope_target'] ?? 'storewide';

            $signalDate = !empty($signal['detected_at']) ? Carbon::parse($signal['detected_at']) : $detectedDate;

            $fpData = $this->fingerprintGenerator->generate(
                $clientId,
                $family,
                $scopeType,
                $scopeTarget,
                $signalDate,
                $existingEpisodes
            );

            $fp = $fpData['fingerprint'];

            // Deduplication across overlapping windows:
            // If the same base_scope_key is already present in this evaluation pass, suppress duplicate
            if (isset($seenFingerprints[$fpData['base_scope_key']])) {
                $duplicatesSuppressed++;
                continue;
            }

            $seenFingerprints[$fpData['base_scope_key']] = true;

            $fingerprinted[] = array_merge($signal, [
                'fingerprint' => $fp,
                'episode_id' => $fpData['episode_id'],
                'lifecycle_state' => $fpData['lifecycle_state'],
                'base_scope_key' => $fpData['base_scope_key'],
            ]);
        }

        // 2. Classify relationships across signals
        $incidents = [];
        $crossRelationships = [];
        $mergedKeys = [];

        $count = count($fingerprinted);
        for ($i = 0; $i < $count; $i++) {
            if (isset($mergedKeys[$i])) {
                continue;
            }

            $sigA = $fingerprinted[$i];
            $currentIncidentSignals = [$sigA];

            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($mergedKeys[$j])) {
                    continue;
                }

                $sigB = $fingerprinted[$j];
                $rel = $this->relationshipClassifier->classify($sigA, $sigB, [
                    'direct_causal_trace' => ($sigA['evidence']['direct_causal_trace'] ?? false) || ($sigB['evidence']['direct_causal_trace'] ?? false),
                ]);

                if ($rel['combine_into_single_incident']) {
                    // Causal or shared-scope signals combined into single incident
                    $currentIncidentSignals[] = array_merge($sigB, [
                        'synthesis_role' => 'contributing_symptom',
                        'relationship_to_root' => $rel['relationship'],
                        'relationship_rationale' => $rel['rationale'],
                    ]);
                    $mergedKeys[$j] = true;
                } else {
                    // Independent or related signals remain separate, cross-referenced
                    $crossRelationships[] = [
                        'signal_a_fingerprint' => $sigA['fingerprint'],
                        'signal_b_fingerprint' => $sigB['fingerprint'],
                        'relationship' => $rel['relationship'],
                        'rationale' => $rel['rationale'],
                    ];
                }
            }

            // Build incident package
            $primarySignal = $currentIncidentSignals[0];
            $isIndependent = count($currentIncidentSignals) === 1;

            $incidents[] = [
                'incident_id' => $primarySignal['fingerprint'],
                'fingerprint' => $primarySignal['fingerprint'],
                'base_scope_key' => $primarySignal['base_scope_key'],
                'client_id' => $clientId,
                'title' => $this->formatIncidentTitle($primarySignal, count($currentIncidentSignals)),
                'family' => $primarySignal['family'],
                'scope_type' => $primarySignal['scope_type'],
                'scope_target' => $primarySignal['scope_target'],
                'episode_id' => $primarySignal['episode_id'],
                'lifecycle_state' => $primarySignal['lifecycle_state'],
                'detected_at' => $detectedDate->toDateTimeString(),
                'primary_signal' => $primarySignal,
                'supporting_signals' => array_slice($currentIncidentSignals, 1),
                'actionability' => $this->determineActionability($primarySignal),
                'is_independently_actionable' => true,
            ];
        }

        return [
            'incidents' => $incidents,
            'cross_relationships' => $crossRelationships,
            'deduplication_summary' => [
                'raw_signals_count' => count($rawSignals),
                'incidents_emitted' => count($incidents),
                'duplicates_suppressed' => $duplicatesSuppressed,
            ],
        ];
    }

    private function formatIncidentTitle(array $signal, int $signalCount): string
    {
        $rawTitle = $signal['title'] ?? 'Incident Alert';
        if ($signalCount > 1) {
            return $rawTitle . sprintf(' (+%d correlated symptoms)', $signalCount - 1);
        }
        return $rawTitle;
    }

    private function determineActionability(array $signal): array
    {
        $family = $signal['family'] ?? '';
        $target = $signal['scope_target'] ?? '';

        if (str_contains($family, 'technical')) {
            return [
                'type' => 'technical_remediation',
                'description' => "Investigate and resolve front-end exception on {$target}.",
                'target_team' => 'engineering',
            ];
        }

        if (str_contains($family, 'attribution')) {
            return [
                'type' => 'marketing_tracking_configuration',
                'description' => 'Verify campaign UTM tagging and cross-channel tracking parameters.',
                'target_team' => 'marketing_operations',
            ];
        }

        if (str_contains($family, 'traffic')) {
            return [
                'type' => 'traffic_quality_investigation',
                'description' => "Investigate sudden traffic pattern shift on {$target}. Assess referral source and intent.",
                'target_team' => 'analytics',
            ];
        }

        return [
            'type' => 'diagnostic_monitoring',
            'description' => 'Monitor metric trend against baseline.',
            'target_team' => 'e_commerce',
        ];
    }
}
