<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_assigned_roles_and_permissions(): void
    {
        // Create user
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        // Create permission
        $permission = Permission::create([
            'name' => 'view_clients',
            'description' => 'View clients dashboard',
        ]);

        // Create role
        $role = Role::create([
            'name' => 'Viewer',
            'description' => 'Viewer role',
        ]);

        // Attach permission to role
        $role->permissions()->attach($permission->id);

        // Attach role to user
        $user->roles()->attach($role->id);

        // Assert relationship structure
        $this->assertTrue($user->roles->contains($role));
        $this->assertTrue($role->permissions->contains($permission));

        // Test custom methods on User model
        $this->assertTrue($user->hasRole('Viewer'));
        $this->assertFalse($user->hasRole('Manager'));

        $this->assertTrue($user->hasPermission('view_clients'));
        $this->assertFalse($user->hasPermission('manage_users'));
    }

    public function test_is_admin_users_automatically_pass_all_permission_checks(): void
    {
        // Create super admin user
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        // Act & Assert helper methods
        $this->assertTrue($admin->hasPermission('some_arbitrary_permission'));

        // Act & Assert Laravel Gates (AppServiceProvider Gate::before)
        $this->assertTrue(Gate::forUser($admin)->allows('some_arbitrary_permission'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage_users'));
    }

    public function test_regular_users_with_role_allowance_pass_gates(): void
    {
        // Seed standard permissions and roles
        $permission = Permission::create([
            'name' => 'manage_clients',
            'description' => 'Can manage clients',
        ]);

        $role = Role::create([
            'name' => 'Manager',
            'description' => 'Manager role',
        ]);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['is_admin' => false]);
        $user->roles()->attach($role->id);

        // Define gate dynamically since AppServiceProvider boots early but we refreshed DB
        Gate::define('manage_clients', function ($u) {
            return $u->hasPermission('manage_clients');
        });

        // Act & Assert
        $this->assertTrue($user->hasPermission('manage_clients'));
        $this->assertTrue(Gate::forUser($user)->allows('manage_clients'));
        $this->assertFalse(Gate::forUser($user)->allows('manage_users'));
    }
}
