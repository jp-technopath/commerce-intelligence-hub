<?php

use App\Http\Controllers\Api\WorkerAgentJobController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1/worker')
    ->middleware('worker.auth')
    ->name('api.internal.worker.')
    ->group(function (): void {
        Route::post('/heartbeat', [WorkerAgentJobController::class, 'workerHeartbeat'])
            ->name('heartbeat');
        Route::post('/jobs/claim', [WorkerAgentJobController::class, 'claim'])
            ->name('jobs.claim');
        Route::post('/jobs/{agentJob}/heartbeat', [WorkerAgentJobController::class, 'heartbeat'])
            ->name('jobs.heartbeat');
        Route::post('/jobs/{agentJob}/progress', [WorkerAgentJobController::class, 'progress'])
            ->name('jobs.progress');
        Route::post('/jobs/{agentJob}/result', [WorkerAgentJobController::class, 'result'])
            ->name('jobs.result');
        Route::post('/jobs/{agentJob}/failure', [WorkerAgentJobController::class, 'failure'])
            ->name('jobs.failure');
        Route::get('/jobs/{agentJob}/cancellation', [WorkerAgentJobController::class, 'cancellation'])
            ->name('jobs.cancellation');
        Route::post('/jobs/{agentJob}/cancelled', [WorkerAgentJobController::class, 'cancelled'])
            ->name('jobs.cancelled');
        Route::post('/jobs/{agentJob}/complete', [WorkerAgentJobController::class, 'complete'])
            ->name('jobs.complete');
    });
