<?php

namespace App\Jobs;

use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Services\EstimateApprovalService;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPmProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;
    public $tries = 2;

    public function __construct(
        public PmProject $project,
        public ?int $days = 30
    ) {}

    public function handle(JiraProvider $jiraProvider, EstimateApprovalService $approvalService): void
    {
        Log::info("SyncPmProjectJob: Starting sync for project {$this->project->external_project_key} (ID #{$this->project->id})");

        try {
            $workItems = $jiraProvider->syncWorkItems($this->project, $this->days);
            Log::info("SyncPmProjectJob: Synced " . count($workItems) . " items for {$this->project->external_project_key}");

            if (empty($workItems)) {
                return;
            }

            // Batch query worklog sums for all synced items with time_spent_seconds > 0
            $itemsWithTime = collect($workItems)->filter(fn (PmWorkItem $item) => $item->time_spent_seconds > 0);
            $dbWorklogSums = $itemsWithTime->isNotEmpty()
                ? PmWorklog::whereIn('pm_work_item_id', $itemsWithTime->pluck('id'))
                    ->groupBy('pm_work_item_id')
                    ->selectRaw('pm_work_item_id, SUM(time_spent_seconds) as total_seconds')
                    ->pluck('total_seconds', 'pm_work_item_id')
                : collect();

            foreach ($workItems as $workItem) {
                // Estimate approval checks
                try {
                    $approvalService->checkInitialEstimateApprovalNeeded($workItem);
                    $approvalService->checkEstimateReapprovalNeeded($workItem, $workItem->estimated_seconds);
                } catch (\Throwable $ae) {
                    Log::warning("Estimate approval check failed for item {$workItem->external_item_key}: " . $ae->getMessage());
                }

                // Sync worklogs if item has logged time and DB worklogs differ from reported time_spent_seconds
                if ($workItem->time_spent_seconds > 0) {
                    $dbSeconds = (int) ($dbWorklogSums->get($workItem->id) ?? 0);
                    if ($dbSeconds != $workItem->time_spent_seconds) {
                        try {
                            $jiraProvider->syncWorklogs($workItem);
                        } catch (\Throwable $we) {
                            Log::warning("Worklog sync failed for {$workItem->external_item_key}: " . $we->getMessage());
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error("SyncPmProjectJob failed for project {$this->project->external_project_key}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
