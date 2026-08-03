<?php

namespace App\Filament\Pages\Placeholders;

class TestingPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-beaker';
    protected static ?string $navigationGroup = 'Delivery';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Testing';
    protected static ?string $title = 'Automated Testing & QA';
    protected static ?string $slug = 'delivery/testing';

    public function getModuleTitle(): string
    {
        return 'Automated Testing & QA';
    }

    public function getModuleDescription(): string
    {
        return 'Automated testing suite execution results, Playwright E2E checks, regression reports, and visual diffs.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Run Test Suite';
    }
}
