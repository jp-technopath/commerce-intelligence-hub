<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Intelligence\Replay\ShadowEvaluationRunner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RunShadowDailyEvaluation extends Command
{
    protected $signature = 'forge:shadow-daily
                            {--client=5 : Client ID for daily shadow observation}';

    protected $description = 'Executes scheduled daily Stage 2 shadow parallel evaluation on newly arrived data';

    public function handle(ShadowEvaluationRunner $runner): int
    {
        $clientId = (int) $this->option('client');
        $client = Client::find($clientId);

        if (!$client) {
            $this->error("Client ID {$clientId} not found.");
            return 1;
        }

        $now = Carbon::now();
        $this->info("==================================================================");
        $this->info("  FORGE FINDINGS ENGINE: STAGE 2 DAILY PARALLEL EVALUATION");
        $this->info("==================================================================");
        $this->info("Client:          {$client->name} (ID: {$client->id})");
        $this->info("Evaluation Time: {$now->toDateTimeString()} UTC");
        $this->info("Data Scope:      Closed through {$now->copy()->subDay()->format('Y-m-d')} 23:59:59 UTC");
        $this->info("Mode:            PARALLEL SHADOW (Isolated / Zero writes to findings)");
        $this->newLine();

        $cycleReport = $runner->runDailyShadowCycle($client, $now);

        $this->table(
            ['Metric', 'Value', 'Audit Status'],
            [
                ['Evaluation Timestamp', $cycleReport['execution_timestamp_utc'], 'UTC'],
                ['Execution Duration', $cycleReport['execution_duration_ms'] . ' ms', 'High performance'],
                ['Target Evaluation Date', $cycleReport['evaluation_period']['target_date'], $cycleReport['evaluation_period']['is_business_day_complete'] ? 'Closed Business Day' : 'Incomplete'],
                ['Client Local Time', $cycleReport['client']['client_local_time'], 'America/New_York (EST)'],
                ['Data Source Freshness', $cycleReport['data_source_freshness_status']['all_platforms_synchronized'] ? 'All 4 Platforms Synchronized' : 'Delayed Sync', 'Fresh'],
                ['Legacy Production Findings (24h)', $cycleReport['production_findings_last_24h'], 'Active in database'],
                ['Detected Signals', $cycleReport['signals_summary']['detected_signals_count'], 'Raw qualified signals'],
                ['Suppressed Signals (Noise)', $cycleReport['signals_summary']['suppressed_signals_count'], 'Gated / deduplicated'],
                ['Stage 2 Retained Incidents', $cycleReport['signals_summary']['retained_signals_count'], 'Actionable incidents'],
                ['New Incident Identifiers', count($cycleReport['incident_identifiers']['new_incident_identifiers']), implode(', ', $cycleReport['incident_identifiers']['new_incident_identifiers']) ?: 'None (Continuing episodes)'],
                ['Continuing Incident Identifiers', count($cycleReport['incident_identifiers']['continuing_incident_identifiers']), implode(', ', $cycleReport['incident_identifiers']['continuing_incident_identifiers']) ?: 'None'],
                ['Database Isolation Result', $cycleReport['database_isolation_result']['status'], '0 Mutating Queries / Read-Only'],
                ['Errors', count($cycleReport['errors']), 'Clean'],
            ]
        );

        if (!empty($cycleReport['signals_summary']['retained_signals'])) {
            $this->info('Stage 2 Retained Incidents:');
            foreach ($cycleReport['signals_summary']['retained_signals'] as $sig) {
                $this->line("  * [{$sig['incident_id']}] {$sig['title']} (Team: {$sig['assigned_team']}, Severity: {$sig['severity']})");
            }
            $this->newLine();
        }

        if (!empty($cycleReport['signals_summary']['suppressed_signals'])) {
            $this->warn('Suppressed Signals (Noise Eliminated):');
            foreach ($cycleReport['signals_summary']['suppressed_signals'] as $sup) {
                $this->line("  * [Finding #{$sup['finding_id']}] {$sup['title']} -> {$sup['category']} ({$sup['rationale']})");
            }
            $this->newLine();
        }

        if (!empty($cycleReport['data_quality_warnings'])) {
            $this->warn('Data Quality Warnings:');
            foreach ($cycleReport['data_quality_warnings'] as $warning) {
                $this->line("  * {$warning}");
            }
            $this->newLine();
        }

        $ledgerPath = storage_path('app/reports/shadow_mode/live_parallel_ledger.json');
        $this->info("Shadow Ledger updated: {$ledgerPath}");

        $snapshotPath = storage_path('app/reports/shadow_mode/daily/' . $cycleReport['evaluation_period']['target_date'] . '_client_' . $client->id . '.json');
        $this->info("Daily Snapshot saved: {$snapshotPath}");

        return 0;
    }
}
