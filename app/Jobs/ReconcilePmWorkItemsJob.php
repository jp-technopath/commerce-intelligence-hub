<?php

namespace App\Jobs;

use App\Models\PmConnection;
use App\Services\EstimateApprovalService;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcilePmWorkItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(JiraProvider $jiraProvider, EstimateApprovalService $approvalService): void
    {
        $connections = PmConnection::where('is_active', true)->get();

        foreach ($connections as $connection) {
            try {
                // First, discover and sync all Jira projects for this connection
                $jiraProvider->syncProjects($connection);

                // Reconcile each active project in connection
                foreach ($connection->projects()->where('is_active', true)->get() as $project) {
                    $workItems = $jiraProvider->syncWorkItems($project);

                    foreach ($workItems as $workItem) {
                        $approvalService->checkInitialEstimateApprovalNeeded($workItem);
                        $approvalService->checkEstimateReapprovalNeeded($workItem, $workItem->estimated_seconds);
                    }
                }

                // Systemic Worklog Sync for ALL work items under connection (Project tasks & Service Desk tickets)
                $allItems = \App\Models\PmWorkItem::where('pm_connection_id', $connection->id)->get();
                foreach ($allItems as $item) {
                    try {
                        $jiraProvider->syncWorklogs($item);
                    } catch (\Throwable $we) {
                        // Suppress individual item worklog errors
                    }
                }
            } catch (\Exception $e) {
                Log::error("ReconcilePmWorkItemsJob failed for Connection #{$connection->id}: " . $e->getMessage());
            }
        }
    }
}
