<?php

namespace App\Filament\Resources\IntegrationResource\Pages;

use App\Filament\Resources\IntegrationResource;
use App\Filament\Widgets\RecentSyncActivityWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIntegrations extends ListRecords
{
    protected static string $resource = IntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    protected function getFooterWidgets(): array
    {
        return [
            RecentSyncActivityWidget::class,
        ];
    }
}
