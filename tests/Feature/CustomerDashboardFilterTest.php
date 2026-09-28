<?php

namespace Tests\Feature;

use App\Filament\Pages\CustomerDashboard;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerDashboardFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_customer_filter_is_hidden_on_dashboard_when_user_has_access_to_single_customer(): void
    {
        $client = Client::create([
            'name'          => 'Cambro',
            'industry'      => 'Manufacturing',
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
            ->test(CustomerDashboard::class)
            ->assertDontSee('Customer Account Filter');
    }

    public function test_customer_filter_is_visible_on_dashboard_when_user_has_access_to_multiple_customers(): void
    {
        $client1 = Client::create([
            'name'          => 'Customer One',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);
        $client2 = Client::create([
            'name'          => 'Customer Two',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $clientUser = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client1->id,
            'is_active' => true,
        ]);
        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client2->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($clientUser)
            ->test(CustomerDashboard::class)
            ->assertSee('Customer Account Filter');
    }

    public function test_customer_filter_is_visible_on_dashboard_for_super_admin_with_multiple_customers(): void
    {
        Client::create([
            'name'          => 'Customer One',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);
        Client::create([
            'name'          => 'Customer Two',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(CustomerDashboard::class)
            ->assertSee('Customer Account Filter');
    }
}
