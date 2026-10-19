<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Services\PM\IdentityResolverService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'industry',
        'platform_type',
        'jira_project_key',
        'timezone',
        'currency',
        'monthly_allocated_hours',
        'status',
        'project_lead_id',
        'notes',
        'business_context',
        'monitoring_config',
        'findings_comparison_period',
    ];

    protected $casts = [
        'status' => ClientStatus::class,
        'monthly_allocated_hours' => 'integer',
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
     * Check whether built-in anomaly detectors (commerce, behavioral, funnels) are enabled.
     * When false, only custom client finding rules evaluate.
     */
    public function builtinDetectorsEnabled(): bool
    {
        return ! ($this->monitoring_config['disable_builtin_detectors'] ?? false);
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

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
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

    public function latestHealthScore(): HasOne
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

    public function findingConfigurations(): HasMany
    {
        return $this->hasMany(ClientFindingConfiguration::class);
    }

    public function activeFindingConfiguration(): HasOne
    {
        return $this->hasOne(ClientFindingConfiguration::class)->where('status', ClientFindingConfiguration::STATUS_ACTIVE);
    }

    public function projectLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_lead_id');
    }

    protected static function booted(): void
    {
        static::saved(function (Client $client) {
            if ($client->project_lead_id) {
                $client->reattributeMeetingsToLead();
            }
        });
    }

    /**
     * Re-attribute any meetings for this client that belong to the project lead.
     */
    public function reattributeMeetingsToLead(): int
    {
        if (! $this->project_lead_id) {
            return 0;
        }

        $lead = User::find($this->project_lead_id);
        if (! $lead) {
            return 0;
        }

        $leadEmails = IdentityResolverService::getUserEmails($lead);

        $count = 0;
        $meetings = $this->clientMeetings()->get();
        foreach ($meetings as $meeting) {
            if ($this->shouldAttributeMeetingToLead($meeting, $lead, $leadEmails)) {
                if ($meeting->internal_owner_id !== $this->project_lead_id) {
                    $meeting->update(['internal_owner_id' => $this->project_lead_id]);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Determine if a meeting for this client should be attributed to the project lead.
     */
    public function shouldAttributeMeetingToLead(ClientMeeting $meeting, User $lead, array $leadEmails = []): bool
    {
        // 1. External client organized meeting -> always attribute to Project Lead
        if ($meeting->isOrganizedByExternalParty()) {
            return true;
        }

        $meta = $meeting->metadata ?? [];
        $organizerEmail = strtolower(trim($meta['organizer_email'] ?? ''));
        $creatorEmail = strtolower(trim($meta['creator_email'] ?? ''));

        // 2. Group/resource calendar meeting -> always attribute to Project Lead
        if (str_ends_with($organizerEmail, '@group.calendar.google.com') || str_ends_with($organizerEmail, '@resource.calendar.google.com')) {
            return true;
        }

        $leadEmails = ! empty($leadEmails) ? $leadEmails : IdentityResolverService::getUserEmails($lead);

        // 3. Meeting created or organized by the Project Lead
        if ((! empty($creatorEmail) && in_array($creatorEmail, $leadEmails, true)) ||
            (! empty($organizerEmail) && in_array($organizerEmail, $leadEmails, true))) {
            return true;
        }

        // 4. Meeting with external client attendees -> always attribute to Project Lead
        if (! empty($meeting->external_attendees)) {
            return true;
        }

        // 5. Meeting without metadata or creator/organizer
        if (empty($organizerEmail) && empty($creatorEmail)) {
            return true;
        }

        // 6. Current owner is NOT the genuine creator or organizer of the meeting
        $currentOwner = $meeting->internalOwner;
        if ($currentOwner) {
            $ownerEmails = IdentityResolverService::getUserEmails($currentOwner);

            $isOwnerGenuine = (! empty($creatorEmail) && in_array($creatorEmail, $ownerEmails, true))
                || (! empty($organizerEmail) && in_array($organizerEmail, $ownerEmails, true));

            if (! $isOwnerGenuine) {
                return true;
            }
        }

        return false;
    }

    /**
     * Backward-compatible alias for reattributeMeetingsToLead.
     */
    public function reattributeExternalMeetingsToLead(): int
    {
        return $this->reattributeMeetingsToLead();
    }

    /**
     * Scope query to only clients that have an assigned Jira project code.
     */
    public function scopeWithJiraProjectKey(Builder $query): Builder
    {
        return $query->whereNotNull('jira_project_key')->where('jira_project_key', '!=', '');
    }

    /**
     * Check if this client has a valid Jira project code.
     */
    public function hasJiraProjectKey(): bool
    {
        return ! empty($this->jira_project_key);
    }
}
