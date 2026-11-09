<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Intelligence\BusinessOpportunityEngine;
use Illuminate\Console\Command;

class RunBusinessOpportunitiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forge:opportunities-run
                            {--client= : The ID of the specific client to evaluate}
                            {--dry-run : Simulate discovery without saving findings to the database}
                            {--format=table : Output format: table or json}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover actionable customer growth opportunities and business risks using purchase pattern intelligence';

    /**
     * Execute the console command.
     */
    public function handle(BusinessOpportunityEngine $engine): int
    {
        $clientId = $this->option('client');
        $dryRun = (bool) $this->option('dry-run');
        $format = $this->option('format');

        $query = Client::query()->where('status', 'active');
        if ($clientId) {
            $query->where('id', $clientId);
        }

        $clients = $query->get();

        if ($clients->isEmpty()) {
            $this->error('No matching active client(s) found.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode. No findings will be saved to the database.');
        }

        $allResults = [];
        $grandTotals = [
            'raw'         => 0,
            'coordinated' => 0,
            'created'     => 0,
            'suppressed'  => 0,
        ];

        foreach ($clients as $client) {
            $this->line("<fg=cyan>Evaluating Client #{$client->id}: {$client->name} ({$client->industry})</>");

            $result = $engine->evaluateClient($client, $dryRun);
            $allResults[] = $result;

            if ($result['status'] === 'skipped_insufficient_data') {
                $this->warn("  -> Skipped: {$result['message']}");
                continue;
            }

            $grandTotals['raw'] += $result['raw_count'];
            $grandTotals['coordinated'] += $result['coordinated_count'];
            $grandTotals['created'] += $result['created_count'];
            $grandTotals['suppressed'] += $result['suppressed_count'];

            $this->info("  -> Raw Candidates: {$result['raw_count']} | Coordinated: {$result['coordinated_count']} | " .
                ($dryRun ? 'Modeled' : 'Created') . ": {$result['created_count']} | Suppressed: {$result['suppressed_count']}");

            if (! empty($result['findings'])) {
                $tableRows = [];
                foreach ($result['findings'] as $f) {
                    $valStr = $f['estimated_revenue_impact'] !== null
                        ? '$' . number_format($f['estimated_revenue_impact'], 2) . ' (' . $f['impact_nature'] . ')'
                        : 'N/A';

                    $tableRows[] = [
                        'Pillar'     => $f['finding_pillar'],
                        'Detector'   => class_basename($f['detector_key']),
                        'Account'    => $f['account_label'],
                        'Impact'     => $valStr,
                        'Confidence' => round($f['confidence_score'] * 100) . '% (' . $f['identity_confidence'] . ')',
                        'Priority'   => $f['priority_score'],
                        'Role'       => $f['target_role'],
                    ];
                }
                $this->table(['Pillar', 'Detector', 'Account', 'Impact', 'Confidence', 'Priority', 'Role'], $tableRows);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Evaluation complete across %d client(s). Total: %d raw candidates -> %d coordinated -> %d %s (suppressed: %d duplicate/active episodes).',
            $clients->count(),
            $grandTotals['raw'],
            $grandTotals['coordinated'],
            $grandTotals['created'],
            $dryRun ? 'modeled' : 'persisted',
            $grandTotals['suppressed']
        ));

        return self::SUCCESS;
    }
}
