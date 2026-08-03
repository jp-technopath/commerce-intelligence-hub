<?php

namespace App\Filament\Pages\Placeholders;

class AiSpendPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Financials';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'AI Spend';
    protected static ?string $title = 'AI Spend & Model Usage';
    protected static ?string $slug = 'financials/ai-spend';

    public function getModuleTitle(): string
    {
        return 'AI Spend & Usage';
    }

    public function getModuleDescription(): string
    {
        return 'LiteLLM model usage breakdown, token consumption metrics, and cost tracking broken down by client and project.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Export Spend Report';
    }
}
