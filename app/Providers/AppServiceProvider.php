<?php

namespace App\Providers;

use App\Models\ClientMeeting;
use App\Models\Finding;
use App\Observers\FindingObserver;
use App\Policies\ClientMeetingPolicy;
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

        Gate::policy(ClientMeeting::class, ClientMeetingPolicy::class);

        // Bypass all permission checks for users marked is_admin = true
        Gate::before(function ($user, $ability) {
            return $user->is_admin ? true : null;
        });

        // Register dynamic gates for permissions
        if ($this->app->runningInConsole() === false || \Illuminate\Support\Facades\Schema::hasTable('permissions')) {
            try {
                $permissions = \App\Models\Permission::all();
                foreach ($permissions as $permission) {
                    Gate::define($permission->name, function ($user) use ($permission) {
                        return $user->hasPermission($permission->name);
                    });
                }
            } catch (\Throwable $e) {
                // Safeguard against missing DB tables or connection errors during early bootstrap
            }
        }
    }
}
