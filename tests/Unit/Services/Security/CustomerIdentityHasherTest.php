<?php

namespace Tests\Unit\Services\Security;

use App\Models\Client;
use App\Services\Security\CustomerIdentityHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerIdentityHasherTest extends TestCase
{
    use RefreshDatabase;

    private CustomerIdentityHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new CustomerIdentityHasher();
    }

    public function test_it_hashes_customer_identity_with_client_scoped_key(): void
    {
        $client = Client::create([
            'name' => 'Test Client',
            'status' => 'active',
        ]);

        $hash1 = $this->hasher->hashCustomerIdentity($client, customerId: '12345');
        $hash2 = $this->hasher->hashCustomerIdentity($client, customerId: '12345');
        $hashEmail = $this->hasher->hashCustomerIdentity($client, email: 'Buyer@Example.com');

        $this->assertNotNull($hash1);
        $this->assertSame($hash1, $hash2);
        $this->assertNotNull($hashEmail);
        $this->assertNotSame($hash1, $hashEmail);
    }

    public function test_it_normalizes_emails_conservatively(): void
    {
        $norm = $this->hasher->normalizeEmail('  John.Doe+tag@Example.COM  ');
        $this->assertSame('John.Doe+tag@example.com', $norm);
    }

    public function test_it_fails_closed_when_key_is_undecryptable(): void
    {
        $client = Client::create([
            'name' => 'Corrupted Key Client',
            'status' => 'active',
            'monitoring_config' => [
                'identity_hmac_key' => 'corrupted_or_invalid_payload',
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unavailable or cannot be decrypted');

        $this->hasher->getClientHmacKey($client);
    }

    public function test_it_provisions_and_retrieves_encrypted_key_cleanly(): void
    {
        $client = Client::create([
            'name' => 'Provisioned Client',
            'status' => 'active',
        ]);
        $secret = bin2hex(random_bytes(16));

        $this->hasher->setClientHmacKey($client, $secret);

        $client->refresh();
        $this->assertNotSame($secret, $client->monitoring_config['identity_hmac_key']);
        $this->assertSame($secret, $this->hasher->getClientHmacKey($client));
    }
}
