<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use JsonException;

class FindingConfigurationValidator
{
    /**
     * Validate an uploaded JSON configuration string or parsed array for a specific client.
     *
     * @return array{is_valid: bool, errors: array<string>, validated_data: ?array}
     */
    public function validate(Client $client, string|array $input): array
    {
        $errors = [];

        // 1. Parse JSON if string
        if (is_string($input)) {
            try {
                $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return [
                    'is_valid'       => false,
                    'errors'         => ['Invalid JSON format: ' . $e->getMessage()],
                    'validated_data' => null,
                ];
            }
        } else {
            $data = $input;
        }

        if (! is_array($data)) {
            return [
                'is_valid'       => false,
                'errors'         => ['Configuration must be a valid JSON object.'],
                'validated_data' => null,
            ];
        }

        // 2. Validate schema_version
        if (! isset($data['schema_version']) || (string) $data['schema_version'] !== FindingConfigurationService::SCHEMA_VERSION) {
            $errors[] = sprintf(
                'Unsupported schema version "%s". Expected "%s".',
                $data['schema_version'] ?? 'null',
                FindingConfigurationService::SCHEMA_VERSION
            );
        }

        // 3. Validate client ID matches
        if (! isset($data['client']['id'])) {
            $errors[] = 'Missing required field: client.id';
        } elseif ((int) $data['client']['id'] !== (int) $client->id) {
            $errors[] = sprintf(
                'Client ID mismatch: configuration is for client ID %d, but uploading to %s (ID %d).',
                $data['client']['id'],
                $client->name,
                $client->id
            );
        }

        // 4. Security check on JSON structure
        $dangerousPattern = '/<\?php|<script|eval\s*\(|base64_decode|system\s*\(|exec\s*\(|passthru\s*\(|`|SELECT\s+.*FROM|DROP\s+TABLE/i';
        $jsonRepresentation = json_encode($data);
        if ($jsonRepresentation && preg_match($dangerousPattern, $jsonRepresentation)) {
            $errors[] = 'Security violation: configuration contains forbidden characters or executable expressions.';
            return [
                'is_valid'       => false,
                'errors'         => $errors,
                'validated_data' => null,
            ];
        }

        // 5. Validate rules array
        if (! isset($data['rules']) || ! is_array($data['rules'])) {
            $errors[] = 'Field "rules" must be an array of rule objects.';
            return [
                'is_valid'       => false,
                'errors'         => $errors,
                'validated_data' => null,
            ];
        }

        if (empty($data['rules'])) {
            $errors[] = 'At least one rule must be defined in "rules".';
        }

        $availableMetrics = FindingMetricRegistry::getAvailableMetricsForClient($client);
        $availableKeys    = array_column($availableMetrics, 'key');
        $availableListStr = ! empty($availableKeys)
            ? "\nAvailable metrics for {$client->name}:\n- " . implode("\n- ", $availableKeys)
            : "\n(No metrics currently available for this client — ensure integrations are connected and metrics synced).";

        $seenRuleKeys = [];

        foreach ($data['rules'] as $idx => $rule) {
            $ruleNum = $idx + 1;
            $ruleName = isset($rule['name']) && is_string($rule['name']) ? $rule['name'] : "Rule #{$ruleNum}";

            if (! is_array($rule)) {
                $errors[] = "Rule #{$ruleNum} must be a valid JSON object.";
                continue;
            }

            // Check key
            if (empty($rule['key']) || ! is_string($rule['key'])) {
                $errors[] = "Rule \"{$ruleName}\": missing or invalid \"key\". Must be a non-empty string.";
            } elseif (! preg_match('/^[a-z0-9_-]+$/i', $rule['key'])) {
                $errors[] = "Rule \"{$ruleName}\": \"key\" [{$rule['key']}] must contain only letters, numbers, underscores, and hyphens.";
            } elseif (isset($seenRuleKeys[$rule['key']])) {
                $errors[] = "Duplicate rule key detected: \"{$rule['key']}\". Each rule key must be unique.";
            } else {
                $seenRuleKeys[$rule['key']] = true;
            }

            // Check enabled
            if (! isset($rule['enabled']) || ! is_bool($rule['enabled'])) {
                $errors[] = "Rule \"{$ruleName}\": \"enabled\" must be a boolean (true or false).";
            }

            // Check operator
            if (empty($rule['operator']) || ! in_array($rule['operator'], FindingConfigurationService::ALLOWED_OPERATORS, true)) {
                $errors[] = sprintf(
                    'Rule "%s": operator "%s" is invalid. Allowed operators: %s.',
                    $ruleName,
                    $rule['operator'] ?? 'null',
                    implode(', ', FindingConfigurationService::ALLOWED_OPERATORS)
                );
            }

            // Check severity
            if (empty($rule['severity']) || ! in_array(strtolower((string) $rule['severity']), FindingConfigurationService::ALLOWED_SEVERITIES, true)) {
                $errors[] = sprintf(
                    'Rule "%s": severity "%s" is invalid. Allowed severities: %s.',
                    $ruleName,
                    $rule['severity'] ?? 'null',
                    implode(', ', FindingConfigurationService::ALLOWED_SEVERITIES)
                );
            }

            // Check period_days
            if (! isset($rule['period_days']) || ! in_array((int) $rule['period_days'], FindingConfigurationService::ALLOWED_PERIODS, true)) {
                $errors[] = sprintf(
                    'Rule "%s": period_days "%s" is invalid. Allowed comparison periods: %s days.',
                    $ruleName,
                    $rule['period_days'] ?? 'null',
                    implode(', ', FindingConfigurationService::ALLOWED_PERIODS)
                );
            }

            // Check threshold
            if (! isset($rule['threshold']) || ! is_numeric($rule['threshold'])) {
                $errors[] = "Rule \"{$ruleName}\": \"threshold\" must be a numeric value.";
            }

            // Check metric_a
            if (empty($rule['metric_a']) || ! is_string($rule['metric_a'])) {
                $errors[] = "Rule \"{$ruleName}\": \"metric_a\" is required and must be a string.";
            } else {
                $metricA = $rule['metric_a'];
                if (! FindingMetricRegistry::hasMetric($metricA)) {
                    $errors[] = "Rule \"{$ruleName}\": metric_a \"{$metricA}\" is unknown.{$availableListStr}";
                } elseif (! FindingMetricRegistry::isMetricAvailableForClient($client, $metricA)) {
                    $errors[] = "Rule \"{$ruleName}\": metric_a \"{$metricA}\" is not available for this client.{$availableListStr}";
                }
            }

            // Check metric_b
            $operator = $rule['operator'] ?? '';
            if ($operator === 'percentage_difference') {
                if (empty($rule['metric_b']) || ! is_string($rule['metric_b'])) {
                    $errors[] = "Rule \"{$ruleName}\": operator \"percentage_difference\" requires \"metric_b\".";
                } else {
                    $metricB = $rule['metric_b'];
                    if (! FindingMetricRegistry::hasMetric($metricB)) {
                        $errors[] = "Rule \"{$ruleName}\": metric_b \"{$metricB}\" is unknown.{$availableListStr}";
                    } elseif (! FindingMetricRegistry::isMetricAvailableForClient($client, $metricB)) {
                        $errors[] = "Rule \"{$ruleName}\": metric_b \"{$metricB}\" is not available for this client.{$availableListStr}";
                    } elseif (isset($rule['metric_a']) && $rule['metric_a'] === $metricB) {
                        $errors[] = "Rule \"{$ruleName}\": metric_a and metric_b cannot be the same metric ({$metricB}).";
                    }
                }
            }

            // Check quality gates
            if (isset($rule['quality_gates'])) {
                if (! is_array($rule['quality_gates'])) {
                    $errors[] = "Rule \"{$ruleName}\": \"quality_gates\" must be an object.";
                } else {
                    $allowedGates = ['minimum_revenue', 'minimum_sessions', 'minimum_orders'];
                    foreach ($rule['quality_gates'] as $gateKey => $gateVal) {
                        if (! in_array($gateKey, $allowedGates, true)) {
                            $errors[] = sprintf(
                                'Rule "%s": unsupported quality gate "%s". Allowed gates: %s.',
                                $ruleName,
                                $gateKey,
                                implode(', ', $allowedGates)
                            );
                        } elseif (! is_numeric($gateVal) || (float) $gateVal < 0) {
                            $errors[] = "Rule \"{$ruleName}\": quality gate \"{$gateKey}\" must be a positive number.";
                        }
                    }
                }
            }
        }

        return [
            'is_valid'       => empty($errors),
            'errors'         => $errors,
            'validated_data' => empty($errors) ? $data : null,
        ];
    }
}
