<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\User;
use App\Services\PM\IdentityResolverService;
use App\Services\PM\Providers\JiraProvider;
use App\Services\PM\Providers\TempoClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileCustomerWorklogsCommand extends Command
{
    protected $signature = 'pm:reconcile-customer-worklogs
        {--client= : Specific Client ID to reconcile}
        {--connection= : Specific PM Connection ID to reconcile}
        {--from= : Start date for worklog filtering (YYYY-MM-DD)}
        {--to= : End date for worklog filtering (YYYY-MM-DD)}
        {--limit= : Maximum number of worklogs to process}
        {--force : Force re-verification of all worklogs in range even if previously verified}
        {--sync-tempo : Ingest/sync work items directly from Tempo API for date range}
        {--dry-run : Simulate resolution without modifying database}';

    protected $description = 'Authoritatively reconcile unattributed, legacy, and Tempo-bot Jira worklogs using the Tempo API';

    public function handle(): int
    {
        $this->info('Starting Authoritative Tempo Worklog Reconciliation...');

        $clientId = $this->option('client');
        $connectionId = $this->option('connection');
        $fromDate = $this->option('from');
        $toDate = $this->option('to');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $force = (bool) $this->option('force');
        $syncTempo = (bool) $this->option('sync-tempo');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No database changes will be committed.');
        }

        // Backfill any missing canonical_worklog_key across pm_worklogs
        $missingCanonical = PmWorklog::whereNull('canonical_worklog_key')->whereNotNull('external_worklog_id')->get();
        if ($missingCanonical->isNotEmpty()) {
            $this->line("Backfilling {$missingCanonical->count()} worklog(s) with missing canonical keys...");
            foreach ($missingCanonical as $wl) {
                $siteIdentity = $wl->connection?->getJiraSiteIdentity() ?? 'technopath.atlassian.net';
                $key = PmWorklog::generateCanonicalKey($siteIdentity, $wl->external_worklog_id);
                if (! $dryRun) {
                    $wl->updateQuietly(['canonical_worklog_key' => $key]);
                }
            }
        }

        // Optional step: Sync active items directly from Tempo API for the date range
        if ($syncTempo) {
            $this->syncActiveItemsFromTempo($connectionId, $fromDate, $toDate, $dryRun);
        }

        // Build base query: find all worklogs needing Tempo resolution
        $query = PmWorklog::query()
            ->whereNotNull('external_worklog_id');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereIn('attribution_source', ['unattributed', 'legacy_attributed'])
                  ->orWhereNull('attribution_source')
                  ->orWhere('attribution_source', '')
                  ->orWhere('author_name', 'LIKE', '%timesheets by tempo%')
                  ->orWhere('external_author_id', '557058:295406f3-a1fc-4733-b906-dd15d021bd79');
            })
            ->where(function ($q) {
                $q->whereNotIn('attribution_source', ['tempo_verified', 'jira_verified'])
                  ->orWhereNull('attribution_metadata_json->tempo_worklog_id');
            });
        }

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
        $this->info("Found {$totalFound} worklog(s) matching criteria.");

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

                $attributionSource = 'tempo_verified';
                if ($wl->user_id && $wl->user_id !== $resolvedUserId) {
                    $attributionSource = 'authoritative_correction';
                    $metadata['previous_user_id'] = $wl->user_id;
                    $metadata['corrected_user_id'] = $resolvedUserId;
                    $metadata['corrected_at'] = now()->toIso8601String();
                    $metadata['reason'] = 'Authoritative Tempo author reconciliation';
                }

                if (! $dryRun) {
                    $wl->update([
                        'user_id'                   => $resolvedUserId,
                        'author_name'               => $userName,
                        'external_author_id'        => $authorAccountId,
                        'attribution_source'        => $attributionSource,
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
        $this->info("Remaining in Scope: {$remainingUnattributed} logs");

        return Command::SUCCESS;
    }

    /**
     * Query Tempo API directly for all worklogs in range and ensure parent PmWorkItems are synced.
     */
    protected function syncActiveItemsFromTempo(?int $connectionId, ?string $fromDate, ?string $toDate, bool $dryRun): void
    {
        $from = $fromDate ?? now()->startOfMonth()->toDateString();
        $to = $toDate ?? now()->endOfMonth()->toDateString();

        $connections = PmConnection::where('provider', 'jira')
            ->where('is_active', true)
            ->when($connectionId, fn($q) => $q->where('id', $connectionId))
            ->get();

        foreach ($connections as $conn) {
            $tempoClient = TempoClient::forConnection($conn);
            if (! $tempoClient || ! $tempoClient->isConfigured()) {
                continue;
            }

            $this->line("Fetching Tempo worklogs from {$from} to {$to} for connection #{$conn->id}...");
            $tempoLogs = $tempoClient->getWorklogsForDateRange($from, $to);
            $this->info("Retrieved " . count($tempoLogs) . " Tempo worklog(s).");

            if (empty($tempoLogs)) {
                continue;
            }

            $issueIds = collect($tempoLogs)
                ->pluck('issue.id')
                ->filter()
                ->unique()
                ->all();

            $this->line("Found " . count($issueIds) . " distinct Jira issue IDs in Tempo worklogs.");

            $workItems = PmWorkItem::whereIn('external_item_id', $issueIds)->get();
            $jiraProvider = app(JiraProvider::class);

            foreach ($workItems as $item) {
                if ($dryRun) {
                    $this->line("[DRY-RUN] Would sync worklogs for {$item->external_item_key}");
                    continue;
                }

                try {
                    $jiraProvider->syncWorklogs($item);
                } catch (\Throwable $e) {
                    Log::warning("Reconcile: Failed to sync worklogs for {$item->external_item_key}: " . $e->getMessage());
                }
            }
        }
    }
}
