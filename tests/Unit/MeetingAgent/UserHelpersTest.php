<?php

namespace Tests\Unit\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserHelpersTest extends TestCase
{
    use RefreshDatabase;

    // ── googleWorkspaceAccount() ────────────────────────────────────────

    public function test_google_workspace_account_returns_active_connected_account(): void
    {
        $user = User::factory()->create();

        $account = ConnectedAccount::create([
            'user_id'  => $user->id,
            'provider' => 'google_workspace',
            'status'   => ConnectedAccountStatus::Active,
        ]);

        $result = $user->googleWorkspaceAccount();

        $this->assertNotNull($result);
        $this->assertSame($account->id, $result->id);
    }

    public function test_google_workspace_account_returns_null_when_no_active_account(): void
    {
        $user = User::factory()->create();

        // Create a revoked account — should not be returned
        ConnectedAccount::create([
            'user_id'  => $user->id,
            'provider' => 'google_workspace',
            'status'   => ConnectedAccountStatus::Revoked,
        ]);

        $this->assertNull($user->googleWorkspaceAccount());
    }

    public function test_google_workspace_account_returns_null_when_no_account_exists(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->googleWorkspaceAccount());
    }

    public function test_google_workspace_account_ignores_other_providers(): void
    {
        $user = User::factory()->create();

        // A non-workspace provider should not match
        ConnectedAccount::create([
            'user_id'  => $user->id,
            'provider' => 'google_ga4',
            'status'   => ConnectedAccountStatus::Active,
        ]);

        $this->assertNull($user->googleWorkspaceAccount());
    }

    // ── hasGoogleWorkspace() ───────────────────────────────────────────

    public function test_has_google_workspace_returns_true_when_active_account_exists(): void
    {
        $user = User::factory()->create();

        ConnectedAccount::create([
            'user_id'  => $user->id,
            'provider' => 'google_workspace',
            'status'   => ConnectedAccountStatus::Active,
        ]);

        $this->assertTrue($user->hasGoogleWorkspace());
    }

    public function test_has_google_workspace_returns_false_when_no_active_account(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasGoogleWorkspace());
    }

    // ── hasMeetingAgentScope() ─────────────────────────────────────────

    public function test_has_meeting_agent_scope_checks_scope_on_connected_account(): void
    {
        $user = User::factory()->create();
        $gmailScope = config('meeting_agent.google.scopes.gmail_compose');

        ConnectedAccount::create([
            'user_id'        => $user->id,
            'provider'       => 'google_workspace',
            'granted_scopes' => [$gmailScope],
            'status'         => ConnectedAccountStatus::Active,
        ]);

        $this->assertTrue($user->hasMeetingAgentScope($gmailScope));
    }

    public function test_has_meeting_agent_scope_returns_false_when_scope_not_granted(): void
    {
        $user = User::factory()->create();

        ConnectedAccount::create([
            'user_id'        => $user->id,
            'provider'       => 'google_workspace',
            'granted_scopes' => [
                'https://www.googleapis.com/auth/calendar.events.readonly',
            ],
            'status' => ConnectedAccountStatus::Active,
        ]);

        $driveScope = config('meeting_agent.google.scopes.drive_file');
        $this->assertFalse($user->hasMeetingAgentScope($driveScope));
    }

    public function test_has_meeting_agent_scope_returns_false_when_no_account(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(
            $user->hasMeetingAgentScope('https://www.googleapis.com/auth/gmail.compose')
        );
    }

    public function test_has_meeting_agent_scope_uses_full_url_not_partial(): void
    {
        $user = User::factory()->create();

        ConnectedAccount::create([
            'user_id'        => $user->id,
            'provider'       => 'google_workspace',
            'granted_scopes' => [
                'https://www.googleapis.com/auth/gmail.compose',
            ],
            'status' => ConnectedAccountStatus::Active,
        ]);

        // Partial scope string should NOT match
        $this->assertFalse($user->hasMeetingAgentScope('gmail.compose'));

        // Full URL should match
        $this->assertTrue(
            $user->hasMeetingAgentScope('https://www.googleapis.com/auth/gmail.compose')
        );
    }
}
