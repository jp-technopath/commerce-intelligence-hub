<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Models\Integration;
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
        'jira_project_key',
        'status',
        'notes',
        'business_context',
        'monitoring_config',
        'findings_comparison_period',
    ];

    protected $casts = [
        'status'           => ClientStatus::class,
        'monitoring_config' => 'array',
    ];

    // ── Monitoring config helpers ────────────────────────────────────────

    /**
     * Get all monitored metrics for a given type by aggregating across active integrations.
     * Each integration only contributes metrics for its applicable categories.
     */
    public function getMonitoredMetricsForType(string $type): array
    {
        $metrics = $this->integrations()
            ->where('status', 'active')
            ->get()
            ->flatMap(fn (Integration $integration) => $integration->getMonitoredMetrics($type))
            ->unique()
            ->values()
            ->toArray();

        // Fall back to all metrics if no integrations are configured
        if (empty($metrics)) {
            return Integration::getAllMetricsForCategory($type);
        }

        return $metrics;
    }

    /**
     * Get comparison period for the findings engine.
     * Uses the client-level setting, falls back to 7 days.
     */
    public function getComparisonPeriodForType(string $type): int
    {
        return $this->findings_comparison_period ?? 7;
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

    public function emailMarketingMetrics(): HasMany
    {
        return $this->hasMany(EmailMarketingMetric::class);
    }

    public function clientMeetings(): HasMany
    {
        return $this->hasMany(ClientMeeting::class);
    }
}
