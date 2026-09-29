<?php

namespace Tests\Feature\Authorization;

use App\Filament\Pages\BusinessDashboard;
use App\Filament\Resources\ClientResource\RelationManagers\FindingsRelationManager;
use App\Filament\Resources\FindingResource;
use App\Filament\Widgets\RecentFindingsWidget;
use App\Models\Client;
use App\Models\Finding;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Policies\FindingPolicy;
use App\Services\VisibilityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FindingsPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected Client $client;
    protected Role $clientRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create([
            'name'     => 'Super Admin',
            'email'    => 'admin@technopath.co',
            'is_admin' => true,
        ]);

        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->first();
        UserRoleAssignment::create([
            'user_id'   => $this->superAdmin->id,
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name'          => 'Acme Corp',
            'industry'      => 'Ecommerce',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);
        $this->clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();
    }

    public function test_user_without_findings_permission_is_denied_by_policy(): void
    {
        // Detach findings.view from the role to simulate unchecking findings permission
        $this->clientRole->permissions()->detach(
            Permission::whereIn('name', ['findings.view', 'findings.view_any'])->pluck('id')
        );

        $user = User::factory()->create(['name' => 'Restricted Client User', 'email' => 'client@acme.com']);
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $this->clientRole->id,
            'client_id' => $this->client->id,
            'is_active' => true,
        ]);

        $finding = Finding::create([
            'client_id'                 => $this->client->id,
            'title'                     => 'Test Finding',
            'finding_type'              => 'revenue_opportunity',
            'finding_category'          => \App\Enums\FindingCategory::Revenue->value,
            'severity'                  => 'medium',
            'status'                    => 'new',
            'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
        ]);

        $policy = new FindingPolicy();

        // Even though assigned to a client, user lacks findings.view
        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($policy->view($user, $finding));
    }

    public function test_user_with_findings_permission_is_allowed_by_policy(): void
    {
        // Ensure findings.view is attached
        $findingViewPerm = Permission::where('name', 'findings.view')->first();
        if (! $this->clientRole->permissions->contains('id', $findingViewPerm->id)) {
            $this->clientRole->permissions()->attach($findingViewPerm);
        }

        $user = User::factory()->create(['name' => 'Allowed Client User', 'email' => 'allowed@acme.com']);
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $this->clientRole->id,
            'client_id' => $this->client->id,
            'is_active' => true,
        ]);

        $finding = Finding::create([
            'client_id'                 => $this->client->id,
            'title'                     => 'Customer Visible Finding',
            'finding_type'              => 'revenue_opportunity',
            'finding_category'          => \App\Enums\FindingCategory::Revenue->value,
            'severity'                  => 'high',
            'status'                    => 'new',
            'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
        ]);

        $policy = new FindingPolicy();

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $finding));
    }

    public function test_finding_resource_can_view_any_and_direct_url_access(): void
    {
        // Detach findings.view from role
        $this->clientRole->permissions()->detach(
            Permission::whereIn('name', ['findings.view', 'findings.view_any'])->pluck('id')
        );

        $user = User::factory()->create(['name' => 'Restricted Client User', 'email' => 'restricted@acme.com']);
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $this->clientRole->id,
            'client_id' => $this->client->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        // Resource navigation/view checks
        $this->assertFalse(FindingResource::canViewAny());
        $this->assertFalse(FindingResource::shouldRegisterNavigation());
        $this->assertNull(FindingResource::getNavigationBadge());

        // Direct URL access blocked with 403
        $response = $this->get(FindingResource::getUrl('index'));
        $response->assertForbidden();
    }

    public function test_business_dashboard_hides_findings_panel_when_unauthorized(): void
    {
        // Detach findings permissions
        $this->clientRole->permissions()->detach(
            Permission::whereIn('name', ['findings.view', 'findings.view_any'])->pluck('id')
        );

        $user = User::factory()->create(['name' => 'Client User', 'email' => 'client_dashboard@acme.com']);
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $this->clientRole->id,
            'client_id' => $this->client->id,
            'is_active' => true,
        ]);

        Finding::create([
            'client_id'                 => $this->client->id,
            'title'                     => 'Secret Finding',
            'finding_type'              => 'revenue_opportunity',
            'finding_category'          => \App\Enums\FindingCategory::Revenue->value,
            'severity'                  => 'high',
            'status'                    => 'new',
            'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
        ]);

        $this->actingAs($user);

        $dashboard = new BusinessDashboard();
        $dashboard->selectedClientId = $this->client->id;

        $this->assertFalse($dashboard->canViewFindings());
        $this->assertEmpty($dashboard->getFindingsSummary());

        // Test Livewire component render does not display Intelligence Findings
        Livewire::test(BusinessDashboard::class, ['selectedClientId' => $this->client->id])
            ->assertDontSee('Intelligence Findings')
            ->assertDontSee('Secret Finding')
            ->assertSee('Revenue Trend');
    }

    public function test_business_dashboard_shows_findings_panel_when_authorized(): void
    {
        // Ensure findings.view is attached
        $findingViewPerm = Permission::where('name', 'findings.view')->first();
        if (! $this->clientRole->permissions->contains('id', $findingViewPerm->id)) {
            $this->clientRole->permissions()->attach($findingViewPerm);
        }

        $user = User::factory()->create(['name' => 'Authorized User', 'email' => 'authorized_dash@acme.com']);
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $this->clientRole->id,
            'client_id' => $this->client->id,
            'is_active' => true,
        ]);

        Finding::create([
            'client_id'                 => $this->client->id,
            'title'                     => 'Visible Audit Finding',
            'finding_type'              => 'revenue_opportunity',
            'finding_category'          => \App\Enums\FindingCategory::Revenue->value,
            'severity'                  => 'critical',
            'status'                    => 'new',
            'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
        ]);

        $this->actingAs($user);

        $dashboard = new BusinessDashboard();
        $dashboard->selectedClientId = $this->client->id;

        $this->assertTrue($dashboard->canViewFindings());
        $summary = $dashboard->getFindingsSummary();
        $this->assertNotEmpty($summary);
        $this->assertEquals(1, $summary['critical']);

        // Livewire component renders Intelligence Findings
        Livewire::test(BusinessDashboard::class, ['selectedClientId' => $this->client->id])
            ->assertSee('Intelligence Findings')
            ->assertSee('Visible Audit Finding')
            ->assertSee('Revenue Trend');
    }

    public function test_recent_findings_widget_and_relation_manager_visibility(): void
    {
        // Detach findings.view
        $this->clientRole->permissions()->detach(
            Permission::whereIn('name', ['findings.view', 'findings.view_any'])->pluck('id')
        );

        $user = User::factory()->create(['name' => 'Restricted User', 'email' => 'restricted_widget@acme.com']);
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $this->clientRole->id,
            'client_id' => $this->client->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $this->assertFalse(RecentFindingsWidget::canView());
        $this->assertFalse(FindingsRelationManager::canViewForRecord($this->client, 'view'));

        // Grant permission
        $this->clientRole->permissions()->attach(Permission::where('name', 'findings.view')->first());

        $this->assertTrue(RecentFindingsWidget::canView());
        $this->assertTrue(FindingsRelationManager::canViewForRecord($this->client, 'view'));
    }
}
