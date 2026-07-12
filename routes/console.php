<?php

use App\Jobs\RunNightlyAnalysis;
use App\Jobs\TriggerIntegrationSync;
use App\Models\Integration;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Technopath Commerce Intelligence — Scheduled Tasks
|--------------------------------------------------------------------------
|
| Compatible with Laravel Cloud scheduling.
| All times are UTC. Configure via INTELLIGENCE_NIGHTLY_RUN_TIME env var.
|
*/

// Daily data sync — pull latest data from all active integrations
// Runs at 01:00 UTC so data is fresh before nightly analysis at 02:00
Schedule::call(function () {
    Integration::where('status', 'active')->each(function (Integration $integration) {
        TriggerIntegrationSync::dispatch($integration);
    });
})
    ->dailyAt('01:00')
    ->name('daily-integration-sync')
    ->withoutOverlapping(60);

// Nightly analysis — runs Change Detection Engine for all active clients
// Phase 4 will fully implement RunNightlyAnalysis
Schedule::job(new RunNightlyAnalysis())
    ->dailyAt(config('intelligence.nightly_run_time', '02:00'))
    ->name('nightly-intelligence-analysis')
    ->withoutOverlapping(60);

// Customer meeting calendar scan — checks connected Google Calendars for upcoming meetings
Schedule::command('customer-meetings:scan-upcoming')
    ->hourly()
    ->name('customer-meetings:scan-upcoming')
    ->withoutOverlapping(30);
