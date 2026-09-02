<?php

namespace App\Filament\Resources\DevelopmentRequestResource\Pages;

use App\Filament\Resources\DevelopmentRequestResource;
use App\Models\DevelopmentRequest;
use App\Services\DevelopmentRequestIntakeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDevelopmentRequest extends CreateRecord
{
    protected static string $resource = DevelopmentRequestResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var DevelopmentRequest $request */
        $request = app(DevelopmentRequestIntakeService::class)->createDraft(auth()->user(), $data);

        return $request;
    }

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()->label('Save Draft');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Draft saved. Review the routing, then start the agent when ready.';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
