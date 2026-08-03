<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class AgencyDashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $navigationLabel = 'Agency Dashboard';
    protected static ?string $title = 'Agency Dashboard';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        // Hide Agency Dashboard from client portal users
        if ($user->isClientOnly()) {
            return false;
        }

        return $user->hasPermission('dashboard.view');
    }
}
