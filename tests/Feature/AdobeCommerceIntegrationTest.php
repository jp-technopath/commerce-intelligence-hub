<?php

namespace Tests\Feature;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Models\Client;
use App\Models\Integration;
use App\Models\User;
use App\Filament\Resources\IntegrationResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdobeCommerceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
        ]);

        $this->client = Client::create([
            'name' => 'Magento Test Client',
            'industry' => 'Retail',
            'platform_type' => 'Adobe Commerce',
            'status' => \App\Enums\ClientStatus::Active,
        ]);
    }

    public function test_serialize_and_deserialize_adobe_commerce_credentials(): void
    {
        $rawFormData = [
            'client_id' => $this->client->id,
            'integration_type' => 'adobe_commerce',
            'status' => IntegrationStatus::Active->value,
            'adobe_base_url' => 'https://magento-store.test',
            'adobe_admin_username' => 'magento_admin',
            'adobe_admin_password' => 'super_secret_pass123',
        ];

        // 1. Serialize credentials for creation
        $serializedData = IntegrationResource::serializeCredentials($rawFormData);

        $this->assertArrayNotHasKey('adobe_base_url', $serializedData);
        $this->assertArrayNotHasKey('adobe_admin_username', $serializedData);
        $this->assertArrayNotHasKey('adobe_admin_password', $serializedData);

        $this->assertArrayHasKey('credentials_json', $serializedData);
        $this->assertEquals('https://magento-store.test', $serializedData['credentials_json']['base_url']);
        $this->assertEquals('magento_admin', $serializedData['credentials_json']['admin_username']);
        $this->assertEquals('super_secret_pass123', $serializedData['credentials_json']['admin_password']);

        // 2. Save integration to DB
        $integration = Integration::create($serializedData);

        // Verify stored in DB
        $this->assertEquals('https://magento-store.test', $integration->getCredential('base_url'));
        $this->assertEquals('magento_admin', $integration->getCredential('admin_username'));
        $this->assertEquals('super_secret_pass123', $integration->getCredential('admin_password'));

        // 3. Deserialize for form filling on edit
        $filledFormData = IntegrationResource::deserializeCredentials(
            ['integration_type' => $integration->integration_type],
            $integration->credentials_json
        );

        $this->assertEquals('https://magento-store.test', $filledFormData['adobe_base_url']);
        $this->assertEquals('magento_admin', $filledFormData['adobe_admin_username']);
        $this->assertEquals('super_secret_pass123', $filledFormData['adobe_admin_password']);
    }
}
