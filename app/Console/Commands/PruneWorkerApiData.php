<?php

namespace App\Console\Commands;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\WorkerApiRequest;
use Illuminate\Console\Command;

class PruneWorkerApiData extends Command
{
    protected $signature = 'devforge:prune-worker-api-data';

    protected $description = 'Remove expired replay responses and redact retained agent-job details.';

    public function handle(): int
    {
        $requestsDeleted = WorkerApiRequest::query()
            ->where('expires_at', '<', now())
            ->delete();

        $jobsPruned = AgentJob::query()
            ->whereIn('status', [
                AgentJobStatus::Cancelled->value,
                AgentJobStatus::Failed->value,
                AgentJobStatus::Completed->value,
            ])
            ->where('completed_at', '<', now()->subDays(config('devforge.agent_job_detail_retention_days')))
            ->whereNull('details_pruned_at')
            ->update([
                'payload' => null,
                'result' => null,
                'failure' => null,
                'progress_message' => null,
                'lease_token_ciphertext' => null,
                'details_pruned_at' => now(),
            ]);

        $this->info("Deleted {$requestsDeleted} expired replay records and redacted {$jobsPruned} agent jobs.");

        return self::SUCCESS;
    }
}
