<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Manager\ManagerActionCenterWidget;
use App\Filament\Widgets\Manager\ManagerCustomerHoursWidget;
use App\Filament\Widgets\Manager\ManagerMeetingIntelligenceWidget;
use App\Filament\Widgets\Manager\ManagerPortfolioDeliveryHealthWidget;
use App\Filament\Widgets\Manager\ManagerWorkflowHealthWidget;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManagerDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationLabel = 'Manager Dashboard';
    protected static ?string $title = 'Manager Portfolio & Delivery Health';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.manager-dashboard';

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
            ManagerPortfolioDeliveryHealthWidget::class,
            ManagerCustomerHoursWidget::class,
            ManagerWorkflowHealthWidget::class,
            ManagerMeetingIntelligenceWidget::class,
            ManagerActionCenterWidget::class,
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
