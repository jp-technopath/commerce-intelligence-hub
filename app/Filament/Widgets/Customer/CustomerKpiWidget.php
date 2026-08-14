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

        // 3. Work in Pipeline (All active tasks in delivery pipeline)
        $pipelineQuery = PmWorkItem::where('client_id', $clientId)
            ->whereNotIn('normalized_delivery_status', ['completed', 'backlog', 'canceled'])
            ->whereRaw('UPPER(external_status) NOT LIKE ?', ['%BACKLOG%'])
            ->whereHas('project', function ($q) {
                $q->where('external_project_key', '!=', 'SUP');
            })
            ->where('external_item_key', 'NOT LIKE', 'SUP-%');

        $pipelineTaskCount = $pipelineQuery->count();
        $pipelineSeconds = (int) $pipelineQuery->sum('estimated_seconds');
        $pipelineHours = round($pipelineSeconds / 3600, 1);

        // 4. Work in Progress (Tasks where Jira status = In Progress)
        $wipQuery = PmWorkItem::where('client_id', $clientId)
            ->where(function ($q) {
                $q->where('normalized_delivery_status', 'in_progress')
                  ->orWhereRaw('LOWER(external_status) LIKE ?', ['%in progress%']);
            })
            ->whereHas('project', function ($q) {
                $q->where('external_project_key', '!=', 'SUP');
            })
            ->where('external_item_key', 'NOT LIKE', 'SUP-%');

        $wipTaskCount = $wipQuery->count();
        $wipSeconds = (int) $wipQuery->sum('estimated_seconds');
        $wipHours = round($wipSeconds / 3600, 1);

        return [
            Stat::make('Needs Your Attention', $attentionCount)
                ->description($attentionCount > 0 ? 'Action required by customer' : 'All caught up!')
                ->descriptionIcon($attentionCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($attentionCount > 0 ? 'warning' : 'success'),

            Stat::make('Hours Used This Month', $hoursThisMonth . ' hrs')
                ->description('Monthly account usage')
                ->descriptionIcon('heroicon-m-clock')
                ->color('primary'),

            Stat::make('Work in Pipeline', "{$pipelineTaskCount} Tasks")
                ->description('All active tasks in delivery pipeline')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('success')
                ->url('/admin/work-in-pipeline?scope=all_pipeline'),

            Stat::make('Work in Progress', "{$wipTaskCount} Tasks")
                ->description('Tasks with Jira status In Progress')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info')
                ->url('/admin/work-in-pipeline?scope=in_progress'),
        ];
    }
}
