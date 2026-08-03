<?php

namespace App\Filament\Pages\Placeholders;

class RecommendationsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Intelligence';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Recommendations';
    protected static ?string $title = 'AI Recommendations';
    protected static ?string $slug = 'intelligence/recommendations';

    public function getModuleTitle(): string
    {
        return 'AI Recommendations';
    }

    public function getModuleDescription(): string
    {
        return 'AI-generated strategic, technical, and ecommerce recommendations derived from findings and continuous monitoring.';
    }

    public static function isInternalOnly(): bool
    {
        return false;
    }

    public static function getRequiredPermission(): ?string
    {
        return 'recommendations.view';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Generate Recommendations';
    }
}
