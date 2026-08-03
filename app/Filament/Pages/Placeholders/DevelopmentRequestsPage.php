<?php

namespace App\Filament\Pages\Placeholders;

class DevelopmentRequestsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Delivery';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Development Requests';
    protected static ?string $title = 'Development Requests';
    protected static ?string $slug = 'delivery/requests';

    public function getModuleTitle(): string
    {
        return 'Development Requests';
    }

    public function getModuleDescription(): string
    {
        return 'Work originating from customer requests, Jira issues, findings, alerts, meeting action items, and internal tasks.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'New Request';
    }
}
