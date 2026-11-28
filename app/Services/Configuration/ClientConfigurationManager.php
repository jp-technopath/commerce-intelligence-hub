<?php

namespace App\Services\Configuration;

use App\Models\Client;
use App\Models\ClientConfigurationAudit;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Non-destructive, validated, versioned, and auditable manager for client and
 * integration JSON monitoring_config payloads.
 */
class ClientConfigurationManager
{
    public const PROTECTED_CLIENT_KEYS = [
        'identity_hmac_key',
        'order_settings',
        'reconciliation_thresholds',
        'sample_sufficiency',
        'detector_configs',
        'custom_rules',
        'performance_objectives',
        'thresholds',
        'disable_builtin_detectors',
    ];

    public const PROTECTED_INTEGRATION_KEYS = [
        'enabled_metrics',
        'comparison_period_days',
        'order_settings',
        'sync_state',
    ];

    public const FORBIDDEN_ELIGIBLE_STATUSES = [
        'canceled',
        'cancelled',
        'closed',
        'fraud',
        'void',
        'test',
        'duplicate',
        'payment_review',
        'holded',
    ];

    private static ?bool $auditsTableExists = null;

    public static function resetSchemaCache(): void
    {
        self::$auditsTableExists = null;
    }

    /**
     * Merge incoming partial monitoring_config into existing config without clobbering
     * unrelated keys (order_settings, identity_hmac_key, thresholds, custom rules, etc.).
     */
    public function mergePreservingProtectedKeys(
        array $existing,
        array $incoming,
        array $protectedKeys = self::PROTECTED_CLIENT_KEYS
    ): array {
        $merged = $existing;

        foreach ($incoming as $key => $value) {
            if ($key === 'identity_hmac_key' && ($value === null || $value === '') && ! empty($existing['identity_hmac_key'])) {
                continue;
            }

            if (in_array($key, ['order_settings', 'detector_configs', 'reconciliation_thresholds', 'sample_sufficiency', 'performance_objectives', 'enabled_metrics', 'sync_state'], true)
                && is_array($value)
                && is_array($existing[$key] ?? null)
            ) {
                $merged[$key] = array_merge($existing[$key], $value);
                if ($key === 'order_settings') {
                    foreach (['legacy_completed_workflows', 'settlement_unverified_workflows', 'core_deposit_settings'] as $nestedKey) {
                        if (isset($value[$nestedKey]) && is_array($value[$nestedKey]) && is_array($existing['order_settings'][$nestedKey] ?? null)) {
                            $merged['order_settings'][$nestedKey] = array_merge($existing['order_settings'][$nestedKey], $value[$nestedKey]);
                        }
                    }
                }
                continue;
            }

            $merged[$key] = $value;
        }

        // Guard: never drop protected keys absent from a partial form/payload
        foreach ($protectedKeys as $pKey) {
            if (! array_key_exists($pKey, $merged) && array_key_exists($pKey, $existing)) {
                $merged[$pKey] = $existing[$pKey];
            }
        }

        return $merged;
    }

    /**
     * Validate and normalize client/integration monitoring_config values.
     *
     * @throws InvalidArgumentException
     */
    public function validateConfig(array $config): array
    {
        if (isset($config['order_settings'])) {
            if (! is_array($config['order_settings'])) {
                throw new InvalidArgumentException('order_settings must be an associative array.');
            }

            $os = $config['order_settings'];

            foreach (['eligible_statuses', 'excluded_statuses', 'b2b_credit_term_methods', 'b2b_authorized_statuses'] as $listKey) {
                if (isset($os[$listKey])) {
                    $values = is_string($os[$listKey])
                        ? array_map('trim', explode(',', $os[$listKey]))
                        : (array) $os[$listKey];

                    $os[$listKey] = array_values(array_unique(array_filter(array_map(
                        fn ($v) => strtolower(trim((string) $v)),
                        $values
                    ), fn ($v) => $v !== '')));
                }
            }

            if (! empty($os['eligible_statuses'])) {
                $forbidden = array_values(array_intersect($os['eligible_statuses'], self::FORBIDDEN_ELIGIBLE_STATUSES));
                if (! empty($forbidden)) {
                    throw new InvalidArgumentException(
                        'Forbidden terminal/fraud status cannot be added to eligible_statuses: ' . implode(', ', $forbidden)
                    );
                }
            }

            foreach (['allow_authorized_b2b_confirmed', 'allow_invoiced_pending_payment'] as $boolKey) {
                if (array_key_exists($boolKey, $os)) {
                    $os[$boolKey] = (bool) $os[$boolKey];
                }
            }

            if (isset($os['legacy_completed_workflows'])) {
                if (! is_array($os['legacy_completed_workflows'])) {
                    throw new InvalidArgumentException('order_settings.legacy_completed_workflows must be an associative array.');
                }
                $lc = $os['legacy_completed_workflows'];
                if (array_key_exists('enabled', $lc)) {
                    $lc['enabled'] = (bool) $lc['enabled'];
                }
                if (isset($lc['required_status'])) {
                    $reqStatus = strtolower(trim((string) $lc['required_status']));
                    if (in_array($reqStatus, self::FORBIDDEN_ELIGIBLE_STATUSES, true)) {
                        throw new InvalidArgumentException("Forbidden status [{$reqStatus}] cannot be set as legacy required_status.");
                    }
                    $lc['required_status'] = $reqStatus;
                }
                foreach (['allowed_states', 'allowed_payment_methods'] as $subList) {
                    if (isset($lc[$subList])) {
                        $lc[$subList] = array_values(array_unique(array_filter(array_map(
                            fn ($v) => strtolower(trim((string) $v)),
                            (array) $lc[$subList]
                        ), fn ($v) => $v !== '')));
                    }
                }
                $os['legacy_completed_workflows'] = $lc;
            }

            if (isset($os['settlement_unverified_workflows'])) {
                if (! is_array($os['settlement_unverified_workflows'])) {
                    throw new InvalidArgumentException('order_settings.settlement_unverified_workflows must be an associative array.');
                }
                $su = $os['settlement_unverified_workflows'];
                if (array_key_exists('enabled', $su)) {
                    $su['enabled'] = (bool) $su['enabled'];
                }
                foreach (['payment_methods', 'allowed_statuses', 'allowed_states'] as $subList) {
                    if (isset($su[$subList])) {
                        $su[$subList] = array_values(array_unique(array_filter(array_map(
                            fn ($v) => strtolower(trim((string) $v)),
                            (array) $su[$subList]
                        ), fn ($v) => $v !== '')));
                    }
                }
                if (! empty($su['allowed_statuses'])) {
                    $forbidden = array_values(array_intersect($su['allowed_statuses'], self::FORBIDDEN_ELIGIBLE_STATUSES));
                    if (! empty($forbidden)) {
                        throw new InvalidArgumentException(
                            'Forbidden terminal/fraud status cannot be added to settlement_unverified_workflows.allowed_statuses: ' . implode(', ', $forbidden)
                        );
                    }
                }
                $os['settlement_unverified_workflows'] = $su;
            }

            if (isset($os['core_deposit_settings'])) {
                if (! is_array($os['core_deposit_settings'])) {
                    throw new InvalidArgumentException('order_settings.core_deposit_settings must be an associative array.');
                }
                $cd = $os['core_deposit_settings'];
                foreach (['enabled', 'deduct_outstanding_from_merchandise_revenue'] as $bKey) {
                    if (array_key_exists($bKey, $cd)) {
                        $cd[$bKey] = (bool) $cd[$bKey];
                    }
                }
                foreach (['surcharge_codes', 'surcharge_labels'] as $subList) {
                    if (isset($cd[$subList])) {
                        $cd[$subList] = array_values(array_unique(array_filter(array_map(
                            fn ($v) => strtolower(trim((string) $v)),
                            (array) $cd[$subList]
                        ), fn ($v) => $v !== '')));
                    }
                }
                $os['core_deposit_settings'] = $cd;
            }

            $config['order_settings'] = $os;
        }

        if (isset($config['thresholds']) && is_array($config['thresholds'])) {
            foreach ($config['thresholds'] as $idx => $row) {
                if (! is_array($row) || empty($row['metric'])) {
                    throw new InvalidArgumentException("Threshold override #{$idx} must specify a valid metric.");
                }
                if (isset($row['value']) && (! is_numeric($row['value']) || (float) $row['value'] < 0 || (float) $row['value'] > 10)) {
                    throw new InvalidArgumentException("Threshold value for [{$row['metric']}] is out of bounds (0..10).");
                }
            }
        }

        return $config;
    }

    /**
     * Merge, validate, version, and persist partial updates to Client::monitoring_config.
     */
    public function updateClientMonitoringConfig(
        Client $client,
        array $partialConfig,
        string $source = 'ui',
        ?int $actorUserId = null,
        ?string $ipAddress = null
    ): Client {
        return DB::transaction(function () use ($client, $partialConfig, $source, $actorUserId, $ipAddress) {
            $fresh = Client::findOrFail($client->id);
            $before = is_array($fresh->monitoring_config) ? $fresh->monitoring_config : [];
            $merged = $this->mergePreservingProtectedKeys($before, $partialConfig, self::PROTECTED_CLIENT_KEYS);
            $validated = $this->validateConfig($merged);

            $prevVersion = (int) ($before['_meta']['version'] ?? 0);
            $nextVersion = $prevVersion + 1;

            $validated['_meta'] = [
                'version'            => $nextVersion,
                'updated_at'         => now()->toIso8601String(),
                'updated_by_user_id' => $actorUserId,
                'source'             => $source,
            ];

            $changedKeys = $this->computeChangedKeys($before, $validated);

            $fresh->monitoring_config = $validated;
            $fresh->save();

            if (! empty($changedKeys)) {
                $this->recordAudit(
                    clientId: $fresh->id,
                    integrationId: null,
                    actorUserId: $actorUserId,
                    configScope: 'client.monitoring_config',
                    version: $nextVersion,
                    changedKeys: $changedKeys,
                    before: $before,
                    after: $validated,
                    source: $source,
                    ipAddress: $ipAddress
                );
            }

            $client->monitoring_config = $validated;
            return $fresh;
        });
    }

    /**
     * Merge, validate, version, and persist partial updates to Integration::monitoring_config.
     */
    public function updateIntegrationMonitoringConfig(
        Integration $integration,
        array $partialConfig,
        string $source = 'system',
        ?int $actorUserId = null,
        ?string $ipAddress = null,
        bool $recordAudit = true
    ): Integration {
        return DB::transaction(function () use ($integration, $partialConfig, $source, $actorUserId, $ipAddress, $recordAudit) {
            $fresh = Integration::findOrFail($integration->id);
            $before = is_array($fresh->monitoring_config) ? $fresh->monitoring_config : [];
            $merged = $this->mergePreservingProtectedKeys($before, $partialConfig, self::PROTECTED_INTEGRATION_KEYS);
            $validated = $this->validateConfig($merged);

            $prevVersion = (int) ($before['_meta']['version'] ?? 0);
            $onlySyncStateChanged = array_keys($partialConfig) === ['sync_state'];
            $nextVersion = $onlySyncStateChanged ? max(1, $prevVersion) : ($prevVersion + 1);

            $validated['_meta'] = [
                'version'            => $nextVersion,
                'updated_at'         => now()->toIso8601String(),
                'updated_by_user_id' => $actorUserId,
                'source'             => $source,
            ];

            $changedKeys = $this->computeChangedKeys($before, $validated);

            $fresh->monitoring_config = $validated;
            $fresh->save();

            if ($recordAudit && ! $onlySyncStateChanged && ! empty($changedKeys)) {
                $this->recordAudit(
                    clientId: $fresh->client_id,
                    integrationId: $fresh->id,
                    actorUserId: $actorUserId,
                    configScope: 'integration.monitoring_config',
                    version: $nextVersion,
                    changedKeys: $changedKeys,
                    before: $before,
                    after: $validated,
                    source: $source,
                    ipAddress: $ipAddress
                );
            }

            $integration->monitoring_config = $validated;
            return $fresh;
        });
    }

    /**
     * Merge form-submitted monitoring_config safely before Filament EditRecord save.
     */
    public function mergeFormSubmission(array $existing, array $submitted, array $protectedKeys): array
    {
        $merged = $this->mergePreservingProtectedKeys($existing, $submitted, $protectedKeys);
        $validated = $this->validateConfig($merged);

        $prevVersion = (int) ($existing['_meta']['version'] ?? 0);
        $validated['_meta'] = [
            'version'            => $prevVersion + 1,
            'updated_at'         => now()->toIso8601String(),
            'updated_by_user_id' => auth()->id(),
            'source'             => 'filament_ui',
        ];

        return $validated;
    }

    public function computeChangedKeys(array $before, array $after): array
    {
        $changed = [];
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($allKeys as $key) {
            if ($key === '_meta') {
                continue;
            }
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }
        return array_values($changed);
    }

    public function recordAudit(
        ?int $clientId,
        ?int $integrationId,
        ?int $actorUserId,
        string $configScope,
        int $version,
        array $changedKeys,
        array $before,
        array $after,
        string $source,
        ?string $ipAddress = null
    ): void {
        if (self::$auditsTableExists === null) {
            self::$auditsTableExists = Schema::hasTable('client_configuration_audits');
        }
        if (! self::$auditsTableExists) {
            return;
        }

        ClientConfigurationAudit::create([
            'client_id'      => $clientId,
            'integration_id' => $integrationId,
            'actor_user_id'  => $actorUserId,
            'config_scope'   => $configScope,
            'version'        => $version,
            'changed_keys'   => $changedKeys,
            'before_json'    => $this->redactSecrets($before),
            'after_json'     => $this->redactSecrets($after),
            'source'         => $source,
            'ip_address'     => $ipAddress,
        ]);
    }

    private function redactSecrets(array $config): array
    {
        if (array_key_exists('identity_hmac_key', $config)) {
            $config['identity_hmac_key'] = '[REDACTED]';
        }
        return $config;
    }
}
