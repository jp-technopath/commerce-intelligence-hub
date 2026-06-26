<?php

namespace App\Filament\Resources\IntegrationResource\Pages;

use App\Enums\IntegrationType;
use App\Filament\Resources\IntegrationResource;
use App\Services\Connectors\AdobeCommerceConnector;
use App\Services\Connectors\ClarityConnector;
use App\Services\Connectors\GA4Connector;
use App\Services\Connectors\KlaviyoConnector;
use App\Services\Connectors\NewRelicConnector;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditIntegration extends EditRecord
{
    protected static string $resource = IntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('test_connection')
                ->label('Test Connection')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->visible(fn () => in_array(
                    $this->record->integration_type,
                    [IntegrationType::GA4, IntegrationType::Clarity, IntegrationType::AdobeCommerce, IntegrationType::NewRelic, IntegrationType::Klaviyo]
                ))
                ->action(function (): void {
                    $type = $this->record->integration_type;

                    $result = match ($type) {
                        IntegrationType::GA4           => (new GA4Connector($this->record))->testConnection(),
                        IntegrationType::Clarity       => (new ClarityConnector($this->record))->testConnection(),
                        IntegrationType::AdobeCommerce => (new AdobeCommerceConnector($this->record))->testConnection(),
                        IntegrationType::NewRelic      => (new NewRelicConnector($this->record))->testConnection(),
                        IntegrationType::Klaviyo       => (new KlaviyoConnector($this->record))->testConnection(),
                        default                        => ['success' => false, 'message' => 'Test not available for this integration type.'],
                    };

                    if ($result['success']) {
                        Notification::make()->title('Connection successful')->body($result['message'])->success()->send();
                    } else {
                        Notification::make()->title('Connection failed')->body($result['message'])->danger()->send();
                    }
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /**
     * When saving, merge virtual credential fields into credentials_json.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $integrationType = $data['integration_type'] ?? $this->record->integration_type?->value;
        $existing        = $this->record->credentials_json ?? [];

        if ($integrationType === 'ga4') {
            $propertyId = $data['ga4_property_id'] ?? null;
            if ($propertyId) {
                $data['credentials_json'] = array_merge($existing, ['property_id' => $propertyId]);
            }
        }

        if ($integrationType === 'clarity') {
            $data['credentials_json'] = array_merge($existing, array_filter([
                'bearer_token' => $data['clarity_bearer_token'] ?? null,
                'project_id'   => $data['clarity_project_id'] ?? null,
            ]));
        }

        if ($integrationType === 'shopify') {
            $data['credentials_json'] = array_merge($existing, array_filter([
                'access_token' => $data['shopify_access_token'] ?? null,
                'shop_domain'  => $data['shopify_shop_domain'] ?? null,
            ]));
        }

        if ($integrationType === 'adobe_commerce') {
            $data['credentials_json'] = array_merge($existing, array_filter([
                'base_url'       => $data['adobe_base_url'] ?? null,
                'admin_username' => $data['adobe_admin_username'] ?? null,
                'admin_password' => $data['adobe_admin_password'] ?? null,
            ]));
        }

        if ($integrationType === 'new_relic') {
            $data['credentials_json'] = array_merge($existing, array_filter([
                'api_key'        => $data['newrelic_api_key'] ?? null,
                'application_id' => $data['newrelic_application_id'] ?? null,
            ]));
        }

        if ($integrationType === 'klaviyo') {
            $data['credentials_json'] = array_merge($existing, array_filter([
                'api_key' => $data['klaviyo_api_key'] ?? null,
            ]));
        }

        // Remove virtual fields that don't map to DB columns
        unset(
            $data['ga4_property_id'],
            $data['ga4_service_account_json'],
            $data['shopify_access_token'],
            $data['shopify_shop_domain'],
            $data['adobe_bearer_token'],
            $data['adobe_base_url'],
            $data['adobe_admin_username'],
            $data['adobe_admin_password'],
            $data['clarity_bearer_token'],
            $data['clarity_project_id'],
            $data['newrelic_api_key'],
            $data['newrelic_application_id'],
            $data['klaviyo_api_key'],
        );

        return $data;
    }

    /**
     * Pre-fill all credential fields from credentials_json when loading the form.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $credentials = $this->record->credentials_json ?? [];
        $type        = $this->record->integration_type?->value;

        if ($type === 'ga4') {
            $data['ga4_property_id'] = $credentials['property_id'] ?? null;
        }

        if ($type === 'clarity') {
            $data['clarity_bearer_token'] = $credentials['bearer_token'] ?? null;
            $data['clarity_project_id']   = $credentials['project_id'] ?? null;
        }

        if ($type === 'shopify') {
            $data['shopify_access_token'] = $credentials['access_token'] ?? null;
            $data['shopify_shop_domain']  = $credentials['shop_domain'] ?? null;
        }

        if ($type === 'adobe_commerce') {
            $data['adobe_base_url']        = $credentials['base_url'] ?? null;
            $data['adobe_admin_username']  = $credentials['admin_username'] ?? null;
            $data['adobe_admin_password']  = $credentials['admin_password'] ?? null;
        }

        if ($type === 'new_relic') {
            $data['newrelic_api_key']        = $credentials['api_key'] ?? null;
            $data['newrelic_application_id'] = $credentials['application_id'] ?? null;
        }

        if ($type === 'klaviyo') {
            $data['klaviyo_api_key'] = $credentials['api_key'] ?? null;
        }

        return $data;
    }
}

