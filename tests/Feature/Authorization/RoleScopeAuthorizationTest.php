<?php

namespace Tests\Feature\Authorization;

use App\Models\AuthorizationAuditLog;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Policies\UserPolicy;
use App\Services\ApprovalService;
use App\Services\VisibilityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected Client $clientA;
    protected Client $clientB;
    protected Project $projectA1;
    protected Project $projectB1;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create Super Admin
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

        // Create Clients and Projects directly
        $this->clientA = Client::create([
            'name'          => 'Client Alpha',
            'industry'      => 'Ecommerce',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $this->clientB = Client::create([
            'name'          => 'Client Beta',
            'industry'      => 'Retail',
            'platform_type' => 'Adobe Commerce',
            'status'        => 'active',
        ]);

        $this->projectA1 = Project::create([
            'client_id' => $this->clientA->id,
            'name'      => 'Project Alpha 1',
            'status'    => 'active',
        ]);

        $this->projectB1 = Project::create([
            'client_id' => $this->clientB->id,
            'name'      => 'Project Beta 1',
            'status'    => 'active',
        ]);
    }

    /** @test */
    public function super_admin_has_unrestricted_access()
    {
        $this->assertTrue($this->superAdmin->isSuperAdmin());
        $this->assertTrue($this->superAdmin->hasPermission('financials.view_internal'));
        $this->assertEquals(['*'], $this->superAdmin->getAssignedClientIds());
        $this->assertEquals(['*'], $this->superAdmin->getAssignedProjectIds());
    }

    /** @test */
    public function customer_admin_is_scoped_to_assigned_client()
    {
        $custAdmin = User::factory()->create(['name' => 'Customer Admin Alpha']);
        $custAdminRole = Role::where('name', Role::ROLE_CUSTOMER_ADMIN)->first();

        UserRoleAssignment::create([
            'user_id'   => $custAdmin->id,
            'role_id'   => $custAdminRole->id,
            'client_id' => $this->clientA->id,
            'is_active' => true,
        ]);

        $this->assertFalse($custAdmin->isSuperAdmin());
        $this->assertTrue($custAdmin->hasRole(Role::ROLE_CUSTOMER_ADMIN, $this->clientA->id));
        $this->assertFalse($custAdmin->hasRole(Role::ROLE_CUSTOMER_ADMIN, $this->clientB->id));

        $this->assertEquals([$this->clientA->id], $custAdmin->getAssignedClientIds());
        $this->assertContains($this->projectA1->id, $custAdmin->getAssignedProjectIds());
        $this->assertNotContains($this->projectB1->id, $custAdmin->getAssignedProjectIds());

        // Cannot access internal financial records
        $this->assertFalse($custAdmin->hasPermission('financials.view_internal', $this->clientA->id));
        $this->assertTrue($custAdmin->hasPermission('financials.view_customer', $this->clientA->id));
    }

    /** @test */
    public function customer_admin_privilege_escalation_is_prevented()
    {
        $custAdmin = User::factory()->create(['name' => 'Customer Admin Alpha']);
        $custAdminRole = Role::where('name', Role::ROLE_CUSTOMER_ADMIN)->first();

        UserRoleAssignment::create([
            'user_id'   => $custAdmin->id,
            'role_id'   => $custAdminRole->id,
            'client_id' => $this->clientA->id,
            'is_active' => true,
        ]);

        // Customer admin cannot assign internal roles
        $this->assertFalse(UserPolicy::canAssignRole($custAdmin, Role::ROLE_SUPER_ADMIN, $this->clientA->id));
        $this->assertFalse(UserPolicy::canAssignRole($custAdmin, Role::ROLE_ENGINEER, $this->clientA->id));
        $this->assertFalse(UserPolicy::canAssignRole($custAdmin, Role::ROLE_PRODUCT_OWNER, $this->clientA->id));

        // Customer admin CAN assign customer-facing roles for their client
        $this->assertTrue(UserPolicy::canAssignRole($custAdmin, Role::ROLE_CLIENT_USER, $this->clientA->id));
        $this->assertTrue(UserPolicy::canAssignRole($custAdmin, Role::ROLE_CUSTOMER_ADMIN, $this->clientA->id));

        // Customer admin CANNOT assign roles for other clients
        $this->assertFalse(UserPolicy::canAssignRole($custAdmin, Role::ROLE_CLIENT_USER, $this->clientB->id));

        // Protection against deleting final Customer Admin
        $this->assertTrue(UserPolicy::isFinalCustomerAdmin($custAdmin));
    }

    /** @test */
    public function product_owner_can_define_scope_and_approve_business_outcomes()
    {
        $po = User::factory()->create(['name' => 'Product Owner']);
        $poRole = Role::where('name', Role::ROLE_PRODUCT_OWNER)->first();

        UserRoleAssignment::create([
            'user_id'    => $po->id,
            'role_id'    => $poRole->id,
            'client_id'  => $this->clientA->id,
            'project_id' => $this->projectA1->id,
            'is_active'  => true,
        ]);

        $this->assertTrue($po->hasPermission('projects.approve', $this->clientA->id, $this->projectA1->id));
        $this->assertTrue(ApprovalService::canApprove($po, $this->projectA1, 'business_scope'));

        // Cannot access unassigned project B1
        $this->assertFalse($po->hasPermission('projects.approve', $this->clientB->id, $this->projectB1->id));
    }

    /** @test */
    public function engineer_self_approval_is_prevented()
    {
        $engineer = User::factory()->create(['name' => 'Engineer Dave']);
        $engineerRole = Role::where('name', Role::ROLE_ENGINEER)->first();

        UserRoleAssignment::create([
            'user_id'    => $engineer->id,
            'role_id'    => $engineerRole->id,
            'client_id'  => $this->clientA->id,
            'project_id' => $this->projectA1->id,
            'is_active'  => true,
        ]);

        $deployment = Deployment::create([
            'client_id'       => $this->clientA->id,
            'project_id'      => $this->projectA1->id,
            'title'           => 'Feature Release v1.0',
            'deployment_type' => \App\Enums\DeploymentType::PlatformRelease->value,
            'deployed_by'     => 'Engineer Dave',
            'deployed_at'     => now(),
            'user_id'         => $engineer->id, // Created/implemented by engineer
        ]);

        // Engineer Dave cannot approve their own deployment!
        $this->assertFalse($engineer->canApproveWork($deployment, 'production_deployment'));
        $this->assertFalse(ApprovalService::canApprove($engineer, $deployment, 'production_deployment'));

        // A different engineer CAN approve it
        $otherEngineer = User::factory()->create(['name' => 'Engineer Sarah']);
        UserRoleAssignment::create([
            'user_id'    => $otherEngineer->id,
            'role_id'    => $engineerRole->id,
            'client_id'  => $this->clientA->id,
            'project_id' => $this->projectA1->id,
            'is_active'  => true,
        ]);

        $this->assertTrue($otherEngineer->canApproveWork($deployment, 'production_deployment'));
        $this->assertTrue(ApprovalService::canApprove($otherEngineer, $deployment, 'production_deployment'));
    }

    /** @test */
    public function client_user_sees_only_customer_visible_records()
    {
        $clientUser = User::factory()->create(['name' => 'Client Portal User']);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $this->clientA->id,
            'is_active' => true,
        ]);

        $internalFinding = Finding::create([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Internal Technical Anomaly',
            'finding_type'              => 'technical_anomaly',
            'finding_category'          => \App\Enums\FindingCategory::Technical->value,
            'severity'                  => 'medium',
            'status'                    => 'new',
            'visibility_classification' => VisibilityService::CLASSIFICATION_INTERNAL,
        ]);

        $customerFinding = Finding::create([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Customer Revenue Opportunity',
            'finding_type'              => 'revenue_opportunity',
            'finding_category'          => \App\Enums\FindingCategory::Revenue->value,
            'severity'                  => 'high',
            'status'                    => 'new',
            'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
        ]);

        $this->assertFalse(VisibilityService::canViewRecord($clientUser, $internalFinding));
        $this->assertTrue(VisibilityService::canViewRecord($clientUser, $customerFinding));
    }

    /** @test */
    public function multiple_scoped_roles_work_independently()
    {
        $multiUser = User::factory()->create(['name' => 'Multi Role User']);
        $poRole = Role::where('name', Role::ROLE_PRODUCT_OWNER)->first();
        $engineerRole = Role::where('name', Role::ROLE_ENGINEER)->first();

        // Product Owner for Client A
        UserRoleAssignment::create([
            'user_id'   => $multiUser->id,
            'role_id'   => $poRole->id,
            'client_id' => $this->clientA->id,
            'is_active' => true,
        ]);

        // Engineer for Project B1
        UserRoleAssignment::create([
            'user_id'    => $multiUser->id,
            'role_id'    => $engineerRole->id,
            'client_id'  => $this->clientB->id,
            'project_id' => $this->projectB1->id,
            'is_active'  => true,
        ]);

        $this->assertTrue($multiUser->hasRole(Role::ROLE_PRODUCT_OWNER, $this->clientA->id));
        $this->assertFalse($multiUser->hasRole(Role::ROLE_PRODUCT_OWNER, $this->clientB->id));

        $this->assertTrue($multiUser->hasRole(Role::ROLE_ENGINEER, $this->clientB->id, $this->projectB1->id));
        $this->assertFalse($multiUser->hasRole(Role::ROLE_ENGINEER, $this->clientA->id));

        $assignedClients = $multiUser->getAssignedClientIds();
        $this->assertContains($this->clientA->id, $assignedClients);
        $this->assertContains($this->clientB->id, $assignedClients);
    }

    /** @test */
    public function audit_logs_record_authorization_events()
    {
        $targetUser = User::factory()->create(['name' => 'Target User']);
        $custAdminRole = Role::where('name', Role::ROLE_CUSTOMER_ADMIN)->first();

        AuthorizationAuditLog::record(
            targetUser: $targetUser,
            action: 'role_assigned',
            actor: $this->superAdmin,
            role: $custAdminRole,
            client: $this->clientA
        );

        $this->assertDatabaseHas('authorization_audit_logs', [
            'actor_id'       => $this->superAdmin->id,
            'target_user_id' => $targetUser->id,
            'action'         => 'role_assigned',
            'role_id'        => $custAdminRole->id,
            'client_id'      => $this->clientA->id,
        ]);
    }
}
