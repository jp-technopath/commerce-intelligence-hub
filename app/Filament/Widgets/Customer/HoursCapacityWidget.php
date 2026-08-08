<?php

namespace App\Filament\Widgets\Customer;

use App\Models\ForgeEstimateVersion;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class HoursCapacityWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        // 1. Hours Used This Month (Includes all logged hours for client: project tasks & Service Desk tickets)
        $startOfMonth = now()->startOfMonth();
        $secondsThisMonth = PmWorklog::where('client_id', $clientId)
            ->where('worklog_started_at', '>=', $startOfMonth)
            ->sum('time_spent_seconds');
        $hoursThisMonth = round($secondsThisMonth / 3600, 1);

        // 2. Total Approved Pipeline Hours
        $approvedItemIds = PmWorkItem::where('client_id', $clientId)
            ->where('normalized_delivery_status', '!=', 'completed')
            ->get()
            ->filter(fn ($item) => $item->estimate_approval_status === 'approved')
            ->pluck('id');

        $approvedPipelineSeconds = PmWorkItem::whereIn('id', $approvedItemIds)
            ->with('latestEstimateVersion')
            ->get()
            ->sum(fn ($item) => $item->latestEstimateVersion?->estimated_seconds ?? $item->estimated_seconds);
        $approvedPipelineHours = round($approvedPipelineSeconds / 3600, 1);

        // 3. Remaining Approved Work
        $spentOnApprovedSeconds = PmWorklog::whereIn('pm_work_item_id', $approvedItemIds)
            ->sum('time_spent_seconds');
        $remainingSeconds = max(0, $approvedPipelineSeconds - $spentOnApprovedSeconds);
        $remainingHours = round($remainingSeconds / 3600, 1);

        // 4. Pending Approval Hours
        $pendingItemIds = PmWorkItem::where('client_id', $clientId)
            ->get()
            ->filter(fn ($item) => in_array($item->estimate_approval_status, ['pending_approval', 'reapproval_required'], true))
            ->pluck('id');

        $pendingSeconds = PmWorkItem::whereIn('id', $pendingItemIds)
            ->with('latestEstimateVersion')
            ->get()
            ->sum(fn ($item) => $item->latestEstimateVersion?->estimated_seconds ?? $item->estimated_seconds);
        $pendingHours = round($pendingSeconds / 3600, 1);

        return [
            Stat::make('Hours Used This Month', $hoursThisMonth . ' hrs')
                ->description('Account consumption in ' . now()->format('F Y'))
                ->color('primary'),

            Stat::make('Approved Pipeline', $approvedPipelineHours . ' hrs')
                ->description('Total approved backlog & active work')
                ->color('success'),

            Stat::make('Remaining Approved Work', $remainingHours . ' hrs')
                ->description('Approved hours left to deliver')
                ->color('info'),

            Stat::make('Awaiting Approval', $pendingHours . ' hrs')
                ->description('Proposed hours pending customer sign-off')
                ->color('warning'),
        ];
    }
}
