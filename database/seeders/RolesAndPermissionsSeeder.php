<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Define all permissions grouped by family
        $permissionFamilies = [
            'dashboard' => ['view'],
            'clients' => ['view', 'view_any', 'create', 'update', 'delete', 'manage'],
            'projects' => ['view', 'view_any', 'create', 'update', 'delete', 'manage', 'approve'],
            'contacts' => ['view', 'view_any', 'create', 'update', 'delete', 'manage'],
            'integrations' => ['view', 'view_any', 'create', 'update', 'delete', 'manage'],
            'environments' => ['view', 'view_any', 'create', 'update', 'delete', 'manage'],
            'intelligence' => ['view', 'view_any', 'create', 'update', 'delete', 'manage'],
            'findings' => ['view', 'view_any', 'create', 'update', 'delete', 'approve'],
            'knowledge_base' => ['view', 'view_any', 'create', 'update', 'delete'],
            'project_briefs' => ['view', 'view_any', 'create', 'update', 'delete', 'approve'],
            'recommendations' => ['view', 'view_any', 'create', 'update', 'delete', 'approve'],
            'development_requests' => ['view', 'view_any', 'create', 'update', 'delete', 'approve', 'prioritize'],
            'agent_runs' => ['view', 'view_any', 'execute', 'approve'],
            'pull_requests' => ['view', 'view_any', 'create', 'update', 'approve'],
            'approvals' => ['view', 'approve'],
            'testing' => ['view', 'execute', 'approve'],
            'repositories' => ['view', 'view_any', 'manage'],
            'deployments' => ['view', 'view_any', 'create', 'update', 'approve', 'execute'],
            'meetings' => ['view', 'view_any', 'create', 'update', 'delete'],
            'financials' => ['view_customer', 'view_internal', 'manage_budget', 'manage_billing'],
            'users' => ['view', 'view_any', 'create', 'update', 'delete', 'assign_roles'],
            'roles' => ['view', 'view_any', 'create', 'update', 'delete'],
            'platform_settings' => ['view', 'manage'],
        ];

        $createdPermissions = [];

        foreach ($permissionFamilies as $family => $actions) {
            foreach ($actions as $action) {
                $permissionName = "{$family}.{$action}";
                $permission = Permission::firstOrCreate(
                    ['name' => $permissionName],
                    ['description' => "Allows {$action} on {$family}"]
                );
                $createdPermissions[$permissionName] = $permission->id;
            }
        }

        // 2. Define standard roles with descriptions and permissions
        $rolesDefinition = [
            Role::ROLE_SUPER_ADMIN => [
                'label' => 'Super Admin',
                'description' => 'Global system administrator with unrestricted platform access.',
                'permissions' => array_keys($createdPermissions), // All permissions
            ],
            Role::ROLE_CUSTOMER_ADMIN => [
                'label' => 'Customer Admin',
                'description' => 'Customer account administrator managing customer projects, users, and requests.',
                'permissions' => [
                    'dashboard.view', 'clients.view', 'clients.view_any', 'projects.view', 'projects.view_any',
                    'projects.approve', 'contacts.view', 'contacts.view_any', 'contacts.create', 'contacts.update',
                    'findings.view', 'findings.view_any', 'knowledge_base.view', 'knowledge_base.view_any',
                    'project_briefs.view', 'project_briefs.view_any', 'recommendations.view', 'recommendations.view_any',
                    'development_requests.view', 'development_requests.view_any', 'development_requests.create',
                    'development_requests.prioritize', 'testing.view', 'testing.approve', 'meetings.view',
                    'meetings.view_any', 'financials.view_customer', 'users.view', 'users.view_any',
                    'users.create', 'users.update', 'users.assign_roles',
                ],
            ],
            Role::ROLE_PRODUCT_OWNER => [
                'label' => 'Product Owner',
                'description' => 'Owns business scope, feature definitions, and business outcomes.',
                'permissions' => [
                    'dashboard.view', 'clients.view', 'clients.view_any', 'projects.view', 'projects.view_any',
                    'projects.create', 'projects.update', 'projects.approve', 'intelligence.view', 'intelligence.view_any',
                    'findings.view', 'findings.view_any', 'findings.create', 'findings.update', 'findings.approve',
                    'knowledge_base.view', 'knowledge_base.view_any', 'knowledge_base.create', 'knowledge_base.update',
                    'project_briefs.view', 'project_briefs.view_any', 'project_briefs.create', 'project_briefs.update',
                    'project_briefs.approve', 'recommendations.view', 'recommendations.view_any', 'recommendations.create',
                    'recommendations.update', 'recommendations.approve', 'development_requests.view',
                    'development_requests.view_any', 'development_requests.create', 'development_requests.update',
                    'development_requests.approve', 'development_requests.prioritize', 'agent_runs.view',
                    'agent_runs.view_any', 'agent_runs.approve', 'testing.view', 'testing.approve',
                    'deployments.view', 'deployments.view_any', 'meetings.view', 'meetings.view_any',
                    'meetings.create', 'meetings.update', 'financials.view_customer',
                ],
            ],
            Role::ROLE_ANALYST => [
                'label' => 'Analyst',
                'description' => 'Analyzes performance data, generates findings, and drafts recommendations.',
                'permissions' => [
                    'dashboard.view', 'clients.view', 'clients.view_any', 'projects.view', 'projects.view_any',
                    'intelligence.view', 'intelligence.view_any', 'intelligence.create', 'intelligence.update',
                    'findings.view', 'findings.view_any', 'findings.create', 'findings.update',
                    'knowledge_base.view', 'knowledge_base.view_any', 'knowledge_base.create', 'knowledge_base.update',
                    'project_briefs.view', 'project_briefs.view_any', 'project_briefs.create', 'project_briefs.update',
                    'recommendations.view', 'recommendations.view_any', 'recommendations.create', 'recommendations.update',
                    'development_requests.view', 'development_requests.view_any', 'development_requests.create',
                    'meetings.view', 'meetings.view_any', 'meetings.create', 'meetings.update',
                    'deployments.view', 'deployments.view_any', 'financials.view_customer',
                ],
            ],
            Role::ROLE_CLIENT_USER => [
                'label' => 'Client',
                'description' => 'Customer-facing client portal user with access to customer-approved project status, findings, and recommendations.',
                'permissions' => [
                    'dashboard.view', 'projects.view', 'findings.view', 'recommendations.view',
                    'project_briefs.view', 'knowledge_base.view', 'meetings.view', 'financials.view_customer',
                ],
            ],
            Role::ROLE_ENGINEER => [
                'label' => 'Engineer',
                'description' => 'Technical developer working on code, agent runs, pull requests, and deployments.',
                'permissions' => [
                    'dashboard.view', 'projects.view', 'projects.view_any', 'environments.view', 'environments.view_any',
                    'intelligence.view', 'intelligence.view_any', 'project_briefs.view', 'project_briefs.view_any',
                    'development_requests.view', 'development_requests.view_any', 'development_requests.update',
                    'agent_runs.view', 'agent_runs.view_any', 'agent_runs.execute', 'pull_requests.view',
                    'pull_requests.view_any', 'pull_requests.create', 'pull_requests.update', 'pull_requests.approve',
                    'testing.view', 'testing.execute', 'repositories.view', 'repositories.view_any', 'repositories.manage',
                    'deployments.view', 'deployments.view_any', 'deployments.create', 'deployments.update', 'deployments.approve',
                    'meetings.view', 'meetings.view_any',
                ],
            ],
        ];

        foreach ($rolesDefinition as $machineName => $info) {
            // Check if role exists under machine name or display label (e.g., 'Super Admin')
            $role = Role::where('name', $machineName)
                ->orWhere('name', $info['label'])
                ->first();

            if (! $role) {
                $role = Role::create([
                    'name'        => $machineName,
                    'description' => $info['description'],
                ]);
            } else {
                // Ensure machine name is updated
                $role->update([
                    'name'        => $machineName,
                    'description' => $info['description'],
                ]);
            }

            // Sync permissions
            $permissionIds = array_filter(array_map(
                fn ($permName) => $createdPermissions[$permName] ?? null,
                $info['permissions']
            ));

            $role->permissions()->sync($permissionIds);
        }

        // 3. Map existing users with is_admin = true to super_admin UserRoleAssignment
        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->first();

        if ($superAdminRole) {
            $adminUsers = User::where('is_admin', true)->get();
            foreach ($adminUsers as $user) {
                UserRoleAssignment::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'role_id' => $superAdminRole->id,
                    ],
                    [
                        'is_active' => true,
                        'notes'     => 'Automatically migrated from legacy is_admin flag.',
                    ]
                );
            }
        }
    }
}
