<?php

namespace App\Filament\Resources\PmConnectionResource\Pages;

use App\Filament\Resources\PmConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManagePmConnections extends ManageRecords
{
    protected static string $resource = PmConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
