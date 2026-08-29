<?php

namespace App\Models;

use App\Enums\DevelopmentRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DevelopmentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'project_id',
        'owner_user_id',
        'parent_request_id',
        'request_type',
        'state',
        'title',
        'description',
        'status_reason',
        'environment_key',
        'project_environment_mapping_id',
        'execution_target_key',
        'vm_startup_deadline_at',
        'vm_ready_at',
        'source_type',
        'source_id',
        'priority',
        'correlation_identifier',
        'active_run_correlation_id',
        'jira_snapshot',
        'routing_snapshot',
        'selected_capability_tier',
        'pm_work_item_id',
    ];

    protected $casts = [
        'state' => DevelopmentRequestStatus::class,
        'jira_snapshot' => 'json',
        'routing_snapshot' => 'json',
        'vm_startup_deadline_at' => 'datetime',
        'vm_ready_at' => 'datetime',
    ];

    /**
     * The client this request is associated with.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The project this development request belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The user who owns this development request.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * The parent request if this is a reopened request.
     */
    public function parentRequest(): BelongsTo
    {
        return $this->belongsTo(DevelopmentRequest::class, 'parent_request_id');
    }

    /**
     * Child requests (reopenings) of this request.
     */
    public function childRequests(): HasMany
    {
        return $this->hasMany(DevelopmentRequest::class, 'parent_request_id');
    }

    /**
     * The status history for this request.
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(DevelopmentRequestStatusHistory::class)
            ->orderBy('created_at', 'asc');
    }

    /**
     * The associated PM work item (Jira, etc.).
     */
    public function pmWorkItem(): BelongsTo
    {
        return $this->belongsTo(PmWorkItem::class);
    }

    public function projectEnvironmentMapping(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironmentMapping::class);
    }

    public function vmLifecycleActions(): HasMany
    {
        return $this->hasMany(VmLifecycleAction::class);
    }

    /**
     * Get all state transitions for this request in order.
     */
    public function getStateTransitions()
    {
        return $this->statusHistory()->pluck('new_state', 'created_at')->all();
    }

    /**
     * Check if the current state is a terminal state.
     */
    public function isTerminalState(): bool
    {
        return $this->state?->isTerminal() ?? false;
    }

    /**
     * Get the most recent status history entry.
     */
    public function latestStatusHistoryEntry()
    {
        return $this->statusHistory()->latest('created_at')->first();
    }
}
