<?php

namespace App\Models;

use App\Enums\FindingCategory;
use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\TargetBusinessRole;
use App\Services\VisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Finding extends Model
{
    protected $attributes = [
        'visibility_classification' => \App\Services\VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
        'is_customer_visible' => true,
    ];

    protected $fillable = [
        'client_id',
        'responsible_user_id',
        'approved_by_user_id',
        'dismissed_by_user_id',
        'project_id',
        'finding_type',
        'finding_pillar',
        'source_type',
        'source_id',
        'fingerprint',
        'account_identity_hash',
        'finding_category',
        'title',
        'description',
        'severity',
        'escalation_level',
        'confidence_score',
        'priority_score',
        'target_role',
        'execution_status',
        'outcome_status',
        'pm_work_item_id',
        'pm_idempotency_token',
        'estimated_revenue_impact',
        'impact_nature',
        'impact_assumptions_json',
        'status',
        'visibility_classification',
        'is_customer_visible',
        'metadata_json',
        'evidence_json',
        'status_history_json',
        'snoozed_until',
        'under_review_at',
        'approved_at',
        'assigned_at',
        'action_completed_at',
        'outcome_measured_at',
        'resolved_at',
        'resolution_notes',
        'dismissed_at',
        'dismissed_reason',
        'detected_at',
    ];

    protected $casts = [
        'finding_category'         => FindingCategory::class,
        'finding_pillar'           => \App\Enums\FindingPillar::class,
        'severity'                 => FindingSeverity::class,
        'status'                   => FindingStatus::class,
        'execution_status'         => FindingExecutionStatus::class,
        'outcome_status'           => FindingOutcomeStatus::class,
        'confidence_score'         => 'decimal:2',
        'priority_score'           => 'decimal:2',
        'estimated_revenue_impact' => 'decimal:2',
        'impact_nature'            => \App\Enums\ImpactNature::class,
        'impact_assumptions_json'  => 'array',
        'metadata_json'            => 'array',
        'evidence_json'            => 'array',
        'status_history_json'      => 'array',
        'snoozed_until'            => 'datetime',
        'under_review_at'          => 'datetime',
        'approved_at'              => 'datetime',
        'assigned_at'              => 'datetime',
        'action_completed_at'      => 'datetime',
        'outcome_measured_at'      => 'datetime',
        'resolved_at'              => 'datetime',
        'dismissed_at'             => 'datetime',
        'detected_at'              => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function dismissedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function investigationNotes(): HasMany
    {
        return $this->hasMany(InvestigationNote::class);
    }

    public function pmWorkItem(): BelongsTo
    {
        return $this->belongsTo(PmWorkItem::class, 'pm_work_item_id');
    }

    public function statusTransitions(): HasMany
    {
        return $this->hasMany(FindingStatusTransition::class)->orderBy('created_at', 'desc');
    }

    public function recommendationOutcome(): HasOne
    {
        return $this->hasOne(RecommendationOutcome::class, 'finding_id');
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(RecommendationOutcome::class, 'finding_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            FindingStatus::New->value,
            FindingStatus::UnderReview->value,
            FindingStatus::Approved->value,
            FindingStatus::Assigned->value,
            FindingStatus::Acknowledged->value,
            FindingStatus::Investigating->value,
            FindingStatus::Accepted->value,
        ])->where(function ($q) {
            $q->whereNull('snoozed_until')
              ->orWhere('snoozed_until', '<=', now());
        });
    }

    public function transitionTo(
        FindingStatus $targetStatus,
        ?User $user = null,
        ?string $notes = null,
        ?User $assignee = null,
        ?string $businessOutcome = null
    ): void {
        // Enforce permissions: Approval
        if (in_array($targetStatus, [FindingStatus::Approved, FindingStatus::Accepted], true)) {
            if ($user && ! \App\Services\ApprovalService::canApprove($user, $this, 'business_scope')) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    "User {$user->name} is not authorized to approve business initiatives for this customer."
                );
            }
        }

        // Enforce permissions: Assignment
        if ($targetStatus === FindingStatus::Assigned && $assignee) {
            $eligible = User::getAuthorizedUsersForClient($this->client_id);
            if (! $eligible->contains('id', $assignee->id)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'assignee' => ["User {$assignee->name} is not authorized to work on this customer."],
                ]);
            }
        }

        // Enforce resolution requirements: meaningful outcome notes
        if ($targetStatus === FindingStatus::Resolved) {
            $cleanNotes = trim((string) $notes);
            if (empty($cleanNotes) || strlen($cleanNotes) < 10) {
                throw new \InvalidArgumentException(
                    'Meaningful action notes (minimum 10 characters) detailing the action taken are required to resolve an opportunity.'
                );
            }
        }

        // Enforce dismissal requirements: reason is required
        if (in_array($targetStatus, [FindingStatus::Dismissed, FindingStatus::Ignored], true)) {
            $cleanReason = trim((string) $notes);
            if (empty($cleanReason)) {
                throw new \InvalidArgumentException('A dismissal reason is required to dismiss a finding.');
            }
        }

        // Execute in database transaction with row lock to preserve history during concurrent updates
        \Illuminate\Support\Facades\DB::transaction(function () use ($targetStatus, $user, $notes, $assignee, $businessOutcome) {
            /** @var static $fresh */
            $fresh = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $fromStatus = $fresh->status;
            $fromExecution = $fresh->execution_status ?? FindingExecutionStatus::New;
            $fromOutcome = $fresh->outcome_status ?? FindingOutcomeStatus::PendingExecution;
            $now = now();
            $history = is_array($fresh->status_history_json) ? $fresh->status_history_json : [];

            $toExecution = match ($targetStatus) {
                FindingStatus::New                                       => FindingExecutionStatus::New,
                FindingStatus::UnderReview, FindingStatus::Investigating => FindingExecutionStatus::UnderReview,
                FindingStatus::Approved, FindingStatus::Accepted         => FindingExecutionStatus::Approved,
                FindingStatus::Assigned                                  => FindingExecutionStatus::Assigned,
                FindingStatus::Resolved                                  => FindingExecutionStatus::ActionCompleted,
                FindingStatus::Dismissed, FindingStatus::Ignored         => FindingExecutionStatus::Dismissed,
                default                                                  => $fromExecution,
            };

            $toOutcome = match ($targetStatus) {
                FindingStatus::Resolved                          => FindingOutcomeStatus::AwaitingResults,
                FindingStatus::Dismissed, FindingStatus::Ignored => FindingOutcomeStatus::Dismissed,
                default                                          => $fromOutcome,
            };

            $historyEntry = [
                'from_status'           => $fromStatus?->value,
                'to_status'             => $targetStatus->value,
                'from_execution_status' => $fromExecution->value,
                'to_execution_status'   => $toExecution->value,
                'from_outcome_status'   => $fromOutcome->value,
                'to_outcome_status'     => $toOutcome->value,
                'changed_by_user_id'    => $user?->id,
                'changed_by_name'       => $user?->name,
                'changed_at'            => $now->toIso8601String(),
                'notes'                 => $notes,
            ];

            if ($businessOutcome !== null) {
                $historyEntry['business_outcome'] = $businessOutcome;
            }

            $history[] = $historyEntry;

            $updates = [
                'status'              => $targetStatus,
                'execution_status'    => $toExecution,
                'outcome_status'      => $toOutcome,
                'status_history_json' => $history,
            ];

            switch ($targetStatus) {
                case FindingStatus::UnderReview:
                case FindingStatus::Investigating:
                    $updates['under_review_at'] = $now;
                    break;
                case FindingStatus::Approved:
                case FindingStatus::Accepted:
                    $updates['approved_at'] = $now;
                    if ($user) {
                        $updates['approved_by_user_id'] = $user->id;
                    }
                    break;
                case FindingStatus::Assigned:
                    $updates['assigned_at'] = $now;
                    if ($assignee) {
                        $updates['responsible_user_id'] = $assignee->id;
                    } elseif ($user) {
                        $updates['responsible_user_id'] = $user->id;
                    }
                    break;
                case FindingStatus::Resolved:
                    $updates['resolved_at'] = $now;
                    $updates['action_completed_at'] = $now;
                    $formattedNotes = $notes;
                    if ($businessOutcome) {
                        $formattedNotes = "Outcome: " . \Illuminate\Support\Str::headline($businessOutcome) . " | Action: {$notes}";
                    }
                    $updates['resolution_notes'] = $formattedNotes;
                    // Note: estimated_revenue_impact is deliberately NOT modified or assumed recovered.
                    break;
                case FindingStatus::Dismissed:
                case FindingStatus::Ignored:
                    $updates['dismissed_at'] = $now;
                    if ($user) {
                        $updates['dismissed_by_user_id'] = $user->id;
                    }
                    $updates['dismissed_reason'] = $notes;
                    break;
                default:
                    break;
            }

            $fresh->update($updates);

            FindingStatusTransition::create([
                'finding_id'            => $fresh->id,
                'from_status'           => $fromStatus,
                'to_status'             => $targetStatus,
                'from_execution_status' => $fromExecution,
                'to_execution_status'   => $toExecution,
                'from_outcome_status'   => $fromOutcome,
                'to_outcome_status'     => $toOutcome,
                'user_id'               => $user?->id,
                'user_name'             => $user?->name,
                'transition_source'     => 'forge_ui',
                'notes'                 => $notes,
                'business_outcome'      => $businessOutcome,
                'created_at'            => $now,
            ]);
        });

        $this->refresh();
    }

    public function transitionExecutionState(
        FindingExecutionStatus $executionStatus,
        string $source = 'forge_ui',
        ?User $user = null,
        ?string $notes = null
    ): void {
        \Illuminate\Support\Facades\DB::transaction(function () use ($executionStatus, $source, $user, $notes) {
            /** @var static $fresh */
            $fresh = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $fromStatus = $fresh->status;
            $fromExecution = $fresh->execution_status ?? FindingExecutionStatus::New;
            $fromOutcome = $fresh->outcome_status ?? FindingOutcomeStatus::PendingExecution;
            $now = now();

            $toStatus = match ($executionStatus) {
                FindingExecutionStatus::New                                              => FindingStatus::New,
                FindingExecutionStatus::UnderReview                                      => FindingStatus::UnderReview,
                FindingExecutionStatus::Approved                                         => FindingStatus::Approved,
                FindingExecutionStatus::Assigned, FindingExecutionStatus::WorkInProgress => FindingStatus::Assigned,
                FindingExecutionStatus::ActionCompleted, FindingExecutionStatus::Closed  => FindingStatus::Resolved,
                FindingExecutionStatus::Dismissed                                        => FindingStatus::Dismissed,
            };

            $toOutcome = match ($executionStatus) {
                FindingExecutionStatus::ActionCompleted => FindingOutcomeStatus::AwaitingResults,
                FindingExecutionStatus::Dismissed       => FindingOutcomeStatus::Dismissed,
                default                                 => $fromOutcome,
            };

            $history = is_array($fresh->status_history_json) ? $fresh->status_history_json : [];
            $history[] = [
                'from_status'           => $fromStatus?->value,
                'to_status'             => $toStatus->value,
                'from_execution_status' => $fromExecution->value,
                'to_execution_status'   => $executionStatus->value,
                'changed_by_user_id'    => $user?->id,
                'changed_by_name'       => $user?->name,
                'changed_at'            => $now->toIso8601String(),
                'transition_source'     => $source,
                'notes'                 => $notes,
            ];

            $updates = [
                'status'              => $toStatus,
                'execution_status'    => $executionStatus,
                'outcome_status'      => $toOutcome,
                'status_history_json' => $history,
            ];

            if ($executionStatus === FindingExecutionStatus::ActionCompleted) {
                $updates['action_completed_at'] = $now;
                $updates['resolved_at'] = $now;
                if ($notes) {
                    $updates['resolution_notes'] = $notes;
                }
            }

            $fresh->update($updates);

            FindingStatusTransition::create([
                'finding_id'            => $fresh->id,
                'from_status'           => $fromStatus,
                'to_status'             => $toStatus,
                'from_execution_status' => $fromExecution,
                'to_execution_status'   => $executionStatus,
                'from_outcome_status'   => $fromOutcome,
                'to_outcome_status'     => $toOutcome,
                'user_id'               => $user?->id,
                'user_name'             => $user?->name,
                'transition_source'     => $source,
                'notes'                 => $notes,
                'created_at'            => $now,
            ]);
        });

        $this->refresh();
    }

    public function transitionOutcomeState(
        FindingOutcomeStatus $outcomeStatus,
        string $source = 'outcome_reconciliation',
        ?User $user = null,
        ?string $notes = null
    ): void {
        \Illuminate\Support\Facades\DB::transaction(function () use ($outcomeStatus, $source, $user, $notes) {
            /** @var static $fresh */
            $fresh = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $fromStatus = $fresh->status;
            $fromExecution = $fresh->execution_status ?? FindingExecutionStatus::New;
            $fromOutcome = $fresh->outcome_status ?? FindingOutcomeStatus::PendingExecution;
            $now = now();

            $history = is_array($fresh->status_history_json) ? $fresh->status_history_json : [];
            $history[] = [
                'from_outcome_status' => $fromOutcome->value,
                'to_outcome_status'   => $outcomeStatus->value,
                'changed_by_user_id'  => $user?->id,
                'changed_by_name'     => $user?->name,
                'changed_at'          => $now->toIso8601String(),
                'transition_source'   => $source,
                'notes'               => $notes,
            ];

            $updates = [
                'outcome_status'      => $outcomeStatus,
                'status_history_json' => $history,
            ];

            if ($outcomeStatus === FindingOutcomeStatus::OutcomeMeasured || $outcomeStatus === FindingOutcomeStatus::NoOrdersObserved) {
                $updates['outcome_measured_at'] = $now;
            }

            $fresh->update($updates);

            FindingStatusTransition::create([
                'finding_id'            => $fresh->id,
                'from_status'           => $fromStatus,
                'to_status'             => $fromStatus,
                'from_execution_status' => $fromExecution,
                'to_execution_status'   => $fromExecution,
                'from_outcome_status'   => $fromOutcome,
                'to_outcome_status'     => $outcomeStatus,
                'user_id'               => $user?->id,
                'user_name'             => $user?->name,
                'transition_source'     => $source,
                'notes'                 => $notes,
                'created_at'            => $now,
            ]);
        });

        $this->refresh();
    }

    public function isOpen(): bool
    {
        if ($this->status === FindingStatus::Snoozed && $this->snoozed_until && $this->snoozed_until->isFuture()) {
            return false;
        }

        return $this->status?->isOpen() ?? false;
    }

    public function acknowledge(): bool
    {
        return $this->update([
            'status' => FindingStatus::Acknowledged,
        ]);
    }

    public function snooze(\Carbon\CarbonInterface $until): bool
    {
        return $this->update([
            'status'        => FindingStatus::Snoozed,
            'snoozed_until' => $until,
        ]);
    }

    public function resolve(): bool
    {
        return $this->update([
            'status'      => FindingStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function dismiss(?string $reason = null): bool
    {
        return $this->update([
            'status'           => FindingStatus::Dismissed,
            'dismissed_at'     => now(),
            'dismissed_reason' => $reason,
        ]);
    }

    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('is_customer_visible', true)
            ->where(function (Builder $q) {
                $q->whereNull('visibility_classification')
                  ->orWhere('visibility_classification', '!=', VisibilityService::CLASSIFICATION_INTERNAL);
            })
            ->whereNotIn('finding_type', ['task_overdue', 'qa_rework', 'missing_estimates', 'blocked_items'])
            ->where(function (Builder $q) {
                $q->whereNull('finding_category')
                  ->orWhereNotIn('finding_category', [
                      FindingCategory::WorkPriority->value,
                      FindingCategory::WorkflowDelay->value,
                      FindingCategory::ResponseTime->value,
                      FindingCategory::ProjectRisk->value,
                      FindingCategory::TimeTracking->value,
                      FindingCategory::MeetingPreparation->value,
                      FindingCategory::MeetingFollowUp->value,
                  ]);
            });
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('is_customer_visible', false)
              ->orWhere('visibility_classification', VisibilityService::CLASSIFICATION_INTERNAL)
              ->orWhereIn('finding_type', ['task_overdue', 'qa_rework', 'missing_estimates', 'blocked_items'])
              ->orWhereIn('finding_category', [
                  FindingCategory::WorkPriority->value,
                  FindingCategory::WorkflowDelay->value,
                  FindingCategory::ResponseTime->value,
                  FindingCategory::ProjectRisk->value,
                  FindingCategory::TimeTracking->value,
                  FindingCategory::MeetingPreparation->value,
                  FindingCategory::MeetingFollowUp->value,
              ]);
        });
    }

    public function isCustomerVisible(): bool
    {
        if (! $this->is_customer_visible) {
            return false;
        }

        if ($this->visibility_classification === VisibilityService::CLASSIFICATION_INTERNAL) {
            return false;
        }

        if (in_array($this->finding_type, ['task_overdue', 'qa_rework', 'missing_estimates', 'blocked_items'], true)) {
            return false;
        }

        $category = $this->finding_category instanceof FindingCategory ? $this->finding_category->value : (string) $this->finding_category;
        if (in_array($category, [
            FindingCategory::WorkPriority->value,
            FindingCategory::WorkflowDelay->value,
            FindingCategory::ResponseTime->value,
            FindingCategory::ProjectRisk->value,
            FindingCategory::TimeTracking->value,
            FindingCategory::MeetingPreparation->value,
            FindingCategory::MeetingFollowUp->value,
        ], true)) {
            return false;
        }

        return true;
    }

    public function isInternal(): bool
    {
        return ! $this->isCustomerVisible();
    }

    public function getJiraUrlAttribute(): ?string
    {
        $key = $this->evidence_json['item_key'] ?? $this->evidence_json['external_item_key'] ?? null;
        if (! $key && is_array($this->metadata_json)) {
            $key = $this->metadata_json['item_key'] ?? $this->metadata_json['key'] ?? null;
        }

        if ($key) {
            $baseUrl = config('meeting_agent.jira.base_url') ?: 'https://technopath.atlassian.net';
            return rtrim($baseUrl, '/') . '/browse/' . $key;
        }

        if (in_array($this->source_type, ['pm_work_item', PmWorkItem::class], true) && $this->source_id) {
            $item = PmWorkItem::find($this->source_id);
            return $item?->jira_url;
        }

        return null;
    }

    /**
     * Whether the operational action has been completed by the representative / PM work item.
     * Note: Action completion documents operational execution and transitions to outcome observation.
     * It strictly does NOT imply successful commercial revenue recovery.
     */
    public function isActionCompleted(): bool
    {
        return in_array($this->execution_status, [
            FindingExecutionStatus::ActionCompleted,
            FindingExecutionStatus::Closed,
        ], true);
    }

    /**
     * Whether the commercial business outcome has been empirically measured with Commerce order evidence.
     */
    public function isOutcomeMeasured(): bool
    {
        return $this->outcome_status === FindingOutcomeStatus::OutcomeMeasured;
    }

    /**
     * Whether verified Commerce orders were observed during the post-action observation window.
     * Note: Observed orders represent verified gross transactional activity, not net incremental lift.
     */
    public function hasObservedPostActionOrders(): bool
    {
        return $this->outcome_status === FindingOutcomeStatus::OutcomeMeasured
            && $this->outcomes()->where('observed_revenue', '>', 0)->exists();
    }

    /**
     * Get the verified gross observed revenue from matched Commerce orders.
     */
    public function getObservedPostActionRevenue(): float
    {
        return (float) ($this->outcomes()->max('observed_revenue') ?? 0.00);
    }

    /**
     * Whether modeled incremental uplift above pre-dormancy baseline was calculated.
     * Note: Incremental uplift is modeled run-rate lift, NOT causally verified recovery.
     */
    public function hasModeledIncrementalLift(): bool
    {
        return $this->outcome_status === FindingOutcomeStatus::OutcomeMeasured
            && $this->outcomes()->where('actual_incremental_impact', '>', 0)->exists();
    }

    /**
     * Get the modeled incremental uplift above pre-action baseline.
     */
    public function getModeledIncrementalLift(): float
    {
        return (float) ($this->outcomes()->max('actual_incremental_impact') ?? 0.00);
    }

    /**
     * Whether recovered revenue was causally proven through randomized control holdouts
     * or documented causal verification evidence.
     * Note: Positive modeled incremental lift alone is strictly NOT treated as causally verified recovery.
     */
    public function hasCausallyVerifiedRecovery(): bool
    {
        return $this->outcome_status === FindingOutcomeStatus::OutcomeMeasured
            && $this->outcomes()
                ->where('actual_incremental_impact', '>', 0)
                ->where(function ($q) {
                    $q->where('attribution_method', 'causal_holdout')
                      ->orWhereNotNull('verified_at');
                })
                ->exists();
    }

    /**
     * @deprecated Use hasObservedPostActionOrders(), hasModeledIncrementalLift(), or hasCausallyVerifiedRecovery().
     * Note: A positive modeled incremental lift must not automatically be treated as verified or causally attributable recovered revenue.
     */
    public function hasVerifiedRevenueRecovery(): bool
    {
        return $this->hasCausallyVerifiedRecovery();
    }
}

