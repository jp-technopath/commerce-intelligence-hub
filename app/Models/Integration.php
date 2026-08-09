<?php

namespace App\Models;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'integration_type',
        'status',
        'credentials_json',
        'settings_json',
        'monitoring_config',
        'last_sync_at',
    ];

    protected $casts = [
        'integration_type'  => IntegrationType::class,
        'status'            => IntegrationStatus::class,
        'settings_json'     => 'array',
        'monitoring_config' => 'array',
        'last_sync_at'      => 'datetime',
    ];

    public function getCredentialsJsonAttribute($value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        // 1. Try standard Crypt::decrypt with current APP_KEY and APP_PREVIOUS_KEYS
        try {
            $dec = \Illuminate\Support\Facades\Crypt::decrypt($value, false);
            $json = is_string($dec) ? json_decode($dec, true) : $dec;
            if (is_array($json)) {
                return $json;
            }
        } catch (\Throwable $e) {}

        try {
            $dec = \Illuminate\Support\Facades\Crypt::decrypt($value, true);
            if (is_array($dec)) {
                return $dec;
            }
        } catch (\Throwable $e) {}

        // 2. Try candidate fallback keys (e.g. local key, previous prod keys)
        $candidateKeys = [
            'fPg+FfvO7T7kDDJiTdwko5a9Cbcyp9yt41kIVaMQ2X8=', // Local key
            'E2iu1AZORQP5LWQ8c1R5aJo9/Omh7dDqfRzyAhjsCig=', // Default candidate key
            'cfKjZFh01Yt8ie11EV5ixFu+MMnGbAuDJ50xDj1miI8=',
        ];

        foreach ($candidateKeys as $k) {
            $rawKey = base64_decode($k);
            if (strlen($rawKey) !== 32) continue;
            try {
                $enc = new \Illuminate\Encryption\Encrypter($rawKey, 'AES-256-CBC');
                $dec = $enc->decrypt($value, false);
                $json = is_string($dec) ? json_decode($dec, true) : $dec;
                if (is_array($json)) {
                    return $json;
                }
            } catch (\Throwable $e) {}
            try {
                $enc = new \Illuminate\Encryption\Encrypter($rawKey, 'AES-256-CBC');
                $dec = $enc->decrypt($value, true);
                if (is_array($dec)) {
                    return $dec;
                }
            } catch (\Throwable $e) {}
        }

        // 3. Try plain JSON string
        $json = json_decode($value, true);
        if (is_array($json)) {
            return $json;
        }

        return [];
    }

    public function setCredentialsJsonAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['credentials_json'] = null;
            return;
        }

        $array = is_array($value) ? $value : json_decode($value, true);
        if (is_array($array)) {
            $this->attributes['credentials_json'] = \Illuminate\Support\Facades\Crypt::encrypt(
                json_encode($array),
                false
            );
        } else {
            $this->attributes['credentials_json'] = $value;
        }
    }

    // ── All available metrics per category ────────────────────────────────

    public const ALL_COMMERCE_METRICS = [
        'revenue', 'orders', 'conversion_rate', 'aov', 'sessions', 'new_customers', 'return_rate',
    ];

    public const ALL_BEHAVIORAL_METRICS = [
        'rage_clicks', 'dead_clicks', 'quick_backs', 'script_errors', 'error_clicks', 'friction_score',
    ];

    public const ALL_PERFORMANCE_METRICS = [
        'lcp', 'inp', 'cls', 'ttfb', 'page_load_time', 'bounce_rate',
    ];

    public const ALL_INVENTORY_METRICS = [
        'out_of_stock_count', 'low_stock_count', 'out_of_stock_rate', 'inventory_turnover',
    ];

    public const ALL_EMAIL_MARKETING_METRICS = [
        'open_rate', 'click_rate', 'conversions', 'revenue', 'unsubscribes', 'bounces',
    ];

    // ── Monitoring config helpers ────────────────────────────────────────

    /**
     * Get the metric categories applicable to this integration type.
     */
    public function getApplicableMetricCategories(): array
    {
        return $this->integration_type?->metricCategories() ?? [];
    }

    /**
     * Get enabled metrics for a category (e.g. 'commerce', 'behavioral').
     * Returns all metrics when no config is set (backward compatible).
     */
    public function getMonitoredMetrics(string $type): array
    {
        // Only return metrics if this integration type supports the requested category
        if (! in_array($type, $this->getApplicableMetricCategories())) {
            return [];
        }

        $config = $this->monitoring_config['enabled_metrics'][$type] ?? null;

        if ($config === null) {
            return self::getAllMetricsForCategory($type);
        }

        return $config;
    }

    /**
     * Get all available metrics for a category.
     */
    public static function getAllMetricsForCategory(string $type): array
    {
        return match ($type) {
            'commerce'         => self::ALL_COMMERCE_METRICS,
            'behavioral'       => self::ALL_BEHAVIORAL_METRICS,
            'performance'      => self::ALL_PERFORMANCE_METRICS,
            'inventory'        => self::ALL_INVENTORY_METRICS,
            'email_marketing'  => self::ALL_EMAIL_MARKETING_METRICS,
            default            => [],
        };
    }

    /**
     * Get comparison period in days. Default: 7.
     */
    public function getComparisonPeriod(): int
    {
        return (int) ($this->monitoring_config['comparison_period_days'] ?? 7);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function latestSyncLog(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SyncLog::class)->latestOfMany();
    }

    /**
     * Get a specific credential value without exposing the full decrypted payload.
     * The encrypted:array cast handles decode automatically.
     */
    public function getCredential(string $key): mixed
    {
        return ($this->credentials_json ?? [])[$key] ?? null;
    }

    /**
     * Set a specific credential key without overwriting others.
     * The encrypted:array cast handles encode/encrypt automatically.
     */
    public function setCredential(string $key, mixed $value): void
    {
        $credentials       = $this->credentials_json ?? [];
        $credentials[$key] = $value;
        $this->credentials_json = $credentials;
        $this->save();
    }
}
