<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimeTrackingService
{
    /**
     * Get monthly logged hours for an engineer, broken down by client and project.
     */
    public function getUserMonthlyHours(User $user, ?Carbon $date = null): array
    {
        $date = $date ? $date->copy() : now();
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        // Query worklogs for this user within the month
        $worklogs = PmWorklog::with(['client', 'workItem.project'])
            ->where('user_id', $user->id)
            ->whereBetween('worklog_started_at', [$startOfMonth, $endOfMonth])
            ->get();

        $totalSeconds = $worklogs->sum('time_spent_seconds');
        $totalHours = round($totalSeconds / 3600, 1);

        // Breakdown by Customer
        $byCustomer = [];
        $groupedByClient = $worklogs->groupBy('client_id');

        foreach ($groupedByClient as $clientId => $clientLogs) {
            $client = $clientLogs->first()->client;
            $clientSeconds = $clientLogs->sum('time_spent_seconds');
            $clientHours = round($clientSeconds / 3600, 1);
            $clientSharePct = $totalHours > 0 ? round(($clientHours / $totalHours) * 100, 1) : 0;

            // Project breakdown inside this customer
            $byProject = [];
            $groupedByProject = $clientLogs->groupBy(fn ($wl) => $wl->workItem?->pm_project_id ?? 'unassigned');
            foreach ($groupedByProject as $projectId => $projLogs) {
                $projName = $projLogs->first()->workItem?->project?->name ?? 'Direct Tasks';
                $projHours = round($projLogs->sum('time_spent_seconds') / 3600, 1);
                $byProject[] = [
                    'project_id'   => $projectId === 'unassigned' ? null : $projectId,
                    'project_name' => $projName,
                    'hours'        => $projHours,
                ];
            }

            $byCustomer[] = [
                'client_id'   => $clientId,
                'client_name' => $client ? $client->name : 'Unknown Customer',
                'hours'       => $clientHours,
                'share_pct'   => $clientSharePct,
                'projects'    => $byProject,
            ];
        }

        // Sort descending by hours
        usort($byCustomer, fn ($a, $b) => $b['hours'] <=> $a['hours']);

        // Missing time entries: completed or in-review tasks assigned to user with 0 logged seconds
        $missingTimeItems = PmWorkItem::where('user_id', $user->id)
            ->whereIn('normalized_delivery_status', ['completed', 'review_qa', 'customer_review'])
            ->where('time_spent_seconds', '<=', 0)
            ->where('updated_at', '>=', $startOfMonth)
            ->get(['id', 'external_item_key', 'summary', 'normalized_delivery_status']);

        // Capacity calculation if configured on user
        $capacityValidated = false;
        $workingCapacityHours = null;
        if (isset($user->daily_capacity_hours) && $user->daily_capacity_hours > 0) {
            $capacityValidated = true;
            // Approximate working days in month up to current date or whole month
            $workingCapacityHours = round($user->daily_capacity_hours * 21.5, 1);
        }

        return [
            'period_label'            => $startOfMonth->format('M 1') . ' - ' . $endOfMonth->format('M j, Y'),
            'total_hours'             => $totalHours,
            'capacity_validated'      => $capacityValidated,
            'working_capacity_hours'  => $workingCapacityHours,
            'by_customer'             => $byCustomer,
            'missing_time_count'      => $missingTimeItems->count(),
            'missing_time_items'      => $missingTimeItems->toArray(),
        ];
    }

    /**
     * Get monthly customer hours rollups for managers across authorized clients.
     */
    public function getManagerMonthlyHours(array $clientIds = [], ?Carbon $date = null): array
    {
        $date = $date ? $date->copy() : now();
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $clientsQuery = Client::query();
        $filteredIds = array_diff($clientIds, ['*']);
        if (! empty($filteredIds)) {
            $clientsQuery->whereIn('id', $filteredIds);
        }
        $clients = $clientsQuery->get(['id', 'name', 'monthly_allocated_hours', 'status']);
        $effectiveClientIds = $clients->pluck('id')->toArray();

        $worklogsQuery = PmWorklog::with(['user', 'client'])
            ->whereBetween('worklog_started_at', [$startOfMonth, $endOfMonth]);
        if (! empty($effectiveClientIds)) {
            $worklogsQuery->whereIn('client_id', $effectiveClientIds);
        }
        $worklogs = $worklogsQuery->get();

        $byCustomer = [];
        $unattributedLogs = [];

        foreach ($clients as $client) {
            $clientLogs = $worklogs->where('client_id', $client->id);
            $totalSeconds = $clientLogs->sum('time_spent_seconds');
            $actualHours = round($totalSeconds / 3600, 1);
            $allocatedHours = $client->monthly_allocated_hours;

            $allocationPct = null;
            $allocationStatus = 'unbudgeted';

            if ($allocatedHours !== null && $allocatedHours > 0) {
                $allocationPct = round(($actualHours / $allocatedHours) * 100, 1);
                if ($actualHours >= $allocatedHours) {
                    $allocationStatus = 'over_allocation';
                } elseif ($allocationPct >= 85) {
                    $allocationStatus = 'approaching';
                } else {
                    $allocationStatus = 'on_budget';
                }
            }

            // Breakdown by contributor
            $contributors = [];
            $groupedByAuthor = $clientLogs->groupBy(fn ($wl) => $wl->user_id ?? 'unmapped_' . ($wl->external_author_id ?? $wl->author_name));
            foreach ($groupedByAuthor as $authorKey => $aLogs) {
                $user = $aLogs->first()->user;
                $authorName = $user ? $user->name : ($aLogs->first()->author_name ?: 'External/Unmapped Author');
                $isMapped = $user !== null;
                $cHours = round($aLogs->sum('time_spent_seconds') / 3600, 1);

                $contributors[] = [
                    'user_id'   => $user?->id,
                    'name'      => $authorName,
                    'is_mapped' => $isMapped,
                    'hours'     => $cHours,
                ];

                if (! $isMapped) {
                    foreach ($aLogs as $unmappedWl) {
                        $unattributedLogs[] = $unmappedWl;
                    }
                }
            }

            usort($contributors, fn ($a, $b) => $b['hours'] <=> $a['hours']);

            $byCustomer[] = [
                'client_id'         => $client->id,
                'client_name'       => $client->name,
                'hours'             => $actualHours,
                'actual_hours'      => $actualHours,
                'allocated_hours'   => $allocatedHours,
                'utilization_pct'   => $allocationPct,
                'allocation_pct'    => $allocationPct,
                'status'            => $allocationStatus,
                'allocation_status' => $allocationStatus,
                'contributors'      => $contributors,
            ];
        }

        usort($byCustomer, fn ($a, $b) => $b['actual_hours'] <=> $a['actual_hours']);

        $unattributedHours = round(collect($unattributedLogs)->sum('time_spent_seconds') / 3600, 1);

        return [
            'period_label'             => $startOfMonth->format('M 1') . ' - ' . $endOfMonth->format('M j, Y'),
            'total_portfolio_hours'    => round($worklogs->sum('time_spent_seconds') / 3600, 1),
            'by_customer'              => $byCustomer,
            'unattributed_hours'       => $unattributedHours,
            'total_unattributed_hours' => $unattributedHours,
            'unattributed_count'       => count($unattributedLogs),
            'unattributed_authors'     => collect($unattributedLogs)->pluck('author_name')->filter()->unique()->values()->all(),
        ];
    }
}
