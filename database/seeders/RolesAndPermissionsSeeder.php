<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create default permissions
        $permissions = [
            'manage_users' => 'Manage user accounts, roles, and permissions.',
            'manage_clients' => 'Create, edit, or delete clients.',
            'view_clients' => 'View clients and health scores.',
            'manage_meetings' => 'View, prepare, or follow up on client meetings.',
            'manage_deployments' => 'View and trigger deployments.',
            'view_analytics' => 'View metrics and intelligence findings.',
        ];

        $permissionModels = [];
        foreach ($permissions as $name => $description) {
            $permissionModels[$name] = Permission::firstOrCreate(
                ['name' => $name],
                ['description' => $description]
            );
        }

        // 2. Create default roles and assign permissions
        
        // Super Admin Role
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'Super Admin'],
            ['description' => 'Administrator with full access to all system functions and user settings.']
        );
        // Super Admin gets all permissions
        $superAdminRole->permissions()->sync(array_values(array_map(fn($p) => $p->id, $permissionModels)));

        // Manager Role
        $managerRole = Role::firstOrCreate(
            ['name' => 'Manager'],
            ['description' => 'Business manager with rights to edit metrics, view clients, and manage client meetings.']
        );
        $managerRole->permissions()->sync([
            $permissionModels['manage_clients']->id,
            $permissionModels['view_clients']->id,
            $permissionModels['manage_meetings']->id,
            $permissionModels['manage_deployments']->id,
            $permissionModels['view_analytics']->id,
        ]);

        // Viewer Role
        $viewerRole = Role::firstOrCreate(
            ['name' => 'Viewer'],
            ['description' => 'Read-only access to view client dashboards, health metrics, and intelligence findings.']
        );
        $viewerRole->permissions()->sync([
            $permissionModels['view_clients']->id,
            $permissionModels['view_analytics']->id,
        ]);

        // 3. Assign Super Admin role to existing admin users
        $adminUsers = User::where('is_admin', true)->get();
        foreach ($adminUsers as $admin) {
            if (!$admin->roles()->where('name', 'Super Admin')->exists()) {
                $admin->roles()->attach($superAdminRole->id);
            }
        }
    }
}
