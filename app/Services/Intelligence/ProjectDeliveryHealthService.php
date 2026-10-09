<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProjectDeliveryHealthService
{
    /**
     * Evaluate delivery health for a client across all its active PM work items.
     */
    public function evaluateClientHealth(Client $client): array
    {
        $workItems = PmWorkItem::where('client_id', $client->id)->get();

        return $this->evaluateWorkItemsHealth($workItems, $client->name);
    }

    /**
     * Evaluate delivery health for a specific project.
     */
    public function evaluateProjectHealth(Project $project): array
    {
        $workItems = PmWorkItem::where(function ($q) use ($project) {
            $q->where('pm_project_id', $project->id)
              ->orWhere('client_id', $project->client_id);
        })->get();

        return $this->evaluateWorkItemsHealth($workItems, $project->name);
    }

    /**
     * Deterministic, explainable evaluation of work items into delivery health states.
     */
    public function evaluateWorkItemsHealth(Collection $workItems, string $contextName = ''): array
    {
        // 1. Check for genuine Insufficient Data: zero total items
        if ($workItems->isEmpty()) {
            return [
                'status'      => 'Unknown',
                'badge_color' => 'gray',
                'summary'     => 'Insufficient Data',
                'reasons'     => ['No synchronized work items found for ' . ($contextName ?: 'this project')],
                'metrics'     => [
                    'total_active_tasks'  => 0,
                    'blocked_tasks_count' => 0,
                    'overdue_tasks_count' => 0,
                    'critical_open_count' => 0,
                    'rework_tasks_count'  => 0,
                ],
            ];
        }

        $now = now();
        $activeItems = $workItems->filter(function ($item) {
            if (in_array($item->normalized_delivery_status, ['completed', 'cancelled', 'backlog', 'on_hold', 'hold'], true)) {
                return false;
            }
            return ! $item->isBacklogOrOnHold();
        });

        // If all items are completed and none active
        if ($activeItems->isEmpty()) {
            return [
                'status'      => 'Healthy',
                'badge_color' => 'success',
                'summary'     => 'All Tasks Completed',
                'reasons'     => ['All work items are marked completed with zero active blockers.'],
                'metrics'     => [
                    'total_active_tasks'  => 0,
                    'blocked_tasks_count' => 0,
                    'overdue_tasks_count' => 0,
                    'critical_open_count' => 0,
                    'rework_tasks_count'  => 0,
                ],
            ];
        }

        // Evaluate concrete signals
        $blockedItems = $activeItems->filter(fn ($item) => (bool) $item->is_blocked || $item->normalized_delivery_status === 'blocked');
        $overdueItems = $activeItems->filter(fn ($item) => $item->target_due_date && Carbon::parse($item->target_due_date)->isPast());
        $criticalItems = $activeItems->filter(fn ($item) => in_array(strtolower($item->priority), ['critical', 'highest', 'high'], true));
        $reworkItems = $activeItems->filter(fn ($item) => $item->normalized_delivery_status === 'rework' || $item->hasLabel('rework') || $item->hasLabel('qa-fail'));

        // Check blocker duration
        $severelyBlocked = $blockedItems->filter(function ($item) use ($now) {
            $blockedSince = $item->external_updated_at ?: $item->updated_at;
            return $blockedSince && $blockedSince->diffInDays($now) >= 5;
        });

        $moderatelyBlocked = $blockedItems->filter(function ($item) use ($now) {
            $blockedSince = $item->external_updated_at ?: $item->updated_at;
            $days = $blockedSince ? $blockedSince->diffInDays($now) : 1;
            return $days >= 2 && $days < 5;
        });

        // Overdue critical items
        $overdueCritical = $overdueItems->filter(fn ($item) => in_array(strtolower($item->priority), ['critical', 'highest'], true));

        $reasons = [];
        $status = 'Healthy';
        $badgeColor = 'success';

        // ── Rule: At Risk ────────────────────────────────────────────────────
        if ($overdueCritical->isNotEmpty()) {
            $status = 'At Risk';
            $badgeColor = 'danger';
            $keys = $overdueCritical->pluck('external_item_key')->take(3)->implode(', ');
            $reasons[] = "{$overdueCritical->count()} critical task(s) overdue ({$keys})";
        }

        if ($severelyBlocked->isNotEmpty()) {
            $status = 'At Risk';
            $badgeColor = 'danger';
            $keys = $severelyBlocked->pluck('external_item_key')->take(3)->implode(', ');
            $reasons[] = "Task(s) blocked for 5+ business days ({$keys})";
        }

        if ($reworkItems->count() >= 2) {
            $status = 'At Risk';
            $badgeColor = 'danger';
            $reasons[] = "Repeated QA rework loop: {$reworkItems->count()} tasks failed verification";
        }

        // ── Rule: Watch (if not already At Risk) ──────────────────────────────
        if ($status !== 'At Risk') {
            if ($moderatelyBlocked->isNotEmpty()) {
                $status = 'Watch';
                $badgeColor = 'warning';
                $keys = $moderatelyBlocked->pluck('external_item_key')->take(3)->implode(', ');
                $reasons[] = "Task(s) blocked for 2-4 business days ({$keys})";
            }

            if ($overdueItems->isNotEmpty() && $overdueCritical->isEmpty()) {
                $status = 'Watch';
                $badgeColor = 'warning';
                $reasons[] = "{$overdueItems->count()} task(s) past target due date";
            }

            if ($reworkItems->count() === 1) {
                $status = 'Watch';
                $badgeColor = 'warning';
                $reasons[] = "1 task in QA rework";
            }
        }

        if (empty($reasons)) {
            $reasons[] = 'All ' . $activeItems->count() . ' active tasks progressing on schedule with no blockers.';
        }

        return [
            'status'      => $status,
            'badge_color' => $badgeColor,
            'summary'     => $status === 'Healthy' ? 'On Schedule' : implode('; ', $reasons),
            'reasons'     => $reasons,
            'metrics'     => [
                'total_active_tasks'  => $activeItems->count(),
                'blocked_tasks_count' => $blockedItems->count(),
                'overdue_tasks_count' => $overdueItems->count(),
                'critical_open_count' => $criticalItems->count(),
                'rework_tasks_count'  => $reworkItems->count(),
            ],
        ];
    }
}
