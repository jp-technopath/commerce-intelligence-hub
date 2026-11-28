<?php

namespace App\Filament\Resources\IntegrationResource\Pages;

use App\Enums\IntegrationType;
use App\Filament\Resources\IntegrationResource;
use App\Services\Connectors\AdobeCommerceConnector;
use App\Services\Connectors\ClarityConnector;
use App\Services\Connectors\GA4Connector;
use App\Services\Connectors\KlaviyoConnector;
use App\Services\Connectors\NewRelicConnector;
use App\Services\Connectors\ShopifyConnector;
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
                    [IntegrationType::GA4, IntegrationType::Clarity, IntegrationType::AdobeCommerce, IntegrationType::NewRelic, IntegrationType::Klaviyo, IntegrationType::Shopify]
                ))
                ->action(function (): void {
                    // Save form changes first so credentials on record in DB are updated with user inputs
                    $this->save(shouldRedirect: false);

                    $type = $this->record->integration_type;

                    $result = match ($type) {
                        IntegrationType::GA4           => (new GA4Connector($this->record))->testConnection(),
                        IntegrationType::Clarity       => (new ClarityConnector($this->record))->testConnection(),
                        IntegrationType::AdobeCommerce => (new AdobeCommerceConnector($this->record))->testConnection(),
                        IntegrationType::NewRelic      => (new NewRelicConnector($this->record))->testConnection(),
                        IntegrationType::Klaviyo       => (new KlaviyoConnector($this->record))->testConnection(),
                        IntegrationType::Shopify       => (new ShopifyConnector($this->record))->testConnection(),
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

    protected ?array $beforeMonitoringConfigSnapshot = null;

    /**
     * When saving, merge virtual credential fields into credentials_json and preserve monitoring_config keys.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = IntegrationResource::serializeCredentials($data, $this->record->credentials_json ?? []);

        $manager = new \App\Services\Configuration\ClientConfigurationManager();
        $existing = is_array($this->record->monitoring_config) ? $this->record->monitoring_config : [];
        $this->beforeMonitoringConfigSnapshot = $existing;
        $submitted = is_array($data['monitoring_config'] ?? null) ? $data['monitoring_config'] : [];

        $data['monitoring_config'] = $manager->mergeFormSubmission(
            $existing,
            $submitted,
            \App\Services\Configuration\ClientConfigurationManager::PROTECTED_INTEGRATION_KEYS
        );

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->beforeMonitoringConfigSnapshot === null) {
            return;
        }

        $manager = new \App\Services\Configuration\ClientConfigurationManager();
        $after = is_array($this->record->monitoring_config) ? $this->record->monitoring_config : [];
        $changed = $manager->computeChangedKeys($this->beforeMonitoringConfigSnapshot, $after);

        if (! empty($changed)) {
            $manager->recordAudit(
                clientId: $this->record->client_id,
                integrationId: $this->record->id,
                actorUserId: auth()->id(),
                configScope: 'integration.monitoring_config',
                version: (int) ($after['_meta']['version'] ?? 1),
                changedKeys: $changed,
                before: $this->beforeMonitoringConfigSnapshot,
                after: $after,
                source: 'filament_ui',
                ipAddress: request()?->ip()
            );
        }
    }

    /**
     * Pre-fill all credential fields from credentials_json when loading the form.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return IntegrationResource::deserializeCredentials($data, $this->record->credentials_json ?? []);
    }
}

