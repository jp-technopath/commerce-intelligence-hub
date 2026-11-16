<?php

use App\Models\PmConnection;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Services\PM\Providers\JiraProvider;
use App\Services\PM\Providers\TempoClient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ensure all active Jira connections have the authoritative Tempo API token configured
        try {
            $connections = PmConnection::where('provider', 'jira')->get();
            $token = config('services.tempo.token') ?: env('TEMPO_API_TOKEN') ?: '1YL4GtHOU5WKEKp4j1RVGTDPsbhDJQ-us';

            foreach ($connections as $connection) {
                if (! $connection->hasTempoToken()) {
                    $connection->tempo_api_token = $token;
                    $connection->save();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Migration reconcile_all_clients_tempo_worklogs token seeding warning: ' . $e->getMessage());
        }

        // 2. Ensure canonical_worklog_key is backfilled for any NULL entries
        try {
            $missingCanonical = PmWorklog::whereNull('canonical_worklog_key')->whereNotNull('external_worklog_id')->get();
            foreach ($missingCanonical as $wl) {
                $siteIdentity = $wl->connection?->getJiraSiteIdentity() ?? 'technopath.atlassian.net';
                $key = PmWorklog::generateCanonicalKey($siteIdentity, $wl->external_worklog_id);
                $wl->updateQuietly(['canonical_worklog_key' => $key]);
            }
        } catch (\Throwable $e) {
            Log::warning('Migration reconcile_all_clients_tempo_worklogs canonical backfill warning: ' . $e->getMessage());
        }

        // 3. Pre-sync key November Jira work items that have Tempo logs to guarantee all worklogs exist in DB
        try {
            $jiraProvider = app(JiraProvider::class);
            $keyNovemberItems = [
                'CMBR2-2258', 'CMBR2-2259', 'CMBR2-2262',
                'SUP-9595', 'SUP-9652', 'SUP-9659', 'SUP-9673',
                'TRAN-1319', 'TRAN-1357', 'TRAN-1361', 'TRAN-268',
                'USG-1227', 'USG-1238', 'USG-20',
            ];

            $workItems = PmWorkItem::whereIn('external_item_key', $keyNovemberItems)->get();
            foreach ($workItems as $item) {
                try {
                    $jiraProvider->syncWorklogs($item);
                } catch (\Throwable $e) {
                    Log::warning("Migration: Worklog sync for {$item->external_item_key} warning: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Migration reconcile_all_clients_tempo_worklogs work item sync warning: ' . $e->getMessage());
        }

        // 4. Authoritatively reconcile all clients' November 2026 worklogs using Tempo v4 API
        try {
            Artisan::call('pm:reconcile-customer-worklogs', [
                '--from' => '2026-11-01',
                '--to'   => '2026-11-30',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Migration reconcile_all_clients_tempo_worklogs command run warning: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
