<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Integration;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Observers\FindingObserver;
use App\Policies\ClientMeetingPolicy;
use App\Policies\ClientPolicy;
use App\Policies\DeploymentPolicy;
use App\Policies\FindingPolicy;
use App\Policies\IntegrationPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Finding::observe(FindingObserver::class);

        // Explicit Policy Registrations
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Finding::class, FindingPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Deployment::class, DeploymentPolicy::class);
        Gate::policy(Integration::class, IntegrationPolicy::class);
        Gate::policy(ClientMeeting::class, ClientMeetingPolicy::class);

        // Bypass all permission checks for users with Super Admin privileges
        Gate::before(function ($user, $ability) {
            return $user->isSuperAdmin() ? true : null;
        });

        // Register dynamic gates for permissions with optional scope arguments
        if ($this->app->runningInConsole() === false || \Illuminate\Support\Facades\Schema::hasTable('permissions')) {
            try {
                $permissions = Permission::all();
                foreach ($permissions as $permission) {
                    Gate::define($permission->name, function ($user, ?int $clientId = null, ?int $projectId = null) use ($permission) {
                        return $user->hasPermission($permission->name, $clientId, $projectId);
                    });
                }
            } catch (\Throwable $e) {
                // Safeguard against missing DB tables or connection errors during early bootstrap
            }
        }
    }
}
