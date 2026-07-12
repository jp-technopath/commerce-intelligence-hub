<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\MeetingStatus;
use App\Models\ClientMeeting;
use App\Models\User;
use App\Policies\ClientMeetingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMeetingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ClientMeetingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ClientMeetingPolicy();
    }

    // ── view ───────────────────────────────────────────────────────────

    public function test_owner_can_view_their_meeting(): void
    {
        $owner = User::factory()->create();
        $meeting = ClientMeeting::create([
            'title'             => 'Test Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertTrue($this->policy->view($owner, $meeting));
    }

    public function test_admin_can_view_any_meeting(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();

        $meeting = ClientMeeting::create([
            'title'             => 'Other User Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertTrue($this->policy->view($admin, $meeting));
    }

    public function test_non_owner_cannot_view_another_users_meeting(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $owner = User::factory()->create();

        $meeting = ClientMeeting::create([
            'title'             => 'Private Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertFalse($this->policy->view($user, $meeting));
    }

    public function test_any_user_can_view_unassigned_meeting(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $meeting = ClientMeeting::create([
            'title'             => 'Unassigned Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => null,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertTrue($this->policy->view($user, $meeting));
    }

    // ── delete ─────────────────────────────────────────────────────────

    public function test_admin_can_delete_meeting(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $meeting = ClientMeeting::create([
            'title'            => 'Meeting to Delete',
            'meeting_start_at' => now()->addDay(),
            'status'           => MeetingStatus::Detected,
        ]);

        $this->assertTrue($this->policy->delete($admin, $meeting));
    }

    public function test_non_admin_cannot_delete_meeting(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $meeting = ClientMeeting::create([
            'title'             => 'Meeting to Delete',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $user->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertFalse($this->policy->delete($user, $meeting));
    }

    // ── create ─────────────────────────────────────────────────────────

    public function test_any_authenticated_user_can_create(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $adminUser = User::factory()->create(['is_admin' => true]);

        $this->assertTrue($this->policy->create($regularUser));
        $this->assertTrue($this->policy->create($adminUser));
    }

    // ── viewAny ────────────────────────────────────────────────────────

    public function test_any_authenticated_user_can_view_any(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->assertTrue($this->policy->viewAny($user));
    }

    // ── update ─────────────────────────────────────────────────────────

    public function test_owner_can_update_their_meeting(): void
    {
        $owner = User::factory()->create();
        $meeting = ClientMeeting::create([
            'title'             => 'Test Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertTrue($this->policy->update($owner, $meeting));
    }

    public function test_non_owner_non_admin_cannot_update(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $owner = User::factory()->create();

        $meeting = ClientMeeting::create([
            'title'             => 'Other Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertFalse($this->policy->update($user, $meeting));
    }
}
