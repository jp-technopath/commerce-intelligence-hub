<?php

namespace Tests\Unit\Connectors;

use App\Models\Integration;
use App\Services\Connectors\GA4Connector;
use Google\Service\AnalyticsData;
use Tests\TestCase;

class GA4ConnectorTest extends TestCase
{
    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyPath = base_path('storage/credentials/ga4-service-account.json');
    }

    public function test_has_credentials_detects_service_account_from_config(): void
    {
        config(['google.service_account_json' => $this->keyPath]);

        $integration = new Integration(['credentials_json' => []]);
        $connector = new GA4Connector($integration);

        $this->assertTrue($connector->hasServiceAccountConfigured());
        $this->assertTrue($connector->hasCredentials());
    }

    public function test_has_credentials_detects_service_account_from_integration_credentials(): void
    {
        config(['google.service_account_json' => null]);

        $integration = new Integration([
            'credentials_json' => [
                'service_account_json' => $this->keyPath,
            ],
        ]);
        $connector = new GA4Connector($integration);

        $this->assertTrue($connector->hasServiceAccountConfigured());
        $this->assertTrue($connector->hasCredentials());
    }

    public function test_has_credentials_detects_service_account_from_raw_json_string(): void
    {
        config(['google.service_account_json' => null]);

        $json = json_encode([
            'type' => 'service_account',
            'client_email' => 'custom-sync@project.iam.gserviceaccount.com',
        ]);

        $integration = new Integration([
            'credentials_json' => [
                'service_account_json' => $json,
            ],
        ]);
        $connector = new GA4Connector($integration);

        $this->assertTrue($connector->hasServiceAccountConfigured());
        $this->assertTrue($connector->hasCredentials());
    }

    public function test_has_credentials_detects_oauth_refresh_token_as_fallback(): void
    {
        config(['google.service_account_json' => null]);

        $integration = new Integration([
            'credentials_json' => [
                'refresh_token' => 'mock_refresh_token_123',
            ],
        ]);
        $connector = new GA4Connector($integration);

        $this->assertFalse($connector->hasServiceAccountConfigured());
        $this->assertTrue($connector->hasCredentials());
    }

    public function test_has_credentials_returns_false_when_no_credentials_configured(): void
    {
        config(['google.service_account_json' => null]);

        $integration = new Integration(['credentials_json' => []]);
        $connector = new GA4Connector($integration);

        $this->assertFalse($connector->hasServiceAccountConfigured());
        $this->assertFalse($connector->hasCredentials());
    }

    public function test_build_service_initializes_with_service_account_file(): void
    {
        if (! file_exists($this->keyPath)) {
            $this->markTestSkipped('Service account key file does not exist on disk.');
        }

        config(['google.service_account_json' => $this->keyPath]);

        $integration = new Integration(['credentials_json' => ['property_id' => '123456789']]);
        $connector = new GA4Connector($integration);

        $service = $connector->buildService();

        $this->assertInstanceOf(AnalyticsData::class, $service);
    }

    public function test_build_service_initializes_with_inline_json_string(): void
    {
        if (! file_exists($this->keyPath)) {
            $this->markTestSkipped('Service account key file does not exist on disk.');
        }

        config(['google.service_account_json' => null]);
        $jsonContent = file_get_contents($this->keyPath);

        $integration = new Integration([
            'credentials_json' => [
                'property_id'          => '123456789',
                'service_account_json' => $jsonContent,
            ],
        ]);
        $connector = new GA4Connector($integration);

        $service = $connector->buildService();

        $this->assertInstanceOf(AnalyticsData::class, $service);
    }

    public function test_build_service_throws_exception_when_no_credentials_found(): void
    {
        config(['google.service_account_json' => null]);

        $integration = new Integration(['credentials_json' => ['property_id' => '123456789']]);
        $connector = new GA4Connector($integration);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No valid GA4 credentials found');

        $connector->buildService();
    }

    public function test_get_service_account_email_extracts_client_email(): void
    {
        if (! file_exists($this->keyPath)) {
            $this->markTestSkipped('Service account key file does not exist on disk.');
        }

        config(['google.service_account_json' => $this->keyPath]);

        $email = GA4Connector::getServiceAccountEmail();

        $this->assertNotNull($email);
        $this->assertStringContainsString('@technopath.iam.gserviceaccount.com', $email);
    }
}
