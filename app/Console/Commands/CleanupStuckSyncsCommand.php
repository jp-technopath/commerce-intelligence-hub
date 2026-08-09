<?php

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use Illuminate\Console\Command;

class CleanupStuckSyncsCommand extends Command
{
    protected $signature = 'syncs:cleanup-stuck {--minutes=15 : Minutes threshold to consider a running sync stuck}';

    protected $description = 'Marks sync logs stuck in running state as failed';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        $affected = SyncLog::where('status', SyncStatus::Running)
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->update([
                'status'        => SyncStatus::Failed,
                'error_message' => "Sync process timed out after {$minutes} minutes or worker process terminated unexpectedly.",
                'completed_at'  => now(),
            ]);

        $this->info("Cleaned up {$affected} stuck sync log(s).");

        return Command::SUCCESS;
    }
}
