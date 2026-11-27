<?php

namespace App\Services\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\ClientFindingConfiguration;
use App\Models\CommerceMetric;
use App\Models\Finding;
use App\Services\Metrics\MetricsDiagnosticService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    public function evaluateRule(Client $client, array $rule): bool
    {
        $periodDays = (int) ($rule['period_days'] ?? 30);
        $now        = now();
        $from       = $now->copy()->subDays($periodDays)->startOfDay();
        $to         = $now->copy()->subDay()->endOfDay();

        // Clamp window to shared available GA4 date if rule uses GA4 to avoid partial-day zeros
        if (str_contains((string) $rule['metric_a'], 'ga4.') || str_contains((string) ($rule['metric_b'] ?? ''), 'ga4.')) {
            $maxGa4 = CommerceMetric::where('client_id', $client->id)->where('source', 'ga4')->max('date');
            if ($maxGa4) {
                $to = Carbon::parse($maxGa4)->endOfDay();
                $from = $to->copy()->subDays(max(0, $periodDays - 1))->startOfDay();
            }
        }

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
                // Period-over-period comparison (disjoint preceding window of equal length)
                $toPrev   = $from->copy()->subDay()->endOfDay();
                $fromPrev = $toPrev->copy()->subDays(max(0, $periodDays - 1))->startOfDay();
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
            'source_window_start'  => $from->toDateString(),
            'source_window_end'    => $to->toDateString(),
            'evaluated_at'         => $now->toIso8601String(),
            'metric_a'             => [
                'key'   => $metricAKey,
                'label' => $metaA['label'] ?? $metricAKey,
                'value' => round((float) $valA, 2),
            ],
            'difference_amount'    => round($diffAmount, 2),
            'difference_pct'       => round($diffPct, 2),
            'quality_gates_passed' => true,
            'sources'              => array_values(array_unique(array_filter([
                $metaA['source'] ?? null,
                $metaB['source'] ?? null,
            ]))),
        ];

        if ($valPrev !== null) {
            $evidence['prior_value'] = round((float) $valPrev, 2);
            $evidence['prior_window_start'] = $fromPrev->toDateString();
            $evidence['prior_window_end'] = $toPrev->toDateString();
        }

        if ($metaB !== null) {
            $evidence['metric_b'] = [
                'key'   => $rule['metric_b'],
                'label' => $metaB['label'] ?? $rule['metric_b'],
                'value' => round((float) $valB, 2),
            ];
        }

        // Attach scenario-specific authoritative diagnostics via shared analytical services
        $diagService = new MetricsDiagnosticService();
        if ($operator === 'percentage_change' && str_contains($metricAKey, 'conversion_rate')) {
            $evidence['conversion_diagnostics'] = $diagService->buildConversionWindowDiagnostics($client, $from, $to, $fromPrev, $toPrev);
        } elseif ($operator === 'percentage_difference' && (str_contains($metricAKey, 'klaviyo') || str_contains((string) ($rule['metric_b'] ?? ''), 'klaviyo'))) {
            $evidence['klaviyo_diagnostics'] = $diagService->buildKlaviyoAttributionDiagnostics($client, $from, $to);
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

        // ── Calculate Defensible Revenue Impact & Dynamic Confidence ──────────
        $confidenceScore = round(min(0.95, max(0.60, 0.75 + (($diffPct - abs($threshold)) / max(1.0, abs($threshold))) * 0.15)), 2);

        $estimatedRevenueImpact = null;
        $impactCalculation = [
            'modeled'     => false,
            'amount'      => null,
            'assumptions' => null,
            'reason'      => null,
        ];

        if ($operator === 'percentage_difference') {
            // Cross-platform attribution or tracking discrepancies are reporting variances, not lost revenue.
            $impactCalculation['reason'] = 'Cross-platform attribution and tracking variance represents reporting alignment differences, not lost revenue.';
        } elseif ($operator === 'percentage_change') {
            $isRevenueMetric = str_contains($metricAKey, 'revenue');
            $isOrderMetric   = str_contains($metricAKey, 'orders');
            $isCvrMetric     = str_contains($metricAKey, 'conversion_rate');

            if ($isRevenueMetric) {
                // Direct revenue drop
                if ($valPrev > $valA) {
                    $estimatedRevenueImpact = round((float) $valPrev - (float) $valA, 2);
                    $impactCalculation = [
                        'modeled'     => false,
                        'amount'      => $estimatedRevenueImpact,
                        'formula'     => 'val_prev - val_current',
                        'assumptions' => 'Observed period-over-period direct revenue delta.',
                    ];
                }
            } elseif ($isOrderMetric && $valPrev > $valA) {
                // Modeled lost orders * baseline AOV
                $orderDrop = (float) $valPrev - (float) $valA;
                $baselineAov = CommerceMetric::where('client_id', $client->id)
                    ->whereBetween('date', [$fromPrev, $toPrev])
                    ->where('orders', '>', 0)
                    ->avg('aov');

                if ($baselineAov && $baselineAov > 0) {
                    $estimatedRevenueImpact = round($orderDrop * $baselineAov, 2);
                    $impactCalculation = [
                        'modeled'      => true,
                        'amount'       => $estimatedRevenueImpact,
                        'formula'      => 'lost_orders * baseline_aov',
                        'baseline_aov' => round($baselineAov, 2),
                        'assumptions'  => sprintf('Modeled estimate: %.0f lost orders multiplied by prior period AOV of $%.2f.', $orderDrop, $baselineAov),
                    ];
                } else {
                    $impactCalculation['reason'] = 'Baseline AOV could not be determined defensibly.';
                }
            } elseif ($isCvrMetric && $valPrev > $valA) {
                // Check if traffic conditions are stable before dollarizing conversion changes
                $source = str_contains($metricAKey, 'ga4') ? 'ga4' : 'adobe_commerce';
                $curSessions = (int) CommerceMetric::where('client_id', $client->id)
                    ->where('source', $source)
                    ->whereBetween('date', [$from, $to])
                    ->sum('sessions');

                $prevSessions = (int) CommerceMetric::where('client_id', $client->id)
                    ->where('source', $source)
                    ->whereBetween('date', [$fromPrev, $toPrev])
                    ->sum('sessions');

                $sessionChangePct = $prevSessions > 0 ? abs(($curSessions - $prevSessions) / $prevSessions) * 100 : 100;

                if ($sessionChangePct > 50.0 || $prevSessions < 100) {
                    $impactCalculation['reason'] = sprintf(
                        'Conversion rate change occurred during volatile traffic conditions (sessions changed by %.1f%%). Cannot defensibly dollarize without distorting estimates.',
                        $sessionChangePct
                    );
                } else {
                    $baselineAov = CommerceMetric::where('client_id', $client->id)
                        ->whereBetween('date', [$fromPrev, $toPrev])
                        ->where('orders', '>', 0)
                        ->avg('aov');

                    if ($baselineAov && $baselineAov > 0) {
                        $cvrDelta = ($valPrev - $valA) / 100.0;
                        $estimatedRevenueImpact = round($cvrDelta * $curSessions * $baselineAov, 2);
                        $impactCalculation = [
                            'modeled'      => true,
                            'amount'       => $estimatedRevenueImpact,
                            'formula'      => '(cvr_prev - cvr_current) * current_sessions * prior_aov',
                            'baseline_aov' => round($baselineAov, 2),
                            'assumptions'  => sprintf('Modeled estimate under stable traffic (+/-%.1f%%): lost conversions (%.4f) * %d sessions * $%.2f baseline AOV.', $sessionChangePct, $cvrDelta, $curSessions, $baselineAov),
                        ];
                    } else {
                        $impactCalculation['reason'] = 'Baseline AOV unavailable for conversion loss modeling.';
                    }
                }
            } else {
                $impactCalculation['reason'] = 'Metric does not represent a direct revenue shortfall.';
            }
        } else {
            $impactCalculation['reason'] = 'Threshold rule operator does not support defensible revenue loss estimation.';
        }

        $evidence['revenue_impact_calculation'] = $impactCalculation;

        $classification = FindingTypeRegistry::classifyByDescriptor(
            findingType: $rule['key'],
            ruleKey: $rule['key'],
            metricKey: $metricAKey,
            operator: $operator,
            differencePct: $operator === 'percentage_change' ? (float) $diffPct : null
        );

        // ── Persist or refresh inside distributed Cache::lock + DB transaction ─────────────
        $lock = Cache::lock("forge:rule_eval:client:{$client->id}:{$rule['key']}", 30);

        return $lock->block(5, function () use ($client, $rule, $category, $title, $description, $severity, $confidenceScore, $estimatedRevenueImpact, $evidence, $classification, $to, $now) {
            return DB::transaction(function () use ($client, $rule, $category, $title, $description, $severity, $confidenceScore, $estimatedRevenueImpact, $evidence, $classification, $to, $now) {
                $existing = Finding::where('client_id', $client->id)
                    ->where('finding_type', $rule['key'])
                    ->openForDeduplication()
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->first();

                if ($existing) {
                    $oldMeta = is_array($existing->metadata_json) ? $existing->metadata_json : [];
                    $oldWindowEnd = $oldMeta['source_window_end'] ?? ($existing->evidence_json['source_window_end'] ?? null);
                    $newWindowEnd = $to->toDateString();
                    $isNewerWindow = ! $oldWindowEnd || strcmp($newWindowEnd, $oldWindowEnd) > 0;

                    $history = is_array($oldMeta['observation_history'] ?? null) ? $oldMeta['observation_history'] : [];
                    if ($isNewerWindow && $oldWindowEnd) {
                        $history[] = [
                            'observed_at'         => $oldWindowEnd,
                            'source_window_start' => $oldMeta['source_window_start'] ?? null,
                            'source_window_end'   => $oldWindowEnd,
                            'metric_a'            => $oldMeta['metric_a'] ?? null,
                            'metric_b'            => $oldMeta['metric_b'] ?? null,
                            'difference_pct'      => $oldMeta['difference_pct'] ?? null,
                        ];
                        $history = array_slice($history, -10);
                    }

                    $effectiveWindowStart = $isNewerWindow
                        ? ($evidence['source_window_start'] ?? ($oldMeta['source_window_start'] ?? null))
                        : ($oldMeta['source_window_start'] ?? ($evidence['source_window_start'] ?? null));
                    $effectiveWindowEnd = $isNewerWindow ? $newWindowEnd : $oldWindowEnd;
                    $effectiveLastObserved = $isNewerWindow
                        ? ($newWindowEnd . 'T23:59:59+00:00')
                        : ($oldMeta['last_observed_at'] ?? ($effectiveWindowEnd . 'T23:59:59+00:00'));

                    $existing->update([
                        'finding_pillar'           => $existing->finding_pillar?->value ?? $classification['pillar_value'],
                        'title'                    => $title,
                        'description'              => $description,
                        'severity'                 => $severity->value,
                        'confidence_score'         => $confidenceScore,
                        'estimated_revenue_impact' => $estimatedRevenueImpact,
                        'evidence_json'            => array_merge($evidence, [
                            'source_window_start'   => $effectiveWindowStart,
                            'source_window_end'     => $effectiveWindowEnd,
                            'observation_refreshed' => $isNewerWindow,
                        ]),
                        'metadata_json'            => array_merge($oldMeta, $evidence, [
                            'source_window_start' => $effectiveWindowStart,
                            'source_window_end'   => $effectiveWindowEnd,
                            'observation_history' => $history,
                            'last_observed_at'    => $effectiveLastObserved,
                        ]),
                    ]);

                    return false;
                }

                Finding::create([
                    'client_id'                => $client->id,
                    'finding_type'             => $rule['key'],
                    'finding_pillar'           => $classification['pillar_value'],
                    'finding_category'         => $category->value,
                    'target_role'              => $classification['target_role'],
                    'title'                    => $title,
                    'description'              => $description,
                    'severity'                 => $severity->value,
                    'confidence_score'         => $confidenceScore,
                    'estimated_revenue_impact' => $estimatedRevenueImpact,
                    'status'                   => FindingStatus::New->value,
                    'evidence_json'            => array_merge($evidence, ['observation_refreshed' => true]),
                    'metadata_json'            => array_merge($evidence, [
                        'last_observed_at' => $to->toDateString() . 'T23:59:59+00:00',
                    ]),
                    'detected_at'              => $now,
                ]);

                return true;
            });
        });
    }
}
