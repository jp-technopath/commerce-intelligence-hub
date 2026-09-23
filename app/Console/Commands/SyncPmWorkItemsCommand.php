<?php

namespace App\Console\Commands;

use App\Jobs\SyncPmProjectJob;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Console\Command;

class SyncPmWorkItemsCommand extends Command
{
    protected $signature = 'pm:sync 
                            {--project= : Specific project key to sync (e.g. CMBR2, TRAN)}
                            {--connection= : Specific connection ID to sync}
                            {--days=30 : Fetch issues updated in the last N days (0 for all)}
                            {--all : Fetch all issues without date filter}
                            {--queue : Queue the sync jobs instead of running inline synchronously}';

    protected $description = 'Sync PM projects, work items, estimate approvals, and worklogs from Jira';

    public function handle(JiraProvider $jiraProvider): int
    {
        $days = $this->option('all') ? 0 : (int) $this->option('days');
        $queue = (bool) $this->option('queue');
        $projectKey = $this->option('project');
        $connId = $this->option('connection');

        $this->info("Starting PM sync (days: " . ($days > 0 ? $days : 'all') . ", mode: " . ($queue ? 'queued' : 'inline') . ")...");

        // Step 1: Update active project status across active connections
        $connQuery = PmConnection::where('is_active', true);
        if ($connId) {
            $connQuery->where('id', $connId);
        }
        $connections = $connQuery->get();

        if ($connections->isEmpty()) {
            $this->warn('No active PM connections found.');
            return self::FAILURE;
        }

        foreach ($connections as $conn) {
            $this->line("Discovering & updating projects for Connection #{$conn->id} ({$conn->name})...");
            $jiraProvider->syncProjects($conn);
        }

        // Step 2: Fetch active projects to sync
        $projQuery = PmProject::where('is_active', true);
        if ($projectKey) {
            $projQuery->where('external_project_key', strtoupper($projectKey));
        }
        if ($connId) {
            $projQuery->where('pm_connection_id', $connId);
        }
        $projects = $projQuery->get();

        $this->info("Found {$projects->count()} active project(s) to sync.");

        foreach ($projects as $project) {
            $this->line("Syncing project [{$project->external_project_key}] (Conn #{$project->pm_connection_id})...");
            $start = microtime(true);

            if ($queue) {
                SyncPmProjectJob::dispatch($project, $days);
                $this->info("  -> Queued sync job.");
            } else {
                SyncPmProjectJob::dispatchSync($project, $days);
                $elapsed = round(microtime(true) - $start, 2);
                $this->info("  -> Synced inline in {$elapsed}s.");
            }
        }

        $this->info('PM Sync finished successfully.');
        return self::SUCCESS;
    }
}
