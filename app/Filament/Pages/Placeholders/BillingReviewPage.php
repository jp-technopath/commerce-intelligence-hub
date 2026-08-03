<?php

namespace App\Filament\Pages\Placeholders;

class BillingReviewPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';
    protected static ?string $navigationGroup = 'Financials';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Billing Review';
    protected static ?string $title = 'Delivery Billing Review';
    protected static ?string $slug = 'financials/billing-review';

    public function getModuleTitle(): string
    {
        return 'Billing Review';
    }

    public function getModuleDescription(): string
    {
        return 'Combined delivery billing review consolidating AI model usage, cloud compute, developer hours, and client billing rules.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Generate Invoice Draft';
    }
}
