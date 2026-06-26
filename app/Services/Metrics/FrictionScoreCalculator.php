<?php

namespace App\Services\Metrics;

use App\Models\BehavioralMetric;

class FrictionScoreCalculator
{
    /**
     * Calculate a 0–100 friction score from a behavioral metrics array.
     *
     * Formula: weighted_sum(rage_clicks, dead_clicks, quick_backs,
     *                        error_clicks, script_errors)
     * normalized by traffic count so scores are comparable across clients.
     *
     * excessive_scrolling is intentionally excluded (ambiguous signal).
     */
    public static function calculate(array $metrics): float
    {
        $weights = config('intelligence.friction_weights', [
            'rage_clicks'   => 0.30,
            'dead_clicks'   => 0.25,
            'quick_backs'   => 0.20,
            'error_clicks'  => 0.15,
            'script_errors' => 0.10,
        ]);

        $traffic = max(1, (int) ($metrics['traffic'] ?? 1));

        // Normalize each metric as a rate per 100 sessions
        $rates = [
            'rage_clicks'   => ($metrics['rage_clicks']   ?? 0) / $traffic * 100,
            'dead_clicks'   => ($metrics['dead_clicks']   ?? 0) / $traffic * 100,
            'quick_backs'   => ($metrics['quick_backs']   ?? 0) / $traffic * 100,
            'error_clicks'  => ($metrics['error_clicks']  ?? 0) / $traffic * 100,
            'script_errors' => ($metrics['script_errors'] ?? 0) / $traffic * 100,
        ];

        // Weighted sum
        $score = 0.0;
        foreach ($weights as $key => $weight) {
            $score += ($rates[$key] ?? 0) * $weight;
        }

        // Clamp to 0–100
        return (float) round(min(100.0, max(0.0, $score)), 2);
    }

    /**
     * Calculate from a BehavioralMetric Eloquent model.
     */
    public static function fromModel(BehavioralMetric $metric): float
    {
        return self::calculate([
            'rage_clicks'   => $metric->rage_clicks,
            'dead_clicks'   => $metric->dead_clicks,
            'quick_backs'   => $metric->quick_backs,
            'error_clicks'  => $metric->error_clicks,
            'script_errors' => $metric->script_errors,
            'traffic'       => $metric->traffic,
        ]);
    }
}
