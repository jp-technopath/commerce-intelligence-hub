<?php

namespace App\Filament\Pages\Placeholders;

class ApprovalsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-check-badge';
    protected static ?string $navigationGroup = 'Delivery';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Approvals';
    protected static ?string $title = 'Delivery Approvals Inbox';
    protected static ?string $slug = 'delivery/approvals';

    public function getModuleTitle(): string
    {
        return 'Approvals Inbox';
    }

    public function getModuleDescription(): string
    {
        return 'Central approval inbox for requirements, agent execution plans, PR reviews, staging deployment, and customer sign-off.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Review Pending Approvals';
    }
}
