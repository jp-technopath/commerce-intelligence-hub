<?php

namespace App\Providers;

use App\Models\Finding;
use App\Observers\FindingObserver;
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

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Finding::observe(FindingObserver::class);
    }
}
