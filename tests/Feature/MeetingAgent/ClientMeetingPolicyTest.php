<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\MeetingStatus;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Policies\ClientMeetingPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMeetingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ClientMeetingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->policy = new ClientMeetingPolicy();
    }

    // ── view ───────────────────────────────────────────────────────────

    public function test_owner_can_view_their_meeting(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
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
        $owner = User::factory()->create(['is_admin' => false]);

        $meeting = ClientMeeting::create([
            'title'             => 'Other User Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertTrue($this->policy->view($admin, $meeting));
    }

    public function test_client_user_without_permission_cannot_view_meeting(): void
    {
        $client = Client::create(['name' => 'Acme Inc', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $owner = User::factory()->create(['is_admin' => false]);

        $meeting = ClientMeeting::create([
            'title'             => 'Private Meeting',
            'client_id'         => $client->id,
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertFalse($this->policy->view($user, $meeting));
    }

    public function test_client_user_can_view_meeting_when_granted_view_permission(): void
    {
        $client = Client::create(['name' => 'Acme Inc', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        // Grant meetings.view to client role
        $viewPermission = Permission::where('name', 'meetings.view')->first();
        $clientRole->permissions()->attach($viewPermission->id);

        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $owner = User::factory()->create(['is_admin' => false]);

        $meeting = ClientMeeting::create([
            'title'             => 'Client Meeting',
            'client_id'         => $client->id,
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertTrue($this->policy->view($user, $meeting));
    }

    public function test_client_user_cannot_view_meeting_of_different_client(): void
    {
        $clientA = Client::create(['name' => 'Client A', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $clientB = Client::create(['name' => 'Client B', 'industry' => 'Tech', 'platform_type' => 'Magento', 'status' => 'active']);

        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        // Grant meetings.view to role
        $viewPermission = Permission::where('name', 'meetings.view')->first();
        $clientRole->permissions()->attach($viewPermission->id);

        // Assign user only to Client A
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $clientA->id,
            'is_active' => true,
        ]);

        $owner = User::factory()->create(['is_admin' => false]);

        // Meeting belongs to Client B
        $meeting = ClientMeeting::create([
            'title'             => 'Client B Meeting',
            'client_id'         => $clientB->id,
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertFalse($this->policy->view($user, $meeting));
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

    public function test_client_user_cannot_delete_meeting(): void
    {
        $client = Client::create(['name' => 'Acme Inc', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $meeting = ClientMeeting::create([
            'title'             => 'Meeting to Delete',
            'client_id'         => $client->id,
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $user->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertFalse($this->policy->delete($user, $meeting));
    }

    // ── create ─────────────────────────────────────────────────────────

    public function test_client_user_cannot_create_meeting(): void
    {
        $client = Client::create(['name' => 'Acme Inc', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->create($user));
    }

    public function test_admin_can_create_meeting(): void
    {
        $adminUser = User::factory()->create(['is_admin' => true]);

        $this->assertTrue($this->policy->create($adminUser));
    }

    // ── viewAny ────────────────────────────────────────────────────────

    public function test_client_user_without_permission_cannot_view_any(): void
    {
        $client = Client::create(['name' => 'Acme Inc', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_admin_can_view_any(): void
    {
        $adminUser = User::factory()->create(['is_admin' => true]);

        $this->assertTrue($this->policy->viewAny($adminUser));
    }

    public function test_user_with_view_permission_can_view_any(): void
    {
        $client = Client::create(['name' => 'Acme Inc', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        $viewPermission = Permission::where('name', 'meetings.view')->first();
        $clientRole->permissions()->attach($viewPermission->id);

        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $this->assertTrue($this->policy->viewAny($user));
    }

    // ── update ─────────────────────────────────────────────────────────

    public function test_owner_can_update_their_meeting(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $meeting = ClientMeeting::create([
            'title'             => 'Test Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $owner->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertTrue($this->policy->update($owner, $meeting));
    }

    public function test_client_user_cannot_update_meeting(): void
    {
        $client = Client::create(['name' => 'Acme Inc', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $meeting = ClientMeeting::create([
            'title'             => 'Meeting to Update',
            'client_id'         => $client->id,
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $user->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $this->assertFalse($this->policy->update($user, $meeting));
    }
}
