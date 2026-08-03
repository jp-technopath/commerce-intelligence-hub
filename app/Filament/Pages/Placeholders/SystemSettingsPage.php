<?php

namespace App\Filament\Pages\Placeholders;

class SystemSettingsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'System Settings';
    protected static ?string $title = 'System Configuration & Logs';
    protected static ?string $slug = 'administration/system-settings';

    public function getModuleTitle(): string
    {
        return 'System Settings';
    }

    public function getModuleDescription(): string
    {
        return 'System-wide platform settings, application environment status, audit trail logs, and background job worker status.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Save System Settings';
    }
}
