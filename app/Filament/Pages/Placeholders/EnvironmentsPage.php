<?php

namespace App\Filament\Pages\Placeholders;

class EnvironmentsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Environments';
    protected static ?string $title = 'Client Environments';
    protected static ?string $slug = 'clients/environments';

    public function getModuleTitle(): string
    {
        return 'Client Environments';
    }

    public function getModuleDescription(): string
    {
        return 'Configure hosting environments (Production, Staging, Dev) and domain endpoints for each client project.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Add Environment';
    }
}
