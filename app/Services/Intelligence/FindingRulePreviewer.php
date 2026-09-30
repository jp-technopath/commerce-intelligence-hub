<?php

namespace App\Services\Intelligence;

class FindingRulePreviewer
{
    /**
     * Generate plain-English preview for a validated configuration.
     *
     * @return array<int, array{key: string, name: string, enabled: bool, severity: string, plain_english: string, details: array}>
     */
    public function generatePreview(array $configData): array
    {
        $rules = $configData['rules'] ?? [];
        $registered = FindingMetricRegistry::getRegisteredMetrics();
        $preview = [];

        foreach ($rules as $rule) {
            $name     = $rule['name'] ?? 'Unnamed Rule';
            $enabled  = (bool) ($rule['enabled'] ?? true);
            $operator = $rule['operator'] ?? 'unknown';
            $period   = (int) ($rule['period_days'] ?? 30);
            $thresh   = $rule['threshold'] ?? 0;
            $severity = ucfirst(strtolower($rule['severity'] ?? 'medium'));

            $metricALabel = $registered[$rule['metric_a']]['label'] ?? ($rule['metric_a'] ?? 'Unknown Metric A');
            $metricBLabel = isset($rule['metric_b']) ? ($registered[$rule['metric_b']]['label'] ?? $rule['metric_b']) : null;

            $lines = [];
            $lines[] = "• **Status**: " . ($enabled ? "Active / Enabled" : "Disabled (will be skipped)");
            $lines[] = "• **Evaluation Period**: Last {$period} days";

            switch ($operator) {
                case 'percentage_difference':
                    $lines[] = "• **Comparison**: {$metricALabel} vs. {$metricBLabel}";
                    $lines[] = "• **Condition**: Trigger when the percentage difference exceeds {$thresh}%";
                    break;

                case 'percentage_change':
                    $dir = (float) $thresh < 0 ? "decreases by more than " . abs($thresh) . "%" : "increases by more than {$thresh}%";
                    $lines[] = "• **Metric**: {$metricALabel}";
                    $lines[] = "• **Condition**: Trigger when value {$dir} compared to the previous {$period}-day period";
                    break;

                case 'greater_than':
                    $lines[] = "• **Metric**: {$metricALabel}";
                    $lines[] = "• **Condition**: Trigger when value is greater than {$thresh}";
                    break;

                case 'less_than':
                    $lines[] = "• **Metric**: {$metricALabel}";
                    $lines[] = "• **Condition**: Trigger when value is less than {$thresh}";
                    break;

                default:
                    $lines[] = "• **Condition**: {$operator} {$thresh}";
                    break;
            }

            $lines[] = "• **Severity**: {$severity}";

            if (! empty($rule['quality_gates'])) {
                $gates = [];
                if (isset($rule['quality_gates']['minimum_revenue'])) {
                    $gates[] = "At least \$" . number_format($rule['quality_gates']['minimum_revenue'], 2) . " in revenue";
                }
                if (isset($rule['quality_gates']['minimum_sessions'])) {
                    $gates[] = "At least " . number_format($rule['quality_gates']['minimum_sessions']) . " sessions";
                }
                if (isset($rule['quality_gates']['minimum_orders'])) {
                    $gates[] = "At least " . number_format($rule['quality_gates']['minimum_orders']) . " orders";
                }

                if (! empty($gates)) {
                    $lines[] = "• **Quality Gates**: " . implode('; ', $gates) . " (skips evaluation if not met to prevent false alarms)";
                }
            }

            $preview[] = [
                'key'           => $rule['key'] ?? '',
                'name'          => $name,
                'enabled'       => $enabled,
                'severity'      => $severity,
                'plain_english' => implode("\n", $lines),
                'details'       => [
                    'metric_a'      => $metricALabel,
                    'metric_b'      => $metricBLabel,
                    'operator'      => $operator,
                    'threshold'     => $thresh,
                    'period_days'   => $period,
                    'quality_gates' => $rule['quality_gates'] ?? [],
                ],
            ];
        }

        return $preview;
    }

    /**
     * Convert preview array into a clean markdown string for modals and logs.
     */
    public function toMarkdown(array $configData): string
    {
        $preview = $this->generatePreview($configData);
        $output = [];

        foreach ($preview as $item) {
            $statusBadge = $item['enabled'] ? '✅ Enabled' : '⏸️ Disabled';
            $output[] = "### {$item['name']} [{$statusBadge}]\n" . $item['plain_english'];
        }

        return implode("\n\n---\n\n", $output);
    }
}
