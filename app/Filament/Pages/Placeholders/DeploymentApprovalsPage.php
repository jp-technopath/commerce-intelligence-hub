<?php

namespace App\Filament\Pages\Placeholders;

class DeploymentApprovalsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Deployments';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Deployment Approvals';
    protected static ?string $title = 'Deployment Approvals';
    protected static ?string $slug = 'deployments/approvals';

    public function getModuleTitle(): string
    {
        return 'Deployment Approvals';
    }

    public function getModuleDescription(): string
    {
        return 'Gatekeeper approval workflow for staging and production deployments across client environments.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Review Approvals';
    }
}
