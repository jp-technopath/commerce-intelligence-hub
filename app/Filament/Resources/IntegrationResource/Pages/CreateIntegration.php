<?php

namespace App\Filament\Resources\IntegrationResource\Pages;

use App\Filament\Resources\IntegrationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIntegration extends CreateRecord
{
    protected static string $resource = IntegrationResource::class;

    /**
     * Strip virtual credential fields before saving.
     * The property_id is stored inside credentials_json.
     * On create, we don't have OAuth tokens yet — user must authorize after saving.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return IntegrationResource::serializeCredentials($data, $data['credentials_json'] ?? []);
    }

    protected function getRedirectUrl(): string
    {
        // After create, go straight to edit page so user can authorize
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
