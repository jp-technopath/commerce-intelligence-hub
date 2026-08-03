<?php

namespace App\Filament\Pages\Placeholders;

class ReleaseCalendarPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Deployments';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Release Calendar';
    protected static ?string $title = 'Release Calendar';
    protected static ?string $slug = 'deployments/release-calendar';

    public function getModuleTitle(): string
    {
        return 'Release Calendar';
    }

    public function getModuleDescription(): string
    {
        return 'Calendar schedule of past, upcoming, and planned releases across client environments and maintenance windows.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Schedule Release';
    }
}
