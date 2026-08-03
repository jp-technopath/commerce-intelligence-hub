<?php

namespace App\Filament\Pages\Placeholders;

class IntegrationSettingsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Integration Settings';
    protected static ?string $title = 'Platform Integration Settings';
    protected static ?string $slug = 'administration/integration-settings';

    public function getModuleTitle(): string
    {
        return 'Integration Settings';
    }

    public function getModuleDescription(): string
    {
        return 'Platform-wide integration configuration including OAuth app credentials, global API keys, webhooks, and third-party provider access.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Configure Providers';
    }
}
