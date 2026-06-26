<?php

namespace App\Models;

use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'industry',
        'platform_type',
        'status',
        'notes',
        'business_context',
        'monitoring_config',
    ];

    protected $casts = [
        'status'           => ClientStatus::class,
        'monitoring_config' => 'array',
    ];

    // ── All available metrics (used as defaults when no config is set) ────

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

    // ── Monitoring config helpers ────────────────────────────────────────

    /**
     * Get enabled metrics for a type ('commerce', 'behavioral', 'performance', or 'inventory').
     * Returns all metrics when no config is set (backward compatible).
     */
    public function getMonitoredMetrics(string $type): array
    {
        $config = $this->monitoring_config['enabled_metrics'][$type] ?? null;

        if ($config === null) {
            return match ($type) {
                'commerce'    => self::ALL_COMMERCE_METRICS,
                'behavioral'  => self::ALL_BEHAVIORAL_METRICS,
                'performance' => self::ALL_PERFORMANCE_METRICS,
                'inventory'   => self::ALL_INVENTORY_METRICS,
                default       => [],
            };
        }

        return $config;
    }

    /**
     * Get threshold for a metric key (e.g. 'revenue_decrease').
     * Checks client overrides first, falls back to global config.
     */
    public function getThreshold(string $key): float
    {
        $thresholds = $this->monitoring_config['thresholds'] ?? [];

        // Thresholds stored as array of objects: [{metric, value, severity}]
        foreach ($thresholds as $row) {
            if (($row['metric'] ?? null) === $key) {
                return (float) ($row['value'] ?? 0);
            }
        }

        // Fall back to global config
        return (float) config("intelligence.thresholds.{$key}", 0.10);
    }

    /**
     * Get forced severity for a metric, or null to use calculated severity.
     */
    public function getSeverityOverride(string $metricKey): ?string
    {
        $thresholds = $this->monitoring_config['thresholds'] ?? [];

        foreach ($thresholds as $row) {
            if (($row['metric'] ?? null) === $metricKey && ! empty($row['severity'])) {
                return $row['severity'];
            }
        }

        return null;
    }

    /**
     * Get comparison period in days (7, 14, or 30). Default: 7.
     */
    public function getComparisonPeriod(): int
    {
        return (int) ($this->monitoring_config['comparison_period_days'] ?? 7);
    }

    /**
     * Get the integration types this client actually has connected.
     * Returns array like ['ga4', 'adobe_commerce', 'clarity'].
     */
    public function getActiveIntegrationTypes(): array
    {
        return $this->integrations()
            ->where('status', 'active')
            ->pluck('integration_type')
            ->map(fn ($t) => is_object($t) ? $t->value : $t)
            ->unique()
            ->values()
            ->toArray();
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function commerceMetrics(): HasMany
    {
        return $this->hasMany(CommerceMetric::class);
    }

    public function behavioralMetrics(): HasMany
    {
        return $this->hasMany(BehavioralMetric::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function healthScores(): HasMany
    {
        return $this->hasMany(ClientHealthScore::class);
    }

    public function latestHealthScore(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ClientHealthScore::class)->latestOfMany('date');
    }

    public function openFindings(): HasMany
    {
        return $this->hasMany(Finding::class)->whereIn('status', ['new', 'investigating', 'accepted']);
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(PerformanceMetric::class);
    }

    public function inventoryMetrics(): HasMany
    {
        return $this->hasMany(InventoryMetric::class);
    }
}
