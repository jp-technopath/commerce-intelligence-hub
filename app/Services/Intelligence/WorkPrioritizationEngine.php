<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmWorkItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WorkPrioritizationEngine
{
    protected MeetingIntelligenceService $meetingIntelligence;
    protected TimeTrackingService $timeTracking;

    public function __construct(
        MeetingIntelligenceService $meetingIntelligence,
        TimeTrackingService $timeTracking
    ) {
        $this->meetingIntelligence = $meetingIntelligence;
        $this->timeTracking = $timeTracking;
    }

    /**
     * Get or build prioritized work plan for an engineer.
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
     * Explicitly invalidate cached plan for a user (called on any of the 9 triggers).
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
        // Flush user work plan keys
        User::query()->select('id')->chunk(100, function ($users) {
            foreach ($users as $u) {
                Cache::forget("forge:work_plan:user:{$u->id}");
            }
        });
    }

    /**
     * Generate the prioritized work plan.
     */
    protected function generatePlan(User $user): array
    {
        $now = now();

        // 1. Fetch user's assigned work items
        // Match either direct user_id or assignee_name / external_assignee_id
        $items = PmWorkItem::with(['client', 'project', 'pmConnection'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('assignee_name', $user->name);
            })
            ->excludeBacklogAndOnHold()
            ->whereNotIn('normalized_delivery_status', ['completed', 'cancelled', 'canceled'])
            ->get();

        // Check sync freshness across user's connected connections
        $lastSync = PmConnection::max('last_synced_at');
        $isSyncStale = ! $lastSync || Carbon::parse($lastSync)->diffInHours($now) >= 2;

        $executableItems = [];
        $needsAttention = [];

        // 2. Separate strictly: Blocked vs. Executable (strictly excluding backlog, on hold, and canceled)
        foreach ($items as $item) {
            if ($item->isInactiveOrExcluded()) {
                continue;
            }

            $isBlocked = (bool) $item->is_blocked 
                || $item->normalized_delivery_status === 'blocked'
                || $item->hasLabel('blocked');

            if ($isBlocked) {
                // Rule 5: Blocked or unready tasks CANNOT appear in executable work plan
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

            // Calculate prioritization score for executable tasks
            $scoreData = $this->calculateTaskScore($item, $now);
            $executableItems[] = [
                'item_id'             => $item->id,
                'key'                 => $item->external_item_key,
                'title'               => $item->summary,
                'client_name'         => $item->client?->name ?? 'Internal',
                'priority'            => $item->priority,
                'score'               => $scoreData['score'],
                'reasoning'           => $scoreData['reasoning'],
                'suggested_next_step' => $scoreData['suggested_step'],
                'estimated_hours'     => $item->estimated_hours,
                'time_spent_hours'    => $item->time_spent_hours,
                'delivery_status'     => $item->delivery_status_label,
                'target_due_date'     => $item->target_due_date?->format('M j, Y'),
                'is_overdue'          => $item->target_due_date && $item->target_due_date->isPast(),
                'jira_url'            => $item->jira_url,
            ];
        }

        // Sort executable items descending by AI priority score
        usort($executableItems, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Limit top executable recommendations to 5 items
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
                    'meeting_start_at' => $meeting->meeting_start_at->toDayDateTimeString(),
                    'prep_stage'       => $meeting->prep_stage,
                    'action_label'     => $meeting->prep_stage === 'needed' ? 'Prepare Brief' : 'Review Draft',
                ];
            }
        }

        // 4. Missing Time Log Warnings (Tasks completed with 0 logged time)
        $timeData = $this->timeTracking->getUserMonthlyHours($user);
        if (! empty($timeData['missing_time_items'])) {
            foreach (array_slice($timeData['missing_time_items'], 0, 3) as $mItem) {
                $needsAttention[] = [
                    'item_id'         => $mItem['id'],
                    'key'             => $mItem['external_item_key'],
                    'title'           => $mItem['summary'],
                    'client_name'     => 'Time Tracking',
                    'reason'          => 'Work completed or in QA, but 0 hours logged',
                    'priority'        => 'Medium',
                    'target_due_date' => null,
                    'action_label'    => 'Log Hours',
                    'delivery_status' => $mItem['normalized_delivery_status'],
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

    /**
     * Compute multi-factor deterministic priority score for an executable task.
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

        $suggestedStep = match ($item->normalized_delivery_status) {
            'in_progress' => 'Continue active development',
            'rework'      => 'Fix QA feedback and re-submit',
            'review_qa'   => 'Verify staging build and unblock QA',
            default       => 'Begin implementation',
        };

        return [
            'score'          => min(100, max(1, $score)),
            'reasoning'      => implode(' • ', $reasons) ?: 'Scheduled task',
            'suggested_step' => $suggestedStep,
        ];
    }
}
