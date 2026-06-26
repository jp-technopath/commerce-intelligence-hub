<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\ClientHealthScore;
use App\Enums\RiskLevel;

/**
 * Health Score Calculator — Phase 4
 *
 * Computes a daily health score (0-100) for each client based on:
 *   - Revenue trend (weight: 0.35)
 *   - Conversion trend (weight: 0.30)
 *   - Clarity friction score (weight: 0.20)
 *   - Open findings severity (weight: 0.15)
 *
 * Weights and risk level thresholds are configurable in config/intelligence.php.
 * Score is stored in client_health_scores with a full breakdown_json.
 */
class HealthScoreCalculator
{
    public function calculateForClient(Client $client, \DateTimeInterface $date): ClientHealthScore
    {
        // TODO: Phase 4 — implement weighted health scoring
        throw new \RuntimeException('HealthScoreCalculator not yet implemented. Scheduled for Phase 4.');
    }
}
