<?php

namespace App\Filament\Resources\DevelopmentRequestResource\Pages;

use App\Filament\Resources\DevelopmentRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDevelopmentRequests extends ListRecords
{
    protected static string $resource = DevelopmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New Request'),
        ];
    }
}
