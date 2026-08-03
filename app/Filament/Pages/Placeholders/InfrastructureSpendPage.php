<?php

namespace App\Filament\Pages\Placeholders;

class InfrastructureSpendPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cloud';
    protected static ?string $navigationGroup = 'Financials';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Infrastructure Spend';
    protected static ?string $title = 'Cloud & Infrastructure Spend';
    protected static ?string $slug = 'financials/infrastructure-spend';

    public function getModuleTitle(): string
    {
        return 'Infrastructure Spend';
    }

    public function getModuleDescription(): string
    {
        return 'GCP VM runtime costs, compute instances, storage, and cloud infrastructure expenditure tracked by environment.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'View GCP Dashboard';
    }
}
