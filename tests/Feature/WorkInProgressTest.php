<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkInProgress;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkInProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_see_raw_jira_status_column(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(WorkInProgress::class)
            ->assertTableColumnVisible('external_status')
            ->assertTableColumnVisible('normalized_delivery_status')
            ->assertTableColumnVisible('external_item_key')
            ->assertTableColumnVisible('summary');
    }

    public function test_internal_staff_can_see_raw_jira_status_column(): void
    {
        $engineer = User::factory()->create(['is_admin' => false]);
        $engineerRole = Role::where('name', Role::ROLE_ENGINEER)->first();

        UserRoleAssignment::create([
            'user_id'   => $engineer->id,
            'role_id'   => $engineerRole->id,
            'client_id' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($engineer)
            ->test(WorkInProgress::class)
            ->assertTableColumnVisible('external_status')
            ->assertTableColumnVisible('normalized_delivery_status');
    }

    public function test_client_role_user_cannot_see_raw_jira_status_column(): void
    {
        $client = Client::create([
            'name'          => 'Acme Inc',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $clientUser = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($clientUser)
            ->test(WorkInProgress::class)
            ->assertTableColumnHidden('external_status')
            ->assertTableColumnVisible('normalized_delivery_status')
            ->assertTableColumnVisible('external_item_key')
            ->assertTableColumnVisible('summary');
    }
}
