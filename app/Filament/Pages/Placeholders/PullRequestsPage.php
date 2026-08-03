<?php

namespace App\Filament\Pages\Placeholders;

class PullRequestsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-code-bracket-square';
    protected static ?string $navigationGroup = 'Delivery';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Pull Requests';
    protected static ?string $title = 'Pull Requests';
    protected static ?string $slug = 'delivery/pull-requests';

    public function getModuleTitle(): string
    {
        return 'Pull Requests';
    }

    public function getModuleDescription(): string
    {
        return 'Aggregated GitHub and Bitbucket pull requests across all client projects, tracking code review and build status.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Sync Pull Requests';
    }
}
