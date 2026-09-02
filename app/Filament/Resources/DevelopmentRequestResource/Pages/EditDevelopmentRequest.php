<?php

namespace App\Filament\Resources\DevelopmentRequestResource\Pages;

use App\Filament\Resources\DevelopmentRequestResource;
use App\Enums\DevelopmentRequestStatus;
use App\Models\DevelopmentRequest;
use App\Services\DevelopmentRequestIntakeService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDevelopmentRequest extends EditRecord
{
    protected static string $resource = DevelopmentRequestResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless(in_array($this->record->state, [
            DevelopmentRequestStatus::Draft,
            DevelopmentRequestStatus::ChangesRequested,
        ], true), 403);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var DevelopmentRequest $record */
        return app(DevelopmentRequestIntakeService::class)->updateDraft($record, auth()->user(), $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft and routing snapshot updated.';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
