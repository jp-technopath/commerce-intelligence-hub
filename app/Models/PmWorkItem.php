<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PmWorkItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'pm_connection_id',
        'pm_project_id',
        'external_item_id',
        'external_item_key',
        'summary',
        'description',
        'item_type',
        'priority',
        'external_status',
        'normalized_delivery_status',
        'estimated_seconds',
        'time_spent_seconds',
        'assignee_name',
        'target_due_date',
        'is_blocked',
        'blocked_reason',
        'labels_json',
        'external_updated_at',
        'last_synced_at',
    ];

    protected $casts = [
        'is_blocked'          => 'boolean',
        'labels_json'          => 'array',
        'target_due_date'     => 'date',
        'external_updated_at' => 'datetime',
        'last_synced_at'      => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PmConnection::class, 'pm_connection_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(PmProject::class, 'pm_project_id');
    }

    public function pmProject(): BelongsTo
    {
        return $this->project();
    }

    public function worklogs(): HasMany
    {
        return $this->hasMany(PmWorklog::class);
    }

    public function estimateVersions(): HasMany
    {
        return $this->hasMany(ForgeEstimateVersion::class)->orderBy('version', 'desc');
    }

    public function latestEstimateVersion(): HasOne
    {
        return $this->hasOne(ForgeEstimateVersion::class)->latestOfMany('version');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(MeetingActionItem::class, 'jira_issue_key', 'external_item_key');
    }

    // ── Helper Accessors ─────────────────────────────────────────────────

    public function hasLabel(string $targetLabel): bool
    {
        $labels = $this->labels_json ?? [];
        foreach ($labels as $lbl) {
            if (strcasecmp(trim($lbl), trim($targetLabel)) === 0) {
                return true;
            }
        }
        return false;
    }

    public function getEstimatedHoursAttribute(): float
    {
        return round($this->estimated_seconds / 3600, 1);
    }

    public function getTimeSpentHoursAttribute(): float
    {
        return round($this->time_spent_seconds / 3600, 1);
    }

    /**
     * Get latest estimate approval status.
     */
    public function getEstimateApprovalStatusAttribute(): string
    {
        $latestVersion = $this->latestEstimateVersion;
        if (! $latestVersion) {
            return 'not_submitted';
        }

        $latestEvent = $latestVersion->latestEvent;
        return $latestEvent ? $latestEvent->event_type : 'pending_approval';
    }

    /**
     * Get human-readable delivery status title.
     */
    public function getDeliveryStatusLabelAttribute(): string
    {
        return match ($this->normalized_delivery_status) {
            'planned'               => 'Planned',
            'ready'                 => 'Ready for Dev',
            'in_progress'           => 'In Progress',
            'review_qa'             => 'Review / QA',
            'customer_review'       => 'Customer Review',
            'ready_for_deployment' => 'Ready for Deployment',
            'completed'             => 'Completed',
            default           => ucfirst(str_replace('_', ' ', $this->normalized_delivery_status)),
        };
    }
}
