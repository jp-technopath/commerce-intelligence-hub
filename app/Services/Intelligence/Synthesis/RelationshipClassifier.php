<?php

namespace App\Services\Intelligence\Synthesis;

class RelationshipClassifier
{
    public const INDEPENDENT = 'independent';
    public const RELATED = 'related';
    public const POSSIBLE_SHARED_CAUSE = 'possible_shared_cause';
    public const CONFIRMED_SHARED_CAUSE = 'confirmed_shared_cause';

    /**
     * Classify relationship between two co-occurring anomaly signals.
     *
     * @param array $signalA Primary signal details (family, scope_type, scope_target, metric)
     * @param array $signalB Secondary signal details (family, scope_type, scope_target, metric)
     * @param array $evidence Any direct causal evidence (e.g. error traces, URL match, funnel drop)
     * @return array
     */
    public function classify(array $signalA, array $signalB, array $evidence = []): array
    {
        $familyA = $signalA['family'] ?? '';
        $familyB = $signalB['family'] ?? '';
        $targetA = $signalA['scope_target'] ?? '';
        $targetB = $signalB['scope_target'] ?? '';

        $hasDirectCausalTrace = $evidence['direct_causal_trace'] ?? false;
        $sharesUrlPath = !empty($targetA) && !empty($targetB) && ($targetA === $targetB || str_contains($targetA, $targetB) || str_contains($targetB, $targetA));

        // 1. Direct Causal Trace -> Confirmed Shared Cause
        if ($hasDirectCausalTrace) {
            return [
                'relationship' => self::CONFIRMED_SHARED_CAUSE,
                'confidence' => 0.95,
                'rationale' => 'Direct causal trace connects signal A and signal B (e.g. checkout API failure directly causing order loss).',
                'combine_into_single_incident' => true,
            ];
        }

        // 2. Shared Scope (e.g. same page or same checkout funnel step)
        if ($sharesUrlPath) {
            return [
                'relationship' => self::POSSIBLE_SHARED_CAUSE,
                'confidence' => 0.80,
                'rationale' => sprintf(
                    'Signals share affected scope (%s). Plausible interaction between %s and %s on this page.',
                    $targetA,
                    $familyA,
                    $familyB
                ),
                'combine_into_single_incident' => true,
            ];
        }

        // 3. Technical script error on specific page vs site-wide traffic surge
        $isPageSpecificTechnical = str_contains($familyA, 'technical') && ($signalA['scope_type'] ?? '') === 'page';
        $isGlobalTraffic = str_contains($familyB, 'traffic') && ($signalB['scope_type'] ?? '') !== 'page';

        if (($isPageSpecificTechnical && $isGlobalTraffic) || (str_contains($familyB, 'technical') && str_contains($familyA, 'traffic'))) {
            return [
                'relationship' => self::INDEPENDENT,
                'confidence' => 0.88,
                'rationale' => 'Co-occurring in time, but page-specific script error and global traffic surge represent distinct scopes and mechanisms. Must be tracked as separate, independently actionable incidents.',
                'combine_into_single_incident' => false,
            ];
        }

        // 4. Default: Related co-occurrence
        return [
            'relationship' => self::RELATED,
            'confidence' => 0.70,
            'rationale' => 'Signals occurred during the same evaluation period. Concurrent observation without confirmed shared mechanism.',
            'combine_into_single_incident' => false,
        ];
    }
}
