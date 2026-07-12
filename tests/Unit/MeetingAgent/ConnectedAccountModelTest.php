<?php

namespace Tests\Unit\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectedAccountModelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ── hasScope() ──────────────────────────────────────────────────────

    public function test_has_scope_returns_true_for_exact_full_url_scope_match(): void
    {
        $account = ConnectedAccount::create([
            'user_id'        => $this->user->id,
            'provider'       => 'google_workspace',
            'granted_scopes' => [
                'https://www.googleapis.com/auth/calendar.events.readonly',
                'https://www.googleapis.com/auth/gmail.compose',
            ],
            'status' => ConnectedAccountStatus::Active,
        ]);

        $this->assertTrue(
            $account->hasScope('https://www.googleapis.com/auth/gmail.compose')
        );
    }

    public function test_has_scope_returns_false_for_partial_scope_string(): void
    {
        $account = ConnectedAccount::create([
            'user_id'        => $this->user->id,
            'provider'       => 'google_workspace',
            'granted_scopes' => [
                'https://www.googleapis.com/auth/gmail.compose',
            ],
            'status' => ConnectedAccountStatus::Active,
        ]);

        // Partial string should NOT match — scopes must be compared as full URLs
        $this->assertFalse($account->hasScope('gmail.compose'));
    }

    public function test_has_scope_returns_false_when_scope_not_present(): void
    {
        $account = ConnectedAccount::create([
            'user_id'        => $this->user->id,
            'provider'       => 'google_workspace',
            'granted_scopes' => [
                'https://www.googleapis.com/auth/calendar.events.readonly',
            ],
            'status' => ConnectedAccountStatus::Active,
        ]);

        $this->assertFalse(
            $account->hasScope('https://www.googleapis.com/auth/gmail.compose')
        );
    }

    public function test_has_scope_returns_false_when_granted_scopes_is_null(): void
    {
        $account = ConnectedAccount::create([
            'user_id'        => $this->user->id,
            'provider'       => 'google_workspace',
            'granted_scopes' => null,
            'status'         => ConnectedAccountStatus::Active,
        ]);

        $this->assertFalse(
            $account->hasScope('https://www.googleapis.com/auth/gmail.compose')
        );
    }

    // ── needsReconnect() ────────────────────────────────────────────────

    public function test_needs_reconnect_returns_true_for_revoked_status(): void
    {
        $account = ConnectedAccount::create([
            'user_id'  => $this->user->id,
            'provider' => 'google_workspace',
            'status'   => ConnectedAccountStatus::Revoked,
        ]);

        $this->assertTrue($account->needsReconnect());
    }

    public function test_needs_reconnect_returns_true_for_expired_status(): void
    {
        $account = ConnectedAccount::create([
            'user_id'  => $this->user->id,
            'provider' => 'google_workspace',
            'status'   => ConnectedAccountStatus::Expired,
        ]);

        $this->assertTrue($account->needsReconnect());
    }

    public function test_needs_reconnect_returns_true_for_error_status(): void
    {
        $account = ConnectedAccount::create([
            'user_id'  => $this->user->id,
            'provider' => 'google_workspace',
            'status'   => ConnectedAccountStatus::Error,
        ]);

        $this->assertTrue($account->needsReconnect());
    }

    public function test_needs_reconnect_returns_false_for_active_status(): void
    {
        $account = ConnectedAccount::create([
            'user_id'  => $this->user->id,
            'provider' => 'google_workspace',
            'status'   => ConnectedAccountStatus::Active,
        ]);

        $this->assertFalse($account->needsReconnect());
    }

    // ── getCredential() / setCredential() ───────────────────────────────

    public function test_get_credential_returns_correct_value_from_encrypted_credentials(): void
    {
        $account = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'provider'         => 'google_workspace',
            'credentials_json' => [
                'refresh_token' => 'test-refresh-token-value',
                'token_type'    => 'Bearer',
            ],
            'status' => ConnectedAccountStatus::Active,
        ]);

        // Re-fetch to ensure decryption works
        $account->refresh();

        $this->assertSame('test-refresh-token-value', $account->getCredential('refresh_token'));
        $this->assertSame('Bearer', $account->getCredential('token_type'));
    }

    public function test_get_credential_returns_null_for_missing_key(): void
    {
        $account = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'provider'         => 'google_workspace',
            'credentials_json' => ['refresh_token' => 'abc'],
            'status'           => ConnectedAccountStatus::Active,
        ]);

        $this->assertNull($account->getCredential('nonexistent_key'));
    }

    public function test_get_credential_returns_null_when_credentials_json_is_null(): void
    {
        $account = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'provider'         => 'google_workspace',
            'credentials_json' => null,
            'status'           => ConnectedAccountStatus::Active,
        ]);

        $this->assertNull($account->getCredential('refresh_token'));
    }

    public function test_set_credential_saves_and_persists_to_database(): void
    {
        $account = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'provider'         => 'google_workspace',
            'credentials_json' => ['existing_key' => 'existing_value'],
            'status'           => ConnectedAccountStatus::Active,
        ]);

        $account->setCredential('new_key', 'new_value');

        // Re-fetch from database
        $fresh = ConnectedAccount::find($account->id);

        $this->assertSame('new_value', $fresh->getCredential('new_key'));
        $this->assertSame('existing_value', $fresh->getCredential('existing_key'));
    }

    public function test_set_credential_overwrites_existing_key(): void
    {
        $account = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'provider'         => 'google_workspace',
            'credentials_json' => ['refresh_token' => 'old-token'],
            'status'           => ConnectedAccountStatus::Active,
        ]);

        $account->setCredential('refresh_token', 'new-token');

        $fresh = ConnectedAccount::find($account->id);
        $this->assertSame('new-token', $fresh->getCredential('refresh_token'));
    }
}
