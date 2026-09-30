<?php

namespace App\Services\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\ClientFindingConfiguration;
use App\Models\Finding;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FindingRuleEvaluator
{
    /**
     * Evaluate active custom findings rules for a client deterministically.
     *
     * @return int Count of new findings created.
     */
    public function evaluate(Client $client): int
    {
        $activeConfig = ClientFindingConfiguration::where('client_id', $client->id)
            ->active()
            ->first();

        if (! $activeConfig || empty($activeConfig->configuration_json['rules'])) {
            return 0;
        }

        $rules = $activeConfig->configuration_json['rules'];
        $createdCount = 0;

        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }

            try {
                $created = $this->evaluateRule($client, $rule);
                if ($created) {
                    $createdCount++;
                }
            } catch (\Throwable $e) {
                Log::error('FindingRuleEvaluator: failed to evaluate rule', [
                    'client_id' => $client->id,
                    'rule_key'  => $rule['key'] ?? 'unknown',
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $createdCount;
    }

    /**
     * Evaluate a single rule deterministically.
     */
    protected function evaluateRule(Client $client, array $rule): bool
    {
        $periodDays = (int) ($rule['period_days'] ?? 30);
        $now        = now();
        $from       = $now->copy()->subDays($periodDays)->startOfDay();
        $to         = $now->copy()->endOfDay();

        $metricAKey = $rule['metric_a'];
        $valA       = FindingMetricRegistry::resolveMetric($client, $metricAKey, $from, $to);

        if ($valA === null) {
            return false;
        }

        $operator = $rule['operator'];
        $valB     = null;

        if ($operator === 'percentage_difference') {
            $metricBKey = $rule['metric_b'];
            $valB = FindingMetricRegistry::resolveMetric($client, $metricBKey, $from, $to);
            if ($valB === null) {
                return false;
            }
        }

        // ── Quality Gates Verification ─────────────────────────────────────────
        if (! empty($rule['quality_gates'])) {
            $gates = $rule['quality_gates'];

            // 1. Minimum Revenue Gate
            if (isset($gates['minimum_revenue'])) {
                $minRev = (float) $gates['minimum_revenue'];
                $availableRev = max((float) $valA, (float) ($valB ?? 0.0));

                if ($availableRev < $minRev) {
                    Log::info("FindingRuleEvaluator: Rule [{$rule['key']}] skipped — minimum revenue gate not met", [
                        'client_id'    => $client->id,
                        'required_rev' => $minRev,
                        'avail_rev'    => $availableRev,
                    ]);
                    return false;
                }
            }

            // 2. Minimum Sessions Gate
            if (isset($gates['minimum_sessions'])) {
                $minSessions = (int) $gates['minimum_sessions'];
                $actualSessions = (int) FindingMetricRegistry::resolveMetric($client, 'ga4.sessions', $from, $to);

                if ($actualSessions < $minSessions) {
                    Log::info("FindingRuleEvaluator: Rule [{$rule['key']}] skipped — minimum sessions gate not met", [
                        'client_id'         => $client->id,
                        'required_sessions' => $minSessions,
                        'avail_sessions'    => $actualSessions,
                    ]);
                    return false;
                }
            }

            // 3. Minimum Orders Gate
            if (isset($gates['minimum_orders'])) {
                $minOrders = (int) $gates['minimum_orders'];
                $actualOrders = (int) FindingMetricRegistry::resolveMetric($client, 'shopify.orders', $from, $to);
                if ($actualOrders === 0) {
                    $actualOrders = (int) FindingMetricRegistry::resolveMetric($client, 'adobe_commerce.orders', $from, $to);
                }

                if ($actualOrders < $minOrders) {
                    Log::info("FindingRuleEvaluator: Rule [{$rule['key']}] skipped — minimum orders gate not met", [
                        'client_id'       => $client->id,
                        'required_orders' => $minOrders,
                        'avail_orders'    => $actualOrders,
                    ]);
                    return false;
                }
            }
        }

        // ── Deterministic Operator Evaluation ──────────────────────────────────
        $threshold   = (float) $rule['threshold'];
        $triggered   = false;
        $diffPct     = 0.0;
        $diffAmount  = 0.0;
        $valPrev     = null;

        switch ($operator) {
            case 'percentage_difference':
                // Metric B (e.g., Klaviyo) is the reference denominator: ABS(B - A) / B * 100
                $ref = (float) $valB;
                $diffAmount = abs($ref - (float) $valA);

                if ($ref > 0) {
                    $diffPct = round(($diffAmount / $ref) * 100, 2);
                } elseif ((float) $valA > 0) {
                    $diffPct = 100.0;
                } else {
                    $diffPct = 0.0;
                }

                $triggered = ($diffPct >= $threshold);
                break;

            case 'percentage_change':
                // Period-over-period comparison
                $fromPrev = $now->copy()->subDays($periodDays * 2)->startOfDay();
                $toPrev   = $now->copy()->subDays($periodDays + 1)->endOfDay();
                $valPrev  = (float) FindingMetricRegistry::resolveMetric($client, $metricAKey, $fromPrev, $toPrev);

                $diffAmount = abs((float) $valA - $valPrev);

                if ($valPrev > 0) {
                    $diffPct = round((((float) $valA - $valPrev) / $valPrev) * 100, 2);
                } else {
                    $diffPct = 0.0;
                }

                // If negative threshold (e.g. -15%), trigger when drop is equal or worse
                if ($threshold < 0) {
                    $triggered = ($diffPct <= $threshold);
                } else {
                    $triggered = ($diffPct >= $threshold);
                }
                break;

            case 'greater_than':
                $diffAmount = abs((float) $valA - $threshold);
                $triggered  = ((float) $valA > $threshold);
                break;

            case 'less_than':
                $diffAmount = abs((float) $valA - $threshold);
                $triggered  = ((float) $valA < $threshold);
                break;

            default:
                return false;
        }

        if (! $triggered) {
            return false;
        }

        // ── Deduplication: Skip if an open finding for this rule already exists ──
        $openFindingExists = Finding::where('client_id', $client->id)
            ->where('finding_type', $rule['key'])
            ->whereIn('status', [
                FindingStatus::New->value,
                FindingStatus::Investigating->value,
                FindingStatus::Accepted->value,
            ])
            ->exists();

        if ($openFindingExists) {
            return false;
        }

        // ── Formulate Evidence Payload ─────────────────────────────────────────
        $registered = FindingMetricRegistry::getRegisteredMetrics();
        $metaA      = $registered[$metricAKey] ?? [];
        $metaB      = $operator === 'percentage_difference' ? ($registered[$rule['metric_b']] ?? []) : null;

        $evidence = [
            'rule_key'             => $rule['key'],
            'rule_name'            => $rule['name'],
            'operator'             => $operator,
            'period_days'          => $periodDays,
            'threshold'            => $threshold,
            'metric_a'             => [
                'key'   => $metricAKey,
                'label' => $metaA['label'] ?? $metricAKey,
                'value' => round((float) $valA, 2),
            ],
            'difference_amount'    => round($diffAmount, 2),
            'difference_pct'       => round($diffPct, 2),
            'quality_gates_passed' => true,
            'sources'              => array_values(array_filter([
                $metaA['source'] ?? null,
                $metaB['source'] ?? null,
            ])),
        ];

        if ($metaB !== null) {
            $evidence['metric_b'] = [
                'key'   => $rule['metric_b'],
                'label' => $metaB['label'] ?? $rule['metric_b'],
                'value' => round((float) $valB, 2),
            ];
        }

        // ── Determine Category & Severity ──────────────────────────────────────
        $category = FindingCategory::Revenue;
        if (str_contains($metricAKey, 'conversion_rate')) {
            $category = FindingCategory::Conversion;
        }

        $severityString = strtolower($rule['severity'] ?? 'medium');
        $severity = FindingSeverity::tryFrom($severityString) ?? FindingSeverity::Medium;

        // ── Title & Description ────────────────────────────────────────────────
        $title = "{$rule['name']} ({$diffPct}% Discrepancy)";
        if ($operator === 'percentage_difference') {
            $title = "{$rule['name']} ({$diffPct}% Discrepancy)";
            $description = sprintf(
                "Over the last %d days, %s reported \$%s, while %s recorded \$%s — an attribution variance of \$%s (%.1f%%), exceeding the configured %.1f%% threshold.\n\nAttribution variance between platforms is common due to differences in attribution windows (e.g. Klaviyo 5-day click vs GA4 data-driven attribution), UTM tracking parameters, or cookie consent limitations.",
                $periodDays,
                $evidence['metric_b']['label'],
                number_format((float) $valB, 2),
                $evidence['metric_a']['label'],
                number_format((float) $valA, 2),
                number_format($diffAmount, 2),
                $diffPct,
                $threshold
            );
        } elseif ($operator === 'percentage_change') {
            $title = "{$rule['name']} ({$diffPct}%)";
            $description = sprintf(
                "%s changed by %.1f%% over the last %d days compared to the prior period (from %s to %s), crossing the configured threshold of %.1f%%.",
                $evidence['metric_a']['label'],
                $diffPct,
                $periodDays,
                number_format((float) $valPrev, 2),
                number_format((float) $valA, 2),
                $threshold
            );
        } else {
            $title = "{$rule['name']} (Value: " . round((float) $valA, 2) . ")";
            $description = sprintf(
                "%s current value is %s over the last %d days, crossing the configured %s threshold of %s.",
                $evidence['metric_a']['label'],
                round((float) $valA, 2),
                $periodDays,
                str_replace('_', ' ', $operator),
                $threshold
            );
        }

        // ── Create Deterministic Finding ───────────────────────────────────────
        Finding::create([
            'client_id'                => $client->id,
            'finding_type'             => $rule['key'],
            'finding_category'         => $category->value,
            'title'                    => $title,
            'description'              => $description,
            'severity'                 => $severity->value,
            'confidence_score'         => 0.95,
            'estimated_revenue_impact' => in_array($operator, ['percentage_difference', 'percentage_change'], true) ? $diffAmount : null,
            'status'                   => FindingStatus::New->value,
            'metadata_json'            => $evidence,
            'detected_at'              => now(),
        ]);

        return true;
    }
}
