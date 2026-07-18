<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class JiraOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meeting_agent.jira.client_id'     => 'test-client-id',
            'meeting_agent.jira.client_secret' => 'test-client-secret',
            'meeting_agent.jira.redirect_uri'  => 'http://localhost/jira/oauth/callback',
        ]);
    }

    // ── OAuth redirect ─────────────────────────────────────────────────

    public function test_jira_oauth_redirects_to_atlassian_with_correct_scopes(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('jira.oauth.connect'));

        $response->assertRedirect();

        $redirectUrl = $response->headers->get('Location');

        $this->assertStringContainsString('auth.atlassian.com/authorize', $redirectUrl);
        $this->assertStringContainsString('audience=api.atlassian.com', $redirectUrl);
        $this->assertStringContainsString('client_id=test-client-id', $redirectUrl);
        $this->assertStringContainsString(urlencode('read:jira-work write:jira-work read:jira-user offline_access'), $redirectUrl);
    }

    public function test_jira_oauth_stores_nonce_in_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('jira.oauth.connect'));

        $this->assertTrue(
            session()->has('jira_oauth_nonce'),
            'Session should contain Jira OAuth nonce for CSRF validation'
        );
    }

    public function test_jira_oauth_includes_user_id_in_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('jira.oauth.connect'));

        $redirectUrl = $response->headers->get('Location');
        $parsedUrl = parse_url($redirectUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);

        $state = json_decode(base64_decode($queryParams['state'] ?? ''), true);

        $this->assertSame($user->id, $state['user_id']);
        $this->assertNotEmpty($state['nonce']);
    }

    // ── OAuth Callback ─────────────────────────────────────────────────

    public function test_jira_oauth_callback_rejects_nonce_mismatch(): void
    {
        $user = User::factory()->create();

        $this->withSession(['jira_oauth_nonce' => 'correct-nonce']);

        $state = base64_encode(json_encode([
            'user_id' => $user->id,
            'nonce'   => 'wrong-nonce',
        ]));

        $response = $this->actingAs($user)->get(route('jira.oauth.callback', [
            'code'  => 'auth-code-xyz',
            'state' => $state,
        ]));

        $response->assertRedirect('/admin/my-profile');
        $response->assertSessionHas('error', 'Authorization failed: invalid session state. Please try again.');
    }

    public function test_jira_oauth_callback_rejects_user_id_mismatch(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $nonce = Str::random(40);

        $this->withSession(['jira_oauth_nonce' => $nonce]);

        $state = base64_encode(json_encode([
            'user_id' => $otherUser->id,
            'nonce'   => $nonce,
        ]));

        $response = $this->actingAs($user)->get(route('jira.oauth.callback', [
            'code'  => 'auth-code-xyz',
            'state' => $state,
        ]));

        $response->assertRedirect('/admin/my-profile');
        $response->assertSessionHas('error', 'Authorization failed: user session mismatch. Please try again.');
    }

    public function test_jira_oauth_callback_exchanges_code_and_saves_account(): void
    {
        $user = User::factory()->create();
        $nonce = Str::random(40);

        $this->withSession(['jira_oauth_nonce' => $nonce]);

        $state = base64_encode(json_encode([
            'user_id' => $user->id,
            'nonce'   => $nonce,
        ]));

        // Fake Atlassian Token Exchange & Accessible Resources API calls
        Http::fake([
            'https://auth.atlassian.com/oauth/token' => Http::response([
                'access_token'  => 'mock-access-token',
                'refresh_token' => 'mock-refresh-token',
                'expires_in'    => 3600,
            ], 200),
            'https://api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                [
                    'id'     => 'cloud-id-12345',
                    'url'    => 'https://technopath-test.atlassian.net',
                    'name'   => 'Technopath Testing Site',
                    'scopes' => ['read:jira-work', 'read:jira-user'],
                ]
            ], 200),
        ]);

        $response = $this->actingAs($user)->get(route('jira.oauth.callback', [
            'code'  => 'auth-code-xyz',
            'state' => $state,
        ]));

        $response->assertRedirect('/admin/my-profile');
        $response->assertSessionHas('success', 'Jira connected successfully to: Technopath Testing Site');

        // Assert record exists in database
        $this->assertDatabaseHas('connected_accounts', [
            'user_id'          => $user->id,
            'provider'         => 'jira',
            'authorized_email' => 'https://technopath-test.atlassian.net',
            'status'           => ConnectedAccountStatus::Active->value,
        ]);

        $account = ConnectedAccount::where('user_id', $user->id)->where('provider', 'jira')->first();
        $this->assertSame('mock-access-token', $account->getCredential('access_token'));
        $this->assertSame('mock-refresh-token', $account->getCredential('refresh_token'));
        $this->assertSame('cloud-id-12345', $account->getCredential('cloud_id'));
    }

    // ── OAuth Revocation ───────────────────────────────────────────────

    public function test_jira_oauth_revoke_removes_connected_account(): void
    {
        $user = User::factory()->create();

        ConnectedAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'jira',
            'authorized_email' => 'https://site.atlassian.net',
            'credentials_json' => ['access_token' => 'token'],
            'status'           => ConnectedAccountStatus::Active,
        ]);

        $response = $this->actingAs($user)->get(route('jira.oauth.revoke'));

        $response->assertRedirect('/admin/my-profile');
        $response->assertSessionHas('success', 'Jira account disconnected successfully.');

        $this->assertDatabaseMissing('connected_accounts', [
            'user_id'  => $user->id,
            'provider' => 'jira',
        ]);
    }

    // ── On-Demand Token Refresh ────────────────────────────────────────

    public function test_token_refresh_rotates_expired_tokens(): void
    {
        $user = User::factory()->create();

        $account = ConnectedAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'jira',
            'authorized_email' => 'https://site.atlassian.net',
            'credentials_json' => [
                'access_token'  => 'old-access-token',
                'refresh_token' => 'old-refresh-token',
            ],
            'token_expires_at' => now()->subMinutes(10), // Expired
            'status'           => ConnectedAccountStatus::Active,
        ]);

        Http::fake([
            'https://auth.atlassian.com/oauth/token' => Http::response([
                'access_token'  => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in'    => 3600,
            ], 200),
        ]);

        $accessToken = $account->refreshJiraTokenIfNeeded();

        $this->assertSame('new-access-token', $accessToken);
        $this->assertDatabaseHas('connected_accounts', [
            'user_id'  => $user->id,
            'provider' => 'jira',
            'status'   => ConnectedAccountStatus::Active->value,
        ]);

        $account->refresh();
        $this->assertSame('new-access-token', $account->getCredential('access_token'));
        $this->assertSame('new-refresh-token', $account->getCredential('refresh_token'));
        $this->assertTrue($account->token_expires_at->isFuture());
    }
}
