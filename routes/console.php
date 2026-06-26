<?php

use App\Jobs\RunNightlyAnalysis;
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

// Nightly analysis — runs Change Detection Engine for all active clients
// Phase 4 will fully implement RunNightlyAnalysis
Schedule::job(new RunNightlyAnalysis())
    ->dailyAt(config('intelligence.nightly_run_time', '02:00'))
    ->name('nightly-intelligence-analysis')
    ->withoutOverlapping(60);
