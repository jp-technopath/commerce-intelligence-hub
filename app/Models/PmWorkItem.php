<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'user_id',
        'external_assignee_id',
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

    protected static function booted(): void
    {
        static::saved(function (PmWorkItem $item) {
            if ($item->user_id) {
                app(\App\Services\Intelligence\WorkPrioritizationEngine::class)->invalidateUserPlan($item->user_id);
            }
            if ($item->isDirty('user_id') && $item->getOriginal('user_id')) {
                app(\App\Services\Intelligence\WorkPrioritizationEngine::class)->invalidateUserPlan($item->getOriginal('user_id'));
            }
        });

        static::deleted(function (PmWorkItem $item) {
            if ($item->user_id) {
                app(\App\Services\Intelligence\WorkPrioritizationEngine::class)->invalidateUserPlan($item->user_id);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PmConnection::class, 'pm_connection_id');
    }

    public function pmConnection(): BelongsTo
    {
        return $this->connection();
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
            'on_hold'               => 'On Hold',
            'rework'                => 'Rework',
            default                 => ucfirst(str_replace('_', ' ', $this->normalized_delivery_status)),
        };
    }

    /**
     * Direct link to the issue in Jira.
     */
    public function getJiraUrlAttribute(): ?string
    {
        if (empty($this->external_item_key)) {
            return null;
        }

        $conn = $this->relationLoaded('pmConnection')
            ? $this->getRelation('pmConnection')
            : ($this->pm_connection_id ? PmConnection::find($this->pm_connection_id) : null);

        $workspace = $conn?->external_workspace_id;
        if ($workspace) {
            $base = str_starts_with($workspace, 'http') ? $workspace : "https://{$workspace}";
            return rtrim($base, '/') . '/browse/' . $this->external_item_key;
        }

        $baseUrl = config('meeting_agent.jira.base_url') ?: env('JIRA_BASE_URL');
        if ($baseUrl) {
            return rtrim($baseUrl, '/') . '/browse/' . $this->external_item_key;
        }

        return 'https://technopath.atlassian.net/browse/' . $this->external_item_key;
    }

    /**
     * Check if task is categorized as backlog or on hold.
     */
    public function isBacklogOrOnHold(): bool
    {
        $norm = strtolower($this->normalized_delivery_status ?? '');
        if (in_array($norm, ['backlog', 'on_hold', 'hold'], true)) {
            return true;
        }

        $ext = strtolower($this->external_status ?? '');
        if (
            str_contains($ext, 'backlog') ||
            str_contains($ext, 'on hold') ||
            str_contains($ext, 'parking lot') ||
            str_contains($ext, 'archive')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check if task is canceled, rejected, or aborted.
     */
    public function isCanceled(): bool
    {
        $norm = strtolower($this->normalized_delivery_status ?? '');
        if (in_array($norm, ['cancelled', 'canceled', 'rejected', 'aborted'], true)) {
            return true;
        }

        $ext = strtolower($this->external_status ?? '');
        foreach (['cancel', 'reject', 'abort', "won't do", 'wont do', 'wontfix', "won't fix"] as $needle) {
            if (str_contains($ext, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if task is inactive or excluded (backlog, on hold, or canceled).
     */
    public function isInactiveOrExcluded(): bool
    {
        return $this->isBacklogOrOnHold() || $this->isCanceled();
    }

    /**
     * Scope a query to exclude tasks in backlog, on hold, or canceled.
     */
    public function scopeExcludeBacklogAndOnHold($query)
    {
        return $query
            ->whereNotIn('normalized_delivery_status', ['backlog', 'on_hold', 'hold', 'cancelled', 'canceled', 'rejected', 'aborted'])
            ->where(function ($q) {
                $q->whereNull('external_status')
                  ->orWhere(function ($sub) {
                      $sub->whereRaw('LOWER(external_status) NOT LIKE ?', ['%backlog%'])
                          ->whereRaw('LOWER(external_status) NOT LIKE ?', ['%on hold%'])
                          ->whereRaw('LOWER(external_status) NOT LIKE ?', ['%parking lot%'])
                          ->whereRaw('LOWER(external_status) NOT LIKE ?', ['%archive%'])
                          ->whereRaw('LOWER(external_status) NOT LIKE ?', ['%cancel%'])
                          ->whereRaw('LOWER(external_status) NOT LIKE ?', ['%reject%'])
                          ->whereRaw('LOWER(external_status) NOT LIKE ?', ['%abort%'])
                          ->whereRaw('LOWER(external_status) NOT LIKE ?', ['%wont%'])
                          ->whereRaw('LOWER(external_status) NOT LIKE ?', ["%won't%"]);
                  });
            });
    }

    /**
     * Alias for scopeExcludeBacklogAndOnHold to exclude all inactive tasks.
     */
    public function scopeExcludeInactive($query)
    {
        return $this->scopeExcludeBacklogAndOnHold($query);
    }

    /**
     * Scope query to only PM work items belonging to customers in the customer list
     * with an assigned Jira project code, restricted to spaces matching that Jira project code.
     */
    public function scopeForCustomerSpacesWithJiraCode(Builder $query, ?int $clientId = null): Builder
    {
        if ($clientId !== null) {
            $client = Client::find($clientId);
            if (! $client || empty($client->jira_project_key)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('pm_work_items.client_id', $client->id)
                ->where(function ($keyClause) use ($client) {
                    $keyClause->where('pm_work_items.external_item_key', 'LIKE', $client->jira_project_key . '-%')
                              ->orWhereHas('project', function ($pq) use ($client) {
                                  $pq->where('external_project_key', $client->jira_project_key);
                              });
                });
        }

        $validClients = Client::query()
            ->whereNotNull('jira_project_key')
            ->where('jira_project_key', '!=', '')
            ->get(['id', 'jira_project_key']);

        if ($validClients->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($sub) use ($validClients) {
            foreach ($validClients as $client) {
                $sub->orWhere(function ($clause) use ($client) {
                    $clause->where('pm_work_items.client_id', $client->id)
                           ->where(function ($keyClause) use ($client) {
                               $keyClause->where('pm_work_items.external_item_key', 'LIKE', $client->jira_project_key . '-%')
                                         ->orWhereHas('project', function ($pq) use ($client) {
                                             $pq->where('external_project_key', $client->jira_project_key);
                                         });
                           });
                });
            }
        });
    }
}

