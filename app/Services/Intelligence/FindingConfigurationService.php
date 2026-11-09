<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use ZipArchive;

class FindingConfigurationService
{
    public const SCHEMA_VERSION_V1 = '1.0';
    public const SCHEMA_VERSION_V2 = '2.0';
    public const SCHEMA_VERSION = self::SCHEMA_VERSION_V2;

    public const SUPPORTED_SCHEMA_VERSIONS = [
        self::SCHEMA_VERSION_V1,
        self::SCHEMA_VERSION_V2,
    ];

    public const ALLOWED_OPERATORS = [
        'greater_than',
        'less_than',
        'percentage_change',
        'percentage_difference',
    ];

    public const ALLOWED_SEVERITIES = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    public const ALLOWED_PERIODS = [
        7,
        14,
        30,
        60,
        90,
    ];

    /**
     * Generate the findings configuration template for a client.
     */
    public function generateTemplate(Client $client): array
    {
        $availableMetrics = FindingMetricRegistry::getAvailableMetricsForClient($client);

        // Check if client has both GA4 email and Klaviyo to provide the attribution rule
        $hasGa4Email = collect($availableMetrics)->contains('key', 'ga4.email_revenue');
        $hasKlaviyo  = collect($availableMetrics)->contains('key', 'klaviyo.attributed_revenue');

        $rules = [];
        if ($hasGa4Email && $hasKlaviyo) {
            $rules[] = [
                'key'           => 'ga4_klaviyo_attribution_variance',
                'name'          => 'GA4 vs Klaviyo Attribution Variance',
                'enabled'       => true,
                'metric_a'      => 'ga4.email_revenue',
                'metric_b'      => 'klaviyo.attributed_revenue',
                'operator'      => 'percentage_difference',
                'threshold'     => 10,
                'period_days'   => 30,
                'quality_gates' => [
                    'minimum_revenue' => 1000,
                ],
                'severity'      => 'high',
            ];
        } elseif (! empty($availableMetrics)) {
            // Provide a starter rule with the first available metric
            $firstMetric = $availableMetrics[0]['key'];
            $rules[] = [
                'key'           => 'metric_period_drop_alert',
                'name'          => 'Period Over Period Metric Drop Alert',
                'enabled'       => true,
                'metric_a'      => $firstMetric,
                'operator'      => 'percentage_change',
                'threshold'     => -15,
                'period_days'   => 14,
                'quality_gates' => [
                    'minimum_revenue' => 500,
                ],
                'severity'      => 'medium',
            ];
        }

        $opportunityDefaults = array_values(config('intelligence.opportunity_defaults', []));

        return [
            '$schema'               => './finding-rules.schema.json',
            'schema_version'        => self::SCHEMA_VERSION_V2,
            'client'                => [
                'id'   => $client->id,
                'name' => $client->name,
            ],
            'available_metrics'     => $availableMetrics,
            'allowed_operators'     => self::ALLOWED_OPERATORS,
            'allowed_severities'    => self::ALLOWED_SEVERITIES,
            'allowed_periods'       => self::ALLOWED_PERIODS,
            'rules'                 => $rules,
            'opportunity_detectors' => $opportunityDefaults,
            '_instructions'         => 'Only use metrics listed in available_metrics. Only use supported operators, periods and severity values. In opportunity_detectors, configure thresholds, enabled status, and business goals. To customize, paste this entire JSON into ChatGPT or Claude and describe your desired business and technical finding rules.',
        ];
    }

    /**
     * Resolve effective opportunity detector configuration for a client,
     * merging customer-specific overrides over global defaults.
     */
    public function getEffectiveOpportunityConfiguration(Client $client): array
    {
        $defaults = config('intelligence.opportunity_defaults', []);
        $activeConfig = \App\Models\ClientFindingConfiguration::forClient($client->id)->active()->first();

        if (! $activeConfig || empty($activeConfig->configuration_json['opportunity_detectors'])) {
            return $defaults;
        }

        $clientDetectors = $activeConfig->configuration_json['opportunity_detectors'];
        $effective = $defaults;

        foreach ($clientDetectors as $detector) {
            $key = $detector['key'] ?? null;
            if (! $key) {
                continue;
            }

            if (isset($effective[$key])) {
                $effective[$key] = array_replace_recursive($effective[$key], $detector);
            } else {
                $effective[$key] = $detector;
            }
        }

        return $effective;
    }

    /**
     * Safely upgrade an existing v1 ClientFindingConfiguration to v2.
     */
    public function upgradeToV2(\App\Models\ClientFindingConfiguration $config, ?int $userId = null): \App\Models\ClientFindingConfiguration
    {
        $data = $config->configuration_json;
        $data['schema_version'] = self::SCHEMA_VERSION_V2;
        if (empty($data['opportunity_detectors'])) {
            $defaults = config('intelligence.opportunity_defaults', []);
            $data['opportunity_detectors'] = array_values($defaults);
        }

        $newConfig = new \App\Models\ClientFindingConfiguration([
            'client_id'          => $config->client_id,
            'version'            => \App\Models\ClientFindingConfiguration::nextVersionForClient($config->client_id),
            'configuration_json' => $data,
            'schema_version'     => self::SCHEMA_VERSION_V2,
            'rules_count'        => count($data['rules'] ?? []),
            'status'             => 'active',
            'created_by'         => $userId ?? $config->created_by,
        ]);

        $newConfig->activate();

        return $newConfig;
    }

    /**
     * Generate the strict JSON Schema for client-specific findings configuration.
     */
    public function generateJsonSchema(Client $client): array
    {
        $availableMetrics = FindingMetricRegistry::getAvailableMetricsForClient($client);
        $metricKeys = array_column($availableMetrics, 'key');

        return [
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => "FindingRulesConfiguration_{$client->id}",
            'description' => "Schema for {$client->name} findings rules configuration.",
            'type'        => 'object',
            'required'    => ['schema_version', 'client', 'rules'],
            'properties'  => [
                '$schema'            => ['type' => 'string'],
                'schema_version'     => [
                    'type' => 'string',
                    'enum' => self::SUPPORTED_SCHEMA_VERSIONS,
                ],
                'client'             => [
                    'type'       => 'object',
                    'required'   => ['id', 'name'],
                    'properties' => [
                        'id'   => ['type' => 'integer', 'const' => $client->id],
                        'name' => ['type' => 'string'],
                    ],
                ],
                'available_metrics'  => ['type' => 'array'],
                'allowed_operators'  => ['type' => 'array'],
                'allowed_severities' => ['type' => 'array'],
                'allowed_periods'    => ['type' => 'array'],
                '_instructions'      => ['type' => 'string'],
                'rules'              => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['key', 'name', 'enabled', 'metric_a', 'operator', 'threshold', 'period_days', 'severity'],
                        'properties' => [
                            'key'           => [
                                'type'    => 'string',
                                'pattern' => '^[a-z0-9_.-]+$',
                            ],
                            'name'          => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120],
                            'enabled'       => ['type' => 'boolean'],
                            'metric_a'      => [
                                'type' => 'string',
                                'enum' => ! empty($metricKeys) ? $metricKeys : ['none'],
                            ],
                            'metric_b'      => [
                                'type' => 'string',
                                'enum' => ! empty($metricKeys) ? $metricKeys : ['none'],
                            ],
                            'operator'      => [
                                'type' => 'string',
                                'enum' => self::ALLOWED_OPERATORS,
                            ],
                            'threshold'     => ['type' => 'number'],
                            'period_days'   => [
                                'type' => 'integer',
                                'enum' => self::ALLOWED_PERIODS,
                            ],
                            'severity'      => [
                                'type' => 'string',
                                'enum' => self::ALLOWED_SEVERITIES,
                            ],
                            'quality_gates' => [
                                'type'       => 'object',
                                'properties' => [
                                    'minimum_revenue'  => ['type' => 'number', 'minimum' => 0],
                                    'minimum_sessions' => ['type' => 'integer', 'minimum' => 0],
                                    'minimum_orders'   => ['type' => 'integer', 'minimum' => 0],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'allOf'      => [
                            [
                                'if'   => [
                                    'properties' => ['operator' => ['const' => 'percentage_difference']],
                                ],
                                'then' => [
                                    'required' => ['metric_b'],
                                ],
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'opportunity_detectors' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['key', 'name', 'enabled'],
                        'properties' => [
                            'key'                  => ['type' => 'string'],
                            'name'                 => ['type' => 'string'],
                            'enabled'              => ['type' => 'boolean'],
                            'evaluation_frequency' => ['type' => 'string'],
                            'min_order_count'      => ['type' => 'integer'],
                            'thresholds'           => ['type' => 'object'],
                            'business_objectives'  => ['type' => 'object'],
                            'default_owner_role'   => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Create a zip file containing finding-rules.json and finding-rules.schema.json.
     * Returns the temporary zip file path.
     */
    public function generateDownloadZip(Client $client): string
    {
        $template = $this->generateTemplate($client);
        $schema   = $this->generateJsonSchema($client);

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $zipPath = $tempDir . "/finding-rules-client-{$client->id}-" . time() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('finding-rules.json', json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('finding-rules.schema.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();
        }

        return $zipPath;
    }
}
