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
        $integrationType = $data['integration_type'] ?? null;

        if ($integrationType === 'ga4') {
            $propertyId = $data['ga4_property_id'] ?? null;
            $existing   = is_array($data['credentials_json'] ?? null) ? $data['credentials_json'] : [];

            $data['credentials_json'] = array_merge($existing, array_filter([
                'property_id' => $propertyId,
                'auth_method' => 'oauth2_user',
            ]));
        }

        if ($integrationType === 'clarity') {
            $existing = is_array($data['credentials_json'] ?? null) ? $data['credentials_json'] : [];
            $data['credentials_json'] = array_merge($existing, array_filter([
                'bearer_token' => $data['clarity_bearer_token'] ?? null,
                'project_id'   => $data['clarity_project_id'] ?? null,
            ]));
        }

        if ($integrationType === 'shopify') {
            $existing = is_array($data['credentials_json'] ?? null) ? $data['credentials_json'] : [];
            $data['credentials_json'] = array_merge($existing, array_filter([
                'access_token' => $data['shopify_access_token'] ?? null,
                'shop_domain'  => $data['shopify_shop_domain'] ?? null,
            ]));
        }

        if ($integrationType === 'adobe_commerce') {
            $existing = is_array($data['credentials_json'] ?? null) ? $data['credentials_json'] : [];
            $data['credentials_json'] = array_merge($existing, array_filter([
                'bearer_token' => $data['adobe_bearer_token'] ?? null,
                'base_url'     => $data['adobe_base_url'] ?? null,
            ]));
        }

        if ($integrationType === 'new_relic') {
            $existing = is_array($data['credentials_json'] ?? null) ? $data['credentials_json'] : [];
            $data['credentials_json'] = array_merge($existing, array_filter([
                'api_key'        => $data['newrelic_api_key'] ?? null,
                'application_id' => $data['newrelic_application_id'] ?? null,
            ]));
        }

        if ($integrationType === 'klaviyo') {
            $existing = is_array($data['credentials_json'] ?? null) ? $data['credentials_json'] : [];
            $data['credentials_json'] = array_merge($existing, array_filter([
                'api_key' => $data['klaviyo_api_key'] ?? null,
            ]));
        }

        // Remove virtual fields
        unset(
            $data['ga4_property_id'],
            $data['ga4_service_account_json'],
            $data['shopify_access_token'],
            $data['shopify_shop_domain'],
            $data['adobe_bearer_token'],
            $data['adobe_base_url'],
            $data['clarity_bearer_token'],
            $data['clarity_project_id'],
            $data['newrelic_api_key'],
            $data['newrelic_application_id'],
            $data['klaviyo_api_key'],
        );

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // After create, go straight to edit page so user can authorize
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
