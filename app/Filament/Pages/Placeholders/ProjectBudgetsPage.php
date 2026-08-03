<?php

namespace App\Filament\Pages\Placeholders;

class ProjectBudgetsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?string $navigationGroup = 'Financials';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Project Budgets';
    protected static ?string $title = 'Project Budgets';
    protected static ?string $slug = 'financials/budgets';

    public function getModuleTitle(): string
    {
        return 'Project Budgets';
    }

    public function getModuleDescription(): string
    {
        return 'Client project budget buckets, allowances, threshold alerts, and real-time consumption rates.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Set Budget Bucket';
    }
}
