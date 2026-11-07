<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Finding;
use App\Services\Intelligence\AIAnalyst;
use App\Services\Intelligence\ChangeDetectionEngine;
use Illuminate\Console\Command;

class RunLiveFindingsAnalysis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forge:findings-run 
                            {--client= : The ID of the specific client to run analysis for}
                            {--ai : Automatically generate AI investigation analysis and recommendations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute live Stage 2 findings intelligence engine and populate findings dashboard';

    /**
     * Execute the console command.
     */
    public function handle(ChangeDetectionEngine $engine, AIAnalyst $aiAnalyst): int
    {
        $clientId = $this->option('client');
        $runAi = (bool) $this->option('ai');

        $query = Client::query();
        if ($clientId) {
            $query->where('id', $clientId);
        }

        $clients = $query->get();

        if ($clients->isEmpty()) {
            $this->error("No matching client(s) found.");
            return self::FAILURE;
        }

        $this->info("Starting Live Stage 2 Findings Analysis across " . $clients->count() . " client(s)...");

        $totalNew = 0;
        foreach ($clients as $client) {
            $this->line("<fg=cyan>Evaluating Client #{$client->id}: {$client->name}</>");
            $beforeCount = Finding::where('client_id', $client->id)->count();

            $newFindingsCount = $engine->run($client);
            $totalNew += $newFindingsCount;

            $this->info("  -> Generated {$newFindingsCount} new synthesized incident(s).");

            if ($runAi) {
                $unanalysedFindings = Finding::where('client_id', $client->id)
                    ->whereDoesntHave('recommendations')
                    ->get();

                foreach ($unanalysedFindings as $finding) {
                    $this->line("  -> Generating AI analysis for Finding #{$finding->id}: {$finding->title}...");
                    $rec = $aiAnalyst->analyse($finding);
                    if ($rec) {
                        $this->line("     <fg=green>✓ AI recommendation generated</>");
                    } else {
                        $this->line("     <fg=yellow>⚠ AI recommendation could not be generated (provider skipped or unavailable)</>");
                    }
                }
            }
        }

        $this->newLine();
        $this->info("Completed Live Findings Analysis. Total new findings created: {$totalNew}");

        // Display current findings summary table
        $allFindings = Finding::with('client')->latest('id')->get();
        if ($allFindings->isNotEmpty()) {
            $this->table(
                ['ID', 'Client', 'Category', 'Severity', 'Title', 'Fingerprint', 'Status'],
                $allFindings->map(fn ($f) => [
                    $f->id,
                    $f->client?->name ?? $f->client_id,
                    $f->finding_category?->label() ?? $f->finding_category,
                    $f->severity?->label() ?? $f->severity,
                    $f->title,
                    $f->fingerprint,
                    $f->status?->label() ?? $f->status,
                ])
            );
        }

        return self::SUCCESS;
    }
}
