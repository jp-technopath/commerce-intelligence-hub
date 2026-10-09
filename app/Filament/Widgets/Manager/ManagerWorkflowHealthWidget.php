<?php

namespace App\Filament\Widgets\Manager;

use App\Services\Intelligence\ResponseTimeService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class ManagerWorkflowHealthWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $clientIds = $user ? $user->getAssignedClientIds() : [];

        /** @var ResponseTimeService $service */
        $service = app(ResponseTimeService::class);
        $kpis = $service->getPortfolioWorkflowMetrics($clientIds);

        return [
            Stat::make('Avg Dev Time', ($kpis['avg_development_days'] ?? 0.0) . ' days')
                ->description('Cycle time in active development')
                ->descriptionIcon('heroicon-m-code-bracket')
                ->color('primary'),

            Stat::make('Avg QA Time', ($kpis['avg_qa_verification_days'] ?? 0.0) . ' days')
                ->description('Cycle time in QA verification')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('info'),

            Stat::make('Blocked Tasks', (string) ($kpis['total_blocked_tasks'] ?? 0))
                ->description('Tasks waiting on external blockers')
                ->descriptionIcon('heroicon-m-no-symbol')
                ->color(($kpis['total_blocked_tasks'] ?? 0) > 0 ? 'danger' : 'success'),

            Stat::make('Overdue Tasks', (string) ($kpis['total_overdue_tasks'] ?? 0))
                ->description('Active tasks past target due date')
                ->descriptionIcon('heroicon-m-clock')
                ->color(($kpis['total_overdue_tasks'] ?? 0) > 0 ? 'danger' : 'success'),

            Stat::make('Internal Response SLA', ($kpis['internal_response_sla_hours'] ?? 0.0) . ' hrs')
                ->description('Avg business-hour internal response')
                ->descriptionIcon('heroicon-m-bolt')
                ->color(($kpis['internal_response_sla_hours'] ?? 0.0) <= 4.0 ? 'success' : 'warning'),

            Stat::make('Customer Delay', ($kpis['customer_response_delay_days'] ?? 0.0) . ' days')
                ->description('Avg wait time for customer response')
                ->descriptionIcon('heroicon-m-user-group')
                ->color(($kpis['customer_response_delay_days'] ?? 0.0) <= 2.0 ? 'success' : 'warning'),
        ];
    }
}
