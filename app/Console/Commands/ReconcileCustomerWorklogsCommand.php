<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmWorklog;
use App\Models\User;
use App\Services\PM\IdentityResolverService;
use App\Services\PM\Providers\TempoClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileCustomerWorklogsCommand extends Command
{
    protected $signature = 'pm:reconcile-customer-worklogs
        {--client= : Specific Client ID to reconcile}
        {--connection= : Specific PM Connection ID to reconcile}
        {--from= : Start date for worklog filtering (YYYY-MM-DD)}
        {--to= : End date for worklog filtering (YYYY-MM-DD)}
        {--limit= : Maximum number of worklogs to process}
        {--dry-run : Simulate resolution without modifying database}';

    protected $description = 'Authoritatively reconcile unattributed Jira worklogs using the Tempo API';

    public function handle(): int
    {
        $this->info('Starting Authoritative Tempo Worklog Reconciliation...');

        $clientId = $this->option('client');
        $connectionId = $this->option('connection');
        $fromDate = $this->option('from');
        $toDate = $this->option('to');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No database changes will be committed.');
        }

        // Build base query
        $query = PmWorklog::query()
            ->where('attribution_source', 'unattributed')
            ->whereNotNull('external_worklog_id');

        if ($clientId) {
            $query->where('client_id', $clientId);
            $client = Client::find($clientId);
            $this->line("Filtering by Client: <info>" . ($client?->name ?? "ID {$clientId}") . "</info>");
        }

        if ($connectionId) {
            $query->where('pm_connection_id', $connectionId);
            $this->line("Filtering by Connection ID: <info>{$connectionId}</info>");
        }

        if ($fromDate) {
            $query->where('worklog_started_at', '>=', Carbon::parse($fromDate)->startOfDay());
        }

        if ($toDate) {
            $query->where('worklog_started_at', '<=', Carbon::parse($toDate)->endOfDay());
        }

        if ($limit) {
            $query->limit($limit);
        }

        $totalFound = (clone $query)->count();
        $this->info("Found {$totalFound} unattributed worklog(s) matching criteria.");

        if ($totalFound === 0) {
            $this->info('Nothing to reconcile.');
            return Command::SUCCESS;
        }

        // Group by connection
        $worklogs = $query->with('connection', 'client')->get();
        $byConnection = $worklogs->groupBy('pm_connection_id');

        $overallPromotedCount = 0;
        $overallPromotedSeconds = 0;
        $statsByEmployee = [];

        foreach ($byConnection as $connId => $connWorklogs) {
            /** @var PmConnection|null $connection */
            $connection = $connWorklogs->first()->connection ?? PmConnection::find($connId);

            $this->line("\nProcessing Connection: <comment>" . ($connection?->name ?? "Connection #{$connId}") . "</comment> ({$connWorklogs->count()} worklogs)");

            $tempoClient = TempoClient::forConnection($connection);
            if (! $tempoClient || ! $tempoClient->isConfigured()) {
                $this->warn("Skipping connection #{$connId}: No Tempo API token configured.");
                continue;
            }

            $allJiraIds = $connWorklogs->pluck('external_worklog_id')->unique()->all();

            $this->line("Resolving " . count($allJiraIds) . " unique Jira worklog IDs via Tempo API...");
            $resolvedAuthors = $tempoClient->resolveJiraWorklogAuthors($allJiraIds);
            $this->info("Tempo mapped " . count($resolvedAuthors) . " worklog(s) to authentic authors.");

            $connectionPromoted = 0;

            foreach ($connWorklogs as $wl) {
                $jiraId = (int) $wl->external_worklog_id;
                $tempoInfo = $resolvedAuthors[$jiraId] ?? null;

                if (! $tempoInfo || empty($tempoInfo['author_account_id'])) {
                    continue;
                }

                $authorAccountId = $tempoInfo['author_account_id'];
                $resolvedUserId = IdentityResolverService::resolveAuthoritativeAccountId('jira', $authorAccountId);

                if (! $resolvedUserId) {
                    continue;
                }

                $user = User::find($resolvedUserId);
                $userName = $user?->name ?? "User #{$resolvedUserId}";

                $metadata = $wl->attribution_metadata_json ?? [];
                $metadata['tempo_worklog_id']        = $tempoInfo['tempo_worklog_id'];
                $metadata['tempo_author_account_id'] = $authorAccountId;
                $metadata['tempo_reconciled_at']      = now()->toIso8601String();

                if (! $dryRun) {
                    $wl->update([
                        'user_id'                   => $resolvedUserId,
                        'author_name'               => $userName,
                        'external_author_id'        => $authorAccountId,
                        'attribution_source'        => 'tempo_verified',
                        'attribution_metadata_json' => $metadata,
                    ]);
                }

                $connectionPromoted++;
                $overallPromotedCount++;
                $overallPromotedSeconds += (int) $wl->time_spent_seconds;

                if (! isset($statsByEmployee[$userName])) {
                    $statsByEmployee[$userName] = [
                        'user_id' => $resolvedUserId,
                        'count'   => 0,
                        'seconds' => 0,
                    ];
                }
                $statsByEmployee[$userName]['count']++;
                $statsByEmployee[$userName]['seconds'] += (int) $wl->time_spent_seconds;
            }

            $this->info("Promoted {$connectionPromoted} worklog(s) on " . ($connection?->name ?? "Connection #{$connId}") . ".");
        }

        // Summary Table
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->info('                  RECONCILIATION SUMMARY                           ');
        $this->info('═══════════════════════════════════════════════════════════════════');

        $rows = [];
        foreach ($statsByEmployee as $name => $stat) {
            $hours = round($stat['seconds'] / 3600, 2);
            $rows[] = [
                $name,
                $stat['user_id'],
                $stat['count'],
                "{$hours}h",
                'tempo_verified',
            ];
        }

        if (! empty($rows)) {
            $this->table(['Employee', 'User ID', 'Promoted Logs', 'Hours', 'Status'], $rows);
        } else {
            $this->warn('No worklogs could be promoted.');
        }

        $overallHours = round($overallPromotedSeconds / 3600, 2);
        $remainingUnattributed = $totalFound - $overallPromotedCount;

        $this->info("Total Promoted: {$overallPromotedCount} logs ({$overallHours}h)");
        $this->info("Remaining Unattributed in Scope: {$remainingUnattributed} logs");

        return Command::SUCCESS;
    }
}
