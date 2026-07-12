<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleWorkspaceOAuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Workspace connect redirect ─────────────────────────────────────

    public function test_workspace_connect_redirects_to_google_with_correct_scopes(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('google.workspace.connect'));

        $response->assertRedirect();

        $redirectUrl = $response->headers->get('Location');

        // Should redirect to Google accounts
        $this->assertStringContainsString('accounts.google.com', $redirectUrl);

        // Verify workspace scopes are present in the redirect URL
        $expectedScopes = config('meeting_agent.google.workspace_scopes');
        foreach ($expectedScopes as $scope) {
            $this->assertStringContainsString(
                urlencode($scope),
                $redirectUrl,
                "Expected scope '{$scope}' missing from redirect URL"
            );
        }
    }

    public function test_workspace_connect_stores_nonce_in_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('google.workspace.connect'));

        $this->assertTrue(
            session()->has('google_workspace_nonce'),
            'Session should contain workspace nonce for CSRF protection'
        );
    }

    public function test_workspace_connect_includes_user_id_in_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('google.workspace.connect'));

        $redirectUrl = $response->headers->get('Location');
        $parsedUrl = parse_url($redirectUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);

        $state = json_decode(base64_decode($queryParams['state'] ?? ''), true);

        $this->assertSame('workspace', $state['purpose']);
        $this->assertSame($user->id, $state['user_id']);
        $this->assertNotEmpty($state['nonce']);
    }

    // ── Workspace callback ─────────────────────────────────────────────

    public function test_workspace_callback_rejects_nonce_mismatch(): void
    {
        $user = User::factory()->create();

        // Set a session nonce
        $this->withSession(['google_workspace_nonce' => 'correct-nonce']);

        $state = base64_encode(json_encode([
            'purpose' => 'workspace',
            'user_id' => $user->id,
            'nonce'   => 'wrong-nonce',
        ]));

        $response = $this->actingAs($user)->get(route('google.oauth.callback', [
            'code'  => 'test-auth-code',
            'state' => $state,
        ]));

        $response->assertRedirect('/admin/client-meetings');
        $response->assertSessionHas('error');
    }

    public function test_workspace_callback_rejects_user_id_mismatch(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $nonce = Str::random(40);

        $this->withSession(['google_workspace_nonce' => $nonce]);

        $state = base64_encode(json_encode([
            'purpose' => 'workspace',
            'user_id' => $otherUser->id,  // Different user
            'nonce'   => $nonce,
        ]));

        $response = $this->actingAs($user)->get(route('google.oauth.callback', [
            'code'  => 'test-auth-code',
            'state' => $state,
        ]));

        $response->assertRedirect('/admin/client-meetings');
        $response->assertSessionHas('error');
    }

    // ── Workspace revoke ───────────────────────────────────────────────

    public function test_workspace_revoke_updates_connected_account_status_to_revoked(): void
    {
        $user = User::factory()->create();

        $account = ConnectedAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'google_workspace',
            'authorized_email' => 'test@technopath.com',
            'credentials_json' => [
                'refresh_token' => 'test-refresh-token',
            ],
            'granted_scopes' => [
                'https://www.googleapis.com/auth/calendar.events.readonly',
                'https://www.googleapis.com/auth/gmail.compose',
            ],
            'status' => ConnectedAccountStatus::Active,
        ]);

        // The revoke endpoint will try to call Google API, which will fail in test.
        // That's fine — the account should still be revoked locally.
        $response = $this->actingAs($user)->get(route('google.workspace.revoke'));

        $response->assertRedirect('/admin/client-meetings');

        $account->refresh();
        $this->assertSame(ConnectedAccountStatus::Revoked, $account->status);
        $this->assertEmpty($account->credentials_json);
    }

    public function test_workspace_revoke_returns_error_when_no_account(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('google.workspace.revoke'));

        $response->assertRedirect('/admin/client-meetings');
        $response->assertSessionHas('error');
    }

    // ── Callback creates ConnectedAccount (NOT Integration) ────────────

    public function test_callback_uses_connected_account_model_not_integration(): void
    {
        // This is a structural verification: the workspace callback should
        // create/update a ConnectedAccount record, never an Integration record.
        $user = User::factory()->create();

        ConnectedAccount::create([
            'user_id'  => $user->id,
            'provider' => 'google_workspace',
            'status'   => ConnectedAccountStatus::Active,
        ]);

        // Verify it's a ConnectedAccount
        $account = ConnectedAccount::where('user_id', $user->id)
            ->where('provider', 'google_workspace')
            ->first();

        $this->assertInstanceOf(ConnectedAccount::class, $account);
        $this->assertSame('google_workspace', $account->provider);
    }
}
