<?php

namespace App\Services\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\PmConnection;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\Security\ClientScopeContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WorkPrioritizationEngine
{
    public const ROLE_SALES              = 'sales';
    public const ROLE_MARKETING          = 'marketing';
    public const ROLE_ENGINEERING        = 'engineering';
    public const ROLE_ANALYTICS          = 'analytics';
    public const ROLE_PROJECT_MANAGEMENT = 'project_management';

    protected MeetingIntelligenceService $meetingIntelligence;
    protected TimeTrackingService $timeTracking;
    protected ProjectAllocationHealthService $allocationHealth;

    public function __construct(
        MeetingIntelligenceService $meetingIntelligence,
        TimeTrackingService $timeTracking,
        ProjectAllocationHealthService $allocationHealth
    ) {
        $this->meetingIntelligence = $meetingIntelligence;
        $this->timeTracking = $timeTracking;
        $this->allocationHealth = $allocationHealth;
    }

    /**
     * Get or build prioritized work plan for an individual user (engineer/analyst).
     */
    public function getPrioritizedPlan(User $user, bool $forceRefresh = false): array
    {
        $cacheKey = "forge:work_plan:user:{$user->id}";

        if (! $forceRefresh && Cache::has($cacheKey)) {
            $plan = Cache::get($cacheKey);
            $plan['is_cached'] = true;
            return $plan;
        }

        $plan = $this->generatePlan($user);

        // Cache plan for 30 minutes; invalidation occurs on event triggers
        Cache::put($cacheKey, $plan, now()->addMinutes(30));

        return $plan;
    }

    /**
     * Explicitly invalidate cached plan for a user.
     */
    public function invalidateUserPlan(int|User $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        Cache::forget("forge:work_plan:user:{$userId}");
    }

    /**
     * Invalidate all work plans (e.g. after global PM sync).
     */
    public function invalidateAllPlans(): void
    {
        User::query()->select('id')->chunk(100, function ($users) {
            foreach ($users as $u) {
                Cache::forget("forge:work_plan:user:{$u->id}");
            }
        });
    }

    /**
     * Get prioritized work plan for a specific business role.
     * Roles: sales, marketing, engineering, analytics, project_management.
     */
    public function getPrioritizedPlanForRole(string $role, ?int $clientId = null, ?User $actor = null): array
    {
        if ($clientId !== null) {
            ClientScopeContext::assertAuthorizedForClient($clientId, $actor);
        }

        $now = now();

        $query = Finding::with(['client', 'responsibleUser', 'pmWorkItem'])
            ->whereNotIn('status', [FindingStatus::Resolved->value, FindingStatus::Dismissed->value]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        } elseif ($actor && ! $actor->is_admin) {
            $assignedIds = $actor->getAssignedClientIds();
            if (! empty(array_diff($assignedIds, ['*']))) {
                $query->whereIn('client_id', array_diff($assignedIds, ['*']));
            }
        }

        // Role filtering: target_role matching, with fallback inference
        $query->where(function ($q) use ($role) {
            $q->where('target_role', $role)
              ->orWhere(function ($sub) use ($role) {
                  $sub->whereNull('target_role')
                      ->where(function ($nested) use ($role) {
                          match ($role) {
                              self::ROLE_SALES => $nested->where('finding_pillar', FindingPillar::GrowthOpportunity->value)
                                  ->whereIn('finding_category', ['Revenue', 'Customer']),
                              self::ROLE_MARKETING => $nested->where('finding_pillar', FindingPillar::GrowthOpportunity->value)
                                  ->whereIn('finding_category', ['Conversion', 'Merchandising', 'Search']),
                              self::ROLE_ENGINEERING => $nested->where('finding_pillar', FindingPillar::TechnicalHealth->value)
                                  ->orWhere('finding_category', 'Technical'),
                              self::ROLE_ANALYTICS => $nested->where('finding_category', 'Behavioral'),
                              self::ROLE_PROJECT_MANAGEMENT => $nested->where('finding_pillar', FindingPillar::BusinessRisk->value)
                                  ->orWhereIn('finding_category', ['WorkPriority', 'WorkflowDelay', 'ProjectRisk', 'TimeTracking']),
                              default => null,
                          };
                      });
              });
        });

        $findings = $query->get();

        $executableActions = [];
        $needsAttention = [];
        $totalRevenueImpact = 0.0;
        $budgetWarningsCount = 0;

        foreach ($findings as $finding) {
            $totalRevenueImpact += (float) ($finding->estimated_revenue_impact ?? 0);

            $scoreData = $this->calculateFindingScore($finding, $now);

            $actionData = [
                'type'                     => 'finding',
                'finding_id'               => $finding->id,
                'title'                    => $finding->title,
                'client_id'                => $finding->client_id,
                'client_name'              => $finding->client?->name ?? 'Client',
                'finding_pillar'           => $finding->finding_pillar?->value ?? 'growth_opportunity',
                'finding_category'         => $finding->finding_category?->value ?? 'Revenue',
                'severity'                 => $finding->severity instanceof FindingSeverity ? $finding->severity->value : (string) $finding->severity,
                'status'                   => $finding->status instanceof FindingStatus ? $finding->status->value : (string) $finding->status,
                'execution_status'         => $finding->execution_status instanceof FindingExecutionStatus ? $finding->execution_status->value : (string) $finding->execution_status,
                'outcome_status'           => $finding->outcome_status instanceof FindingOutcomeStatus ? $finding->outcome_status->value : (string) $finding->outcome_status,
                'target_role'              => $role,
                'estimated_revenue_impact' => (float) ($finding->estimated_revenue_impact ?? 0),
                'score'                    => $scoreData['score'],
                'reasoning'                => $scoreData['reasoning'],
                'suggested_next_step'      => $scoreData['suggested_step'],
                'pm_work_item_key'         => $finding->pmWorkItem?->external_item_key,
                'budget_warning'           => $scoreData['budget_warning'],
                'requires_budget_approval' => $scoreData['requires_budget_approval'],
                'assigned_user_name'       => $finding->responsibleUser?->name ?? 'Unassigned',
            ];

            if ($scoreData['requires_budget_approval']) {
                $budgetWarningsCount++;
            }

            if ($finding->status === FindingStatus::New || $finding->status === FindingStatus::UnderReview) {
                $needsAttention[] = array_merge($actionData, [
                    'attention_reason' => 'Awaiting management approval before action can begin.',
                ]);
            } else {
                $executableActions[] = $actionData;
            }
        }

        // Sort descending by score
        usort($executableActions, fn ($a, $b) => $b['score'] <=> $a['score']);
        $topRecommendations = array_slice($executableActions, 0, 5);

        return [
            'role'                 => $role,
            'role_label'           => $this->getRoleLabel($role),
            'recommended_order'    => $topRecommendations,
            'all_executable_count' => count($executableActions),
            'total_revenue_impact' => round($totalRevenueImpact, 2),
            'budget_warnings'      => $budgetWarningsCount,
            'needs_attention'      => $needsAttention,
            'generated_at'         => $now->toDateTimeString(),
        ];
    }

    /**
     * Get multi-role manager overview of daily work, budget exhaustion warnings, and milestones.
     */
    public function getManagerOverview(?int $clientId = null, ?User $actor = null): array
    {
        if ($clientId !== null) {
            ClientScopeContext::assertAuthorizedForClient($clientId, $actor);
        }

        $now = now();
        $roles = [
            self::ROLE_SALES              => 'Sales & Account Management',
            self::ROLE_MARKETING          => 'Growth Marketing',
            self::ROLE_ENGINEERING        => 'Engineering & Technical Delivery',
            self::ROLE_ANALYTICS          => 'Data & Analytics',
            self::ROLE_PROJECT_MANAGEMENT => 'Project Management',
        ];

        $rolePlans = [];
        $allPriorities = [];
        $totalActiveActions = 0;
        $totalPotentialRevenue = 0.0;
        $totalBudgetWarnings = 0;

        foreach ($roles as $roleKey => $roleLabel) {
            $plan = $this->getPrioritizedPlanForRole($roleKey, $clientId, $actor);
            $rolePlans[$roleKey] = $plan;
            $totalActiveActions += $plan['all_executable_count'];
            $totalPotentialRevenue += $plan['total_revenue_impact'];
            $totalBudgetWarnings += $plan['budget_warnings'];

            foreach ($plan['recommended_order'] as $item) {
                $item['role'] = $roleKey;
                $item['role_label'] = $roleLabel;
                $allPriorities[] = $item;
            }
        }

        usort($allPriorities, fn ($a, $b) => $b['score'] <=> $a['score']);
        $topPriorities = array_slice($allPriorities, 0, 5);

        // Gather clients over budget
        $clientsQuery = Client::query()->where('status', 'active');
        if ($clientId) {
            $clientsQuery->where('id', $clientId);
        }
        $clientsOverBudget = [];
        foreach ($clientsQuery->get() as $client) {
            $health = $this->allocationHealth->evaluateClientAllocation($client, $now);
            if ($health['status'] === 'over_allocation') {
                $clientsOverBudget[] = [
                    'client_id'       => $client->id,
                    'client_name'     => $client->name,
                    'actual_hours'    => $health['actual_hours'],
                    'allocated_hours' => $health['allocated_hours'],
                    'consumption_pct' => $health['consumption_pct'],
                    'warning_message' => "Client [{$client->name}] has consumed {$health['consumption_pct']}% of allocated hours. Budget threshold exceeded — approval gate required.",
                ];
            }
        }

        // Milestone counts
        $milestoneQuery = Finding::query();
        if ($clientId) {
            $milestoneQuery->where('client_id', $clientId);
        }
        $actionsCompletedCount = (clone $milestoneQuery)
            ->where('execution_status', FindingExecutionStatus::ActionCompleted->value)
            ->count();
        $awaitingResultsCount = (clone $milestoneQuery)
            ->where('outcome_status', FindingOutcomeStatus::AwaitingResults->value)
            ->count();
        $outcomesMeasuredCount = (clone $milestoneQuery)
            ->where('outcome_status', FindingOutcomeStatus::OutcomeMeasured->value)
            ->count();

        return [
            'roles'                    => $rolePlans,
            'portfolio_summary'        => [
                'total_active_actions'    => $totalActiveActions,
                'total_potential_revenue' => round($totalPotentialRevenue, 2),
                'total_budget_warnings'   => $totalBudgetWarnings,
                'actions_completed_count' => $actionsCompletedCount,
                'awaiting_results_count'  => $awaitingResultsCount,
                'outcomes_measured_count' => $outcomesMeasuredCount,
            ],
            'top_immediate_priorities' => $topPriorities,
            'clients_over_budget'      => $clientsOverBudget,
            'generated_at'             => $now->toDateTimeString(),
        ];
    }

    /**
     * Compute multi-factor deterministic priority score for a Finding.
     * Incorporates revenue impact, severity, momentum, customer health, and budget warnings
     * WITHOUT artificially suppressing the underlying business value.
     */
    public function calculateFindingScore(Finding $finding, Carbon $now): array
    {
        $score = 50;
        $reasons = [];

        // 1. Estimated Revenue Impact
        $revenue = (float) $finding->estimated_revenue_impact;
        if ($revenue >= 20000) {
            $score += 30;
            $reasons[] = 'High revenue impact ($' . number_format($revenue, 2) . ')';
        } elseif ($revenue >= 10000) {
            $score += 20;
            $reasons[] = 'Substantial revenue impact ($' . number_format($revenue, 2) . ')';
        } elseif ($revenue >= 5000) {
            $score += 15;
            $reasons[] = 'Moderate revenue impact ($' . number_format($revenue, 2) . ')';
        } elseif ($revenue > 0) {
            $score += 10;
            $reasons[] = 'Revenue opportunity ($' . number_format($revenue, 2) . ')';
        }

        // 2. Severity weight
        $severity = $finding->severity instanceof FindingSeverity ? $finding->severity->value : (string) $finding->severity;
        match (strtolower($severity)) {
            'critical' => [$score += 25, $reasons[] = 'Critical severity'],
            'high'     => [$score += 15, $reasons[] = 'High severity'],
            'medium'   => [$score += 5],
            default    => null,
        };

        // 3. Execution Momentum
        $execStatus = $finding->execution_status instanceof FindingExecutionStatus ? $finding->execution_status->value : (string) $finding->execution_status;
        match ($execStatus) {
            'work_in_progress' => [$score += 20, $reasons[] = 'Active work in progress'],
            'assigned'         => [$score += 10, $reasons[] = 'Assigned for execution'],
            'approved'         => [$score += 5,  $reasons[] = 'Approved by leadership'],
            default            => null,
        };

        // 4. Customer Health factor
        if ($finding->client && $finding->client->status?->value === 'at_risk') {
            $score += 15;
            $reasons[] = 'At-risk customer priority';
        }

        // 5. Budget Threshold Safeguard (checks client allocation)
        $budgetWarning = null;
        if ($finding->client) {
            $health = $this->allocationHealth->evaluateClientAllocation($finding->client, $now);
            if ($health['status'] === 'over_allocation') {
                $budgetWarning = [
                    'is_budget_exhausted' => true,
                    'consumption_pct'     => $health['consumption_pct'],
                    'actual_hours'        => $health['actual_hours'],
                    'allocated_hours'     => $health['allocated_hours'],
                    'warning_message'     => "Client [{$finding->client->name}] has consumed {$health['consumption_pct']}% of allocated hours. Budget threshold exceeded — approval gate required.",
                ];
                $reasons[] = 'Budget threshold warning: >100% consumed (Approval required)';
            }
        }

        return [
            'score'                    => min(100, max(1, $score)),
            'reasoning'                => implode(' • ', $reasons) ?: 'Standard business priority',
            'suggested_step'           => $this->getSuggestedStepForFinding($finding),
            'budget_warning'           => $budgetWarning,
            'requires_budget_approval' => $budgetWarning !== null,
        ];
    }

    /**
     * Compute multi-factor deterministic priority score for an executable task.
     * Incorporates due date urgency, priority, momentum, customer health, and budget warnings
     * WITHOUT artificially suppressing the underlying business value.
     */
    protected function calculateTaskScore(PmWorkItem $item, Carbon $now): array
    {
        $score = 50;
        $reasons = [];

        // 1. Due Date Urgency
        if ($item->target_due_date) {
            $due = Carbon::parse($item->target_due_date);
            if ($due->isPast()) {
                $score += 35;
                $reasons[] = 'Overdue (' . $due->diffForHumans() . ')';
            } elseif ($due->isToday()) {
                $score += 25;
                $reasons[] = 'Due today';
            } elseif ($due->diffInDays($now) <= 2) {
                $score += 15;
                $reasons[] = 'Due in ' . max(1, (int) $due->diffInDays($now)) . ' days';
            }
        }

        // 2. Priority weight
        $priorityLower = strtolower($item->priority ?: 'medium');
        match ($priorityLower) {
            'highest', 'critical' => [$score += 35, $reasons[] = 'Critical priority'],
            'high'                => [$score += 20, $reasons[] = 'High priority'],
            'medium'              => [$score += 5],
            default               => null,
        };

        // 3. Workflow In-Progress Momentum
        if ($item->normalized_delivery_status === 'in_progress') {
            $score += 15;
            $reasons[] = 'Already in progress';
        } elseif ($item->normalized_delivery_status === 'rework') {
            $score += 25;
            $reasons[] = 'QA Rework requested';
        } elseif ($item->normalized_delivery_status === 'review_qa') {
            $score += 10;
            $reasons[] = 'Pending QA verification';
        }

        // 4. Customer Health factor
        if ($item->client && $item->client->status?->value === 'at_risk') {
            $score += 10;
            $reasons[] = 'High-touch customer';
        }

        // 5. Budget Threshold Safeguard (checks client allocation)
        $budgetWarning = null;
        if ($item->client) {
            $health = $this->allocationHealth->evaluateClientAllocation($item->client, $now);
            if ($health['status'] === 'over_allocation') {
                $budgetWarning = [
                    'is_budget_exhausted' => true,
                    'consumption_pct'     => $health['consumption_pct'],
                    'actual_hours'        => $health['actual_hours'],
                    'allocated_hours'     => $health['allocated_hours'],
                    'warning_message'     => "Client [{$item->client->name}] has consumed {$health['consumption_pct']}% of allocated hours. Budget threshold exceeded — approval gate required.",
                ];
                $reasons[] = 'Budget threshold warning: >100% consumed (Approval required)';
            }
        }

        $suggestedStep = match ($item->normalized_delivery_status) {
            'in_progress' => 'Continue active development',
            'rework'      => 'Fix QA feedback and re-submit',
            'review_qa'   => 'Verify staging build and unblock QA',
            default       => 'Begin implementation',
        };

        return [
            'score'                    => min(100, max(1, $score)),
            'reasoning'                => implode(' • ', $reasons) ?: 'Scheduled task',
            'suggested_step'           => $suggestedStep,
            'budget_warning'           => $budgetWarning,
            'requires_budget_approval' => $budgetWarning !== null,
        ];
    }

    /**
     * Generate the prioritized work plan for an engineer/user.
     */
    protected function generatePlan(User $user): array
    {
        $now = now();

        $effectiveUser = \App\Services\PM\IdentityResolverService::resolveEffectiveUser($user);
        $targetUserIds = array_unique([$user->id, $effectiveUser->id]);

        $names = array_filter([$user->name, $effectiveUser->name]);
        if (in_array(2, $targetUserIds)) {
            $names = array_merge($names, ['Jean Paul Hanna', 'Jean Paul']);
        } elseif (in_array(3, $targetUserIds)) {
            $names = array_merge($names, ['Donia Khaled', 'Donia Attwa']);
        } elseif (in_array(4, $targetUserIds)) {
            $names = array_merge($names, ['Nour eldin ayman', 'Nour Ayman']);
        } elseif (in_array(5, $targetUserIds)) {
            $names = array_merge($names, ['Nick Barretta']);
        }
        $names = array_unique($names);

        // 1. Fetch user's assigned work items
        $items = PmWorkItem::with(['client', 'project', 'pmConnection'])
            ->forCustomerSpacesWithJiraCode()
            ->where(function ($q) use ($targetUserIds, $names) {
                $q->whereIn('user_id', $targetUserIds);
                foreach ($names as $name) {
                    $q->orWhere('assignee_name', $name)
                      ->orWhere('assignee_name', 'like', "%{$name}%");
                }
            })
            ->excludeBacklogAndOnHold()
            ->excludeCompletedAndDone()
            ->get();

        // Check sync freshness across user's connected connections
        $lastSync = PmConnection::max('last_synced_at');
        $isSyncStale = ! $lastSync || Carbon::parse($lastSync)->diffInHours($now) >= 2;

        $executableItems = [];
        $needsAttention = [];

        // 2. Separate strictly: Blocked vs. Executable
        foreach ($items as $item) {
            if ($item->isInactiveOrExcluded() || $item->isResolved()) {
                continue;
            }

            $isBlocked = (bool) $item->is_blocked 
                || $item->normalized_delivery_status === 'blocked'
                || $item->hasLabel('blocked');

            if ($isBlocked) {
                $needsAttention[] = [
                    'item_id'             => $item->id,
                    'key'                 => $item->external_item_key,
                    'title'               => $item->summary,
                    'client_name'         => $item->client?->name ?? 'Internal',
                    'reason'              => $item->blocked_reason ?: 'Task is flagged as blocked waiting on resolution',
                    'priority'            => $item->priority,
                    'target_due_date'     => $item->target_due_date?->format('M j, Y'),
                    'action_label'        => 'Resolve Blocker',
                    'delivery_status'     => $item->normalized_delivery_status,
                    'jira_url'            => $item->jira_url,
                ];
                continue;
            }

            if ($item->normalized_delivery_status === 'customer_review' || str_contains(strtolower($item->external_status ?? ''), 'waiting')) {
                continue;
            }

            $scoreData = $this->calculateTaskScore($item, $now);
            $executableItems[] = [
                'item_id'                  => $item->id,
                'key'                      => $item->external_item_key,
                'title'                    => $item->summary,
                'client_name'              => $item->client?->name ?? 'Internal',
                'priority'                 => $item->priority,
                'score'                    => $scoreData['score'],
                'reasoning'                => $scoreData['reasoning'],
                'suggested_next_step'      => $scoreData['suggested_step'],
                'estimated_hours'          => $item->estimated_hours,
                'time_spent_hours'         => $item->time_spent_hours,
                'delivery_status'          => $item->delivery_status_label,
                'target_due_date'          => $item->target_due_date?->format('M j, Y'),
                'is_overdue'               => $item->target_due_date && $item->target_due_date->isPast(),
                'jira_url'                 => $item->jira_url,
                'budget_warning'           => $scoreData['budget_warning'],
                'requires_budget_approval' => $scoreData['requires_budget_approval'],
            ];
        }

        // Sort executable items descending by AI priority score
        usort($executableItems, fn ($a, $b) => $b['score'] <=> $a['score']);
        $topRecommendations = array_slice($executableItems, 0, 5);

        // 3. Meeting Preparation items
        $upcomingMeetings = $this->meetingIntelligence->getUserUpcomingMeetings($user, 2);
        $meetingPrep = [];
        foreach ($upcomingMeetings as $meeting) {
            if (in_array($meeting->prep_stage, ['needed', 'draft_generated'], true)) {
                $meetingPrep[] = [
                    'meeting_id'       => $meeting->id,
                    'title'            => $meeting->title,
                    'client_name'      => $meeting->client?->name ?? 'Customer Meeting',
                    'meeting_start_at' => $meeting->meeting_start_at?->toDayDateTimeString() ?? 'Upcoming',
                    'prep_stage'       => $meeting->prep_stage,
                    'action_label'     => $meeting->prep_stage === 'needed' ? 'Prepare Brief' : 'Review Draft',
                ];
            }
        }

        return [
            'recommended_order'    => $topRecommendations,
            'all_executable_count' => count($executableItems),
            'needs_attention'      => $needsAttention,
            'meeting_prep'         => $meetingPrep,
            'generated_at'         => $now->toDateTimeString(),
            'is_sync_stale'        => $isSyncStale,
            'last_synced_at'       => $lastSync ? Carbon::parse($lastSync)->diffForHumans() : 'Never',
            'is_cached'            => false,
        ];
    }

    protected function getSuggestedStepForFinding(Finding $finding): string
    {
        if ($finding->execution_status === FindingExecutionStatus::ActionCompleted) {
            return 'Awaiting commercial outcome measurement';
        }

        if ($finding->pm_work_item_id) {
            return 'Deliver PM ticket: ' . ($finding->pmWorkItem?->external_item_key ?? 'Linked');
        }

        if ($finding->execution_status === FindingExecutionStatus::Assigned) {
            return 'Create PM work item or initiate direct customer outreach';
        }

        if ($finding->status === FindingStatus::Approved) {
            return 'Assign to team member or create work item';
        }

        return 'Review business opportunity';
    }

    protected function getRoleLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_SALES              => 'Sales & Account Management',
            self::ROLE_MARKETING          => 'Growth Marketing',
            self::ROLE_ENGINEERING        => 'Engineering & Technical Delivery',
            self::ROLE_ANALYTICS          => 'Data & Analytics',
            self::ROLE_PROJECT_MANAGEMENT => 'Project Management',
            default                       => ucfirst($role),
        };
    }
}
