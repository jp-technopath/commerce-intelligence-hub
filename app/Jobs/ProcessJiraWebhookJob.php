<?php

namespace App\Jobs;

use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Services\EstimateApprovalService;
use App\Services\PM\Providers\JiraProvider;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessJiraWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $webhookEvent,
        public readonly array $payload
    ) {}

    public function handle(JiraProvider $jiraProvider, EstimateApprovalService $approvalService): void
    {
        $issueData = $this->payload['issue'] ?? null;
        if (! $issueData) {
            return;
        }

        $projectKey = $issueData['fields']['project']['key'] ?? null;
        if (! $projectKey) {
            return;
        }

        $pmProject = PmProject::where('external_project_key', $projectKey)->first();
        if (! $pmProject) {
            Log::info("ProcessJiraWebhookJob: No PM project registered for key {$projectKey}");
            return;
        }

        $connection = $pmProject->connection;

        // 1. Process issue created or updated
        if (in_array($this->webhookEvent, ['jira:issue_created', 'jira:issue_updated', 'issue_created', 'issue_updated'], true) || empty($this->webhookEvent)) {
            $oldOriginalEstimate = $issueData['fields']['timetracking']['originalEstimateSeconds'] ?? 0;

            $workItem = $jiraProvider->normalizeAndSaveWorkItem($issueData, $pmProject, $connection);

            // Check if estimate reapproval is needed
            if ($oldOriginalEstimate > 0) {
                $approvalService->checkEstimateReapprovalNeeded($workItem, (int) $oldOriginalEstimate);
            }
        }

        // 2. Process worklog events
        $worklogData = $this->payload['worklog'] ?? null;
        if ($worklogData && isset($workItem)) {
            if (in_array($this->webhookEvent, ['jira:worklog_deleted', 'worklog_deleted'], true)) {
                PmWorklog::where('pm_connection_id', $connection->id)
                    ->where('external_worklog_id', (string) $worklogData['id'])
                    ->delete();
            } else {
                PmWorklog::updateOrCreate(
                    [
                        'client_id'           => $connection->client_id,
                        'pm_connection_id'    => $connection->id,
                        'external_worklog_id' => (string) $worklogData['id'],
                    ],
                    [
                        'pm_work_item_id'     => $workItem->id,
                        'author_name'         => $worklogData['author']['displayName'] ?? 'Unknown',
                        'time_spent_seconds'  => (int) ($worklogData['timeSpentSeconds'] ?? 0),
                        'worklog_started_at'  => isset($worklogData['started']) ? Carbon::parse($worklogData['started']) : now(),
                        'external_created_at' => isset($worklogData['created']) ? Carbon::parse($worklogData['created']) : null,
                        'external_updated_at' => isset($worklogData['updated']) ? Carbon::parse($worklogData['updated']) : null,
                        'last_synced_at'      => now(),
                    ]
                );
            }
        }
    }
}
