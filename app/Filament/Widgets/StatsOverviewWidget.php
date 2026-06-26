<?php

namespace App\Filament\Widgets;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\SyncStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\SyncLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    protected function getStats(): array
    {
        $totalClients = Client::count();

        $clientsNeedingAttention = Client::whereHas('findings', function ($q) {
            $q->whereIn('status', [FindingStatus::New->value, FindingStatus::Investigating->value]);
        })->count();

        $openFindings = Finding::whereIn('status', [
            FindingStatus::New->value,
            FindingStatus::Investigating->value,
            FindingStatus::Accepted->value,
        ])->count();

        $highSeverityFindings = Finding::whereIn('status', [
            FindingStatus::New->value,
            FindingStatus::Investigating->value,
        ])->whereIn('severity', [
            FindingSeverity::High->value,
            FindingSeverity::Critical->value,
        ])->count();

        $recentSyncs = SyncLog::where('created_at', '>=', now()->subHours(24))->count();
        $failedSyncs = SyncLog::where('created_at', '>=', now()->subHours(24))
            ->where('status', SyncStatus::Failed->value)
            ->count();

        return [
            Stat::make('Total Clients', $totalClients)
                ->icon('heroicon-o-building-office-2')
                ->color('primary'),

            Stat::make('Clients Needing Attention', $clientsNeedingAttention)
                ->icon('heroicon-o-exclamation-triangle')
                ->color($clientsNeedingAttention > 0 ? 'warning' : 'success'),

            Stat::make('Open Findings', $openFindings)
                ->icon('heroicon-o-bell-alert')
                ->color($openFindings > 0 ? 'danger' : 'success'),

            Stat::make('High / Critical Findings', $highSeverityFindings)
                ->icon('heroicon-o-fire')
                ->color($highSeverityFindings > 0 ? 'danger' : 'success'),

            Stat::make('Syncs (Last 24h)', $recentSyncs)
                ->description($failedSyncs > 0 ? "{$failedSyncs} failed" : 'All successful')
                ->icon('heroicon-o-arrow-path')
                ->color($failedSyncs > 0 ? 'warning' : 'success'),
        ];
    }
}
