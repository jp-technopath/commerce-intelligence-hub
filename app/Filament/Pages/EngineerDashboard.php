<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Engineer\EngineerActiveTasksWidget;
use App\Filament\Widgets\Engineer\EngineerMonthlyHoursWidget;
use App\Filament\Widgets\Engineer\EngineerWorkPlanWidget;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class EngineerDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-command-line';
    protected static ?string $navigationLabel = 'Engineer Dashboard';
    protected static ?string $title = 'Engineer Operational Dashboard';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.engineer-dashboard';

    public function mount(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if ($user && $user->isClientOnly()) {
            redirect()->to(CustomerDashboard::getUrl());
        }
    }

    public function getHeaderWidgets(): array
    {
        return [
            EngineerWorkPlanWidget::class,
            EngineerMonthlyHoursWidget::class,
            EngineerActiveTasksWidget::class,
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return ! $user->isClientOnly();
    }

    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return ! $user->isClientOnly();
    }
}
