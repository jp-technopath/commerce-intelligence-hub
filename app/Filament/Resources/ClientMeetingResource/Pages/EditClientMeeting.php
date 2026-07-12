<?php

namespace App\Filament\Resources\ClientMeetingResource\Pages;

use App\Filament\Resources\ClientMeetingResource;
use App\Filament\Resources\ClientMeetingResource\Actions\GenerateFollowUpAction;
use App\Filament\Resources\ClientMeetingResource\Actions\GeneratePrepAction;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClientMeeting extends EditRecord
{
    protected static string $resource = ClientMeetingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePrepAction::make(),
            GenerateFollowUpAction::make(),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
