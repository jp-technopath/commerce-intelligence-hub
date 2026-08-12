<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RecentFindingsWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class AgencyDashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $navigationLabel = 'Agency Dashboard';
    protected static ?string $title = 'Agency Dashboard';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?int $navigationSort = 1;

    public function mount(): void
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if ($user && $user->isClientOnly()) {
            redirect()->to(CustomerDashboard::getUrl());
        }
    }

    public function getWidgets(): array
    {
        return [
            StatsOverviewWidget::class,
            RecentFindingsWidget::class,
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->isClientOnly()) {
            return false;
        }

        return true;
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
