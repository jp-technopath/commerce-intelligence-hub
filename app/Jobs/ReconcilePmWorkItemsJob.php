<?php

namespace App\Jobs;

use App\Models\PmConnection;
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

    public $timeout = 300;
    public $tries = 1;

    public function __construct(
        public ?int $days = 30,
        public bool $syncInline = false
    ) {}

    public function handle(JiraProvider $jiraProvider): void
    {
        $connections = PmConnection::where('is_active', true)->get();

        foreach ($connections as $connection) {
            try {
                // First, discover and sync Jira projects for this connection (updates is_active properly)
                $jiraProvider->syncProjects($connection);

                // Dispatch project-level sync jobs for each active project
                $activeProjects = $connection->projects()->where('is_active', true)->get();

                foreach ($activeProjects as $project) {
                    if ($this->syncInline) {
                        SyncPmProjectJob::dispatchSync($project, $this->days);
                    } else {
                        SyncPmProjectJob::dispatch($project, $this->days);
                    }
                }

                $connection->update(['last_synced_at' => now()]);
            } catch (\Throwable $e) {
                Log::error("ReconcilePmWorkItemsJob failed for Connection #{$connection->id}: " . $e->getMessage());
            }
        }
    }
}
