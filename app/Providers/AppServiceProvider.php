<?php

namespace App\Providers;

use App\Contracts\ComputeEngineClient;
use App\Contracts\WorkerIdentityVerifier;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\DevelopmentRequest;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Integration;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Role;
use App\Models\User;
use App\Observers\FindingObserver;
use App\Policies\ClientMeetingPolicy;
use App\Policies\ClientPolicy;
use App\Policies\DevelopmentRequestPolicy;
use App\Policies\DeploymentPolicy;
use App\Policies\FindingPolicy;
use App\Policies\IntegrationPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\ProjectEnvironmentMappingPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\GoogleComputeEngineClient;
use App\Services\GoogleWorkerIdentityVerifier;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ComputeEngineClient::class, GoogleComputeEngineClient::class);
        $this->app->singleton(WorkerIdentityVerifier::class, GoogleWorkerIdentityVerifier::class);
    }

    public function boot(): void
    {
        Finding::observe(FindingObserver::class);

        // Explicit Policy Registrations
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(ProjectEnvironmentMapping::class, ProjectEnvironmentMappingPolicy::class);
        Gate::policy(Finding::class, FindingPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Deployment::class, DeploymentPolicy::class);
        Gate::policy(Integration::class, IntegrationPolicy::class);
        Gate::policy(ClientMeeting::class, ClientMeetingPolicy::class);
        Gate::policy(DevelopmentRequest::class, DevelopmentRequestPolicy::class);

        // Bypass all permission checks for users with Super Admin privileges
        Gate::before(function ($user, $ability) {
            return $user->isSuperAdmin() ? true : null;
        });

        // Register dynamic gates for permissions with optional scope arguments
        try {
            if ($this->app->runningInConsole() === false || Schema::hasTable('permissions')) {
                $permissions = Permission::all();
                foreach ($permissions as $permission) {
                    Gate::define($permission->name, function ($user, ?int $clientId = null, ?int $projectId = null) use ($permission) {
                        return $user->hasPermission($permission->name, $clientId, $projectId);
                    });
                }
            }
        } catch (\Throwable $e) {
            // Safeguard against a missing database, missing tables, or connection errors
            // during image builds, migrations, and other early bootstrap commands.
        }
    }
}
