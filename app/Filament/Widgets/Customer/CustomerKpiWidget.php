<?php

namespace App\Filament\Widgets\Customer;

use App\Models\CustomerAttentionItem;
use App\Models\ForgeEstimateVersion;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class CustomerKpiWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected $listeners = ['client-changed' => '$refresh'];

    protected function getStats(): array
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        // 1. Needs Your Attention Count
        $attentionCount = CustomerAttentionItem::where('client_id', $clientId)
            ->unresolved()
            ->count();

        // 2. Hours Used This Month (Includes all logged hours for client: project tasks & Service Desk tickets)
        $startOfMonth = now()->startOfMonth();
        $secondsThisMonth = PmWorklog::where('client_id', $clientId)
            ->where('worklog_started_at', '>=', $startOfMonth)
            ->sum('time_spent_seconds');
        $hoursThisMonth = round($secondsThisMonth / 3600, 1);

        // 3. Approved Pipeline Hours
        $approvedItemIds = PmWorkItem::where('client_id', $clientId)
            ->where('normalized_delivery_status', '!=', 'completed')
            ->get()
            ->filter(fn ($item) => $item->estimate_approval_status === 'approved')
            ->pluck('id');

        $approvedPipelineSeconds = ForgeEstimateVersion::whereIn('pm_work_item_id', $approvedItemIds)
            ->sum('estimated_seconds');
        $approvedPipelineHours = round($approvedPipelineSeconds / 3600, 1);

        // 4. Work in Progress Count
        $wipCount = PmWorkItem::where('client_id', $clientId)
            ->whereIn('normalized_delivery_status', ['ready', 'in_progress', 'review_qa', 'customer_review'])
            ->whereRaw('UPPER(external_status) NOT LIKE ?', ['%BACKLOG%'])
            ->whereHas('project', function ($q) {
                $q->where('external_project_key', '!=', 'SUP');
            })
            ->where('external_item_key', 'NOT LIKE', 'SUP-%')
            ->count();

        return [
            Stat::make('Needs Your Attention', $attentionCount)
                ->description($attentionCount > 0 ? 'Action required by customer' : 'All caught up!')
                ->descriptionIcon($attentionCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($attentionCount > 0 ? 'warning' : 'success'),

            Stat::make('Hours Used This Month', $hoursThisMonth . ' hrs')
                ->description('Monthly account usage')
                ->descriptionIcon('heroicon-m-clock')
                ->color('primary'),

            Stat::make('Approved Pipeline', $approvedPipelineHours . ' hrs')
                ->description('Approved work ready / in delivery')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Work in Progress', $wipCount)
                ->description('Active tasks being delivered (Click to view)')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info')
                ->url('/admin/work-in-progress'),
        ];
    }
}
