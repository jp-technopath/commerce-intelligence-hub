<?php

namespace App\Services\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use Carbon\Carbon;

class DeliveryFindingEvaluator
{
    /**
     * Scan all active clients and generate operational findings.
     */
    public function evaluateAll(): int
    {
        $total = 0;
        foreach (Client::withJiraProjectKey()->where('status', 'active')->get() as $client) {
            $total += $this->evaluateClientFindings($client);
        }
        return $total;
    }

    /**
     * Scan client work items, worklogs, and meetings to generate or update operational findings.
     */
    public function evaluateClientFindings(Client $client): int
    {
        if (empty($client->jira_project_key)) {
            return 0;
        }

        $count = 0;
        $now = now();

        // 1. Evaluate Blocked Tasks
        $blockedItems = PmWorkItem::where('client_id', $client->id)
            ->forCustomerSpacesWithJiraCode($client->id)
            ->excludeBacklogAndOnHold()
            ->where('normalized_delivery_status', '!=', 'completed')
            ->where(function ($q) {
                $q->where('is_blocked', true)
                  ->orWhere('normalized_delivery_status', 'blocked');
            })
            ->get();

        foreach ($blockedItems as $item) {
            $fingerprint = hash('sha256', "blocked_item_{$client->id}_{$item->id}");
            $blockedSince = $item->external_updated_at ?: $item->updated_at;
            $daysBlocked = $blockedSince ? max(1, (int) $blockedSince->diffInDays($now)) : 1;

            $severity = $daysBlocked >= 5 ? FindingSeverity::High : FindingSeverity::Medium;

            Finding::updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'client_id'                  => $client->id,
                    'responsible_user_id'        => $item->user_id,
                    'project_id'                 => $item->pm_project_id,
                    'finding_type'               => 'task_blocked',
                    'source_type'                => 'pm_work_item',
                    'source_id'                  => (string) $item->id,
                    'finding_category'           => FindingCategory::WorkflowDelay,
                    'title'                      => "Task blocked: [{$item->external_item_key}] {$item->summary}",
                    'description'                => "Task has been blocked for {$daysBlocked} day(s). Reason: " . ($item->blocked_reason ?: 'No blocker reason specified'),
                    'severity'                   => $severity,
                    'status'                     => FindingStatus::New,
                    'visibility_classification'  => 'internal',
                    'is_customer_visible'        => false,
                    'evidence_json'              => [
                        'item_key'     => $item->external_item_key,
                        'days_blocked' => $daysBlocked,
                        'reason'       => $item->blocked_reason,
                    ],
                    'detected_at'                => now(),
                ]
            );
            $count++;
        }

        // 2. Evaluate Overdue Tasks
        $overdueItems = PmWorkItem::where('client_id', $client->id)
            ->excludeBacklogAndOnHold()
            ->where('normalized_delivery_status', '!=', 'completed')
            ->whereNotNull('target_due_date')
            ->where('target_due_date', '<', $now->toDateString())
            ->get();

        foreach ($overdueItems as $item) {
            $fingerprint = hash('sha256', "overdue_item_{$client->id}_{$item->id}");
            $isCritical = in_array(strtolower($item->priority), ['critical', 'highest', 'high'], true);
            $severity = $isCritical ? FindingSeverity::High : FindingSeverity::Medium;

            Finding::updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'client_id'                  => $client->id,
                    'responsible_user_id'        => $item->user_id,
                    'project_id'                 => $item->pm_project_id,
                    'finding_type'               => 'task_overdue',
                    'source_type'                => 'pm_work_item',
                    'source_id'                  => (string) $item->id,
                    'finding_category'           => FindingCategory::WorkflowDelay,
                    'title'                      => "Overdue task: [{$item->external_item_key}] {$item->summary}",
                    'description'                => "Due date ({$item->target_due_date->format('M j, Y')}) passed. Priority: {$item->priority}.",
                    'severity'                   => $severity,
                    'status'                     => FindingStatus::New,
                    'visibility_classification'  => 'internal',
                    'is_customer_visible'        => false,
                    'evidence_json'              => [
                        'item_key' => $item->external_item_key,
                        'due_date' => $item->target_due_date->toDateString(),
                        'priority' => $item->priority,
                    ],
                    'detected_at'                => now(),
                ]
            );
            $count++;
        }

        // 3. Evaluate QA Rework Tasks
        $reworkItems = PmWorkItem::where('client_id', $client->id)
            ->excludeBacklogAndOnHold()
            ->where('normalized_delivery_status', '!=', 'completed')
            ->where(function ($q) {
                $q->where('normalized_delivery_status', 'rework')
                  ->orWhereJsonContains('labels_json', 'rework')
                  ->orWhereJsonContains('labels_json', 'qa-fail');
            })
            ->get();

        foreach ($reworkItems as $item) {
            $fingerprint = hash('sha256', "rework_item_{$client->id}_{$item->id}");

            Finding::updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'client_id'                  => $client->id,
                    'responsible_user_id'        => $item->user_id,
                    'project_id'                 => $item->pm_project_id,
                    'finding_type'               => 'qa_rework',
                    'source_type'                => 'pm_work_item',
                    'source_id'                  => (string) $item->id,
                    'finding_category'           => FindingCategory::WorkflowDelay,
                    'title'                      => "QA Rework required: [{$item->external_item_key}] {$item->summary}",
                    'description'                => 'Task failed QA verification and returned to rework state.',
                    'severity'                   => FindingSeverity::Medium,
                    'status'                     => FindingStatus::New,
                    'visibility_classification'  => 'internal',
                    'is_customer_visible'        => false,
                    'evidence_json'              => ['item_key' => $item->external_item_key],
                    'detected_at'                => now(),
                ]
            );
            $count++;
        }

        // 4. Evaluate Monthly Allocation Overages
        if ($client->monthly_allocated_hours && $client->monthly_allocated_hours > 0) {
            $startOfMonth = $now->copy()->startOfMonth();
            $endOfMonth = $now->copy()->endOfMonth();
            $loggedSeconds = PmWorklog::where('client_id', $client->id)
                ->whereBetween('worklog_started_at', [$startOfMonth, $endOfMonth])
                ->sum('time_spent_seconds');
            $loggedHours = round($loggedSeconds / 3600, 1);

            if ($loggedHours >= $client->monthly_allocated_hours) {
                $fingerprint = hash('sha256', "allocation_exceeded_{$client->id}_{$startOfMonth->format('Y_m')}");
                $overage = round($loggedHours - $client->monthly_allocated_hours, 1);

                Finding::updateOrCreate(
                    ['fingerprint' => $fingerprint],
                    [
                        'client_id'                  => $client->id,
                        'responsible_user_id'        => null,
                        'finding_type'               => 'hours_allocation_exceeded',
                        'source_type'                => 'client',
                        'source_id'                  => (string) $client->id,
                        'finding_category'           => FindingCategory::TimeTracking,
                        'title'                      => "Monthly allocation exceeded for {$client->name}",
                        'description'                => "{$loggedHours}h logged against {$client->monthly_allocated_hours}h contract allocation (+{$overage}h overage).",
                        'severity'                   => FindingSeverity::High,
                        'status'                     => FindingStatus::New,
                        'visibility_classification'  => 'internal',
                        'is_customer_visible'        => false,
                        'evidence_json'              => [
                            'logged_hours'    => $loggedHours,
                            'allocated_hours' => $client->monthly_allocated_hours,
                            'overage'         => $overage,
                        ],
                        'detected_at'                => now(),
                    ]
                );
                $count++;
            }
        }

        return $count;
    }
}
