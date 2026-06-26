<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Intelligence\AIAnalyst;
use App\Services\Intelligence\ChangeDetectionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunNightlyAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600; // 10 min for all clients

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $clients = Client::where('status', 'active')->get();
        $engine  = new ChangeDetectionEngine();
        $analyst = new AIAnalyst();

        $totalFindings = 0;

        foreach ($clients as $client) {
            try {
                // 1. Detect changes, generate Finding records
                $newFindings = $engine->run($client);
                $totalFindings += $newFindings;

                // 2. Run AI analysis on any new findings
                if ($newFindings > 0) {
                    $client->findings()
                        ->whereNull('id')  // reload to get newly created
                        ->orWhere(function ($q) use ($client) {
                            $q->where('client_id', $client->id)
                              ->whereDoesntHave('recommendations')
                              ->where('detected_at', '>=', now()->subHours(2));
                        })
                        ->get()
                        ->each(fn ($finding) => $analyst->analyse($finding));
                }

                Log::info('RunNightlyAnalysis: client processed', [
                    'client_id'    => $client->id,
                    'client_name'  => $client->name,
                    'new_findings' => $newFindings,
                ]);

            } catch (\Exception $e) {
                Log::error('RunNightlyAnalysis: client error', [
                    'client_id' => $client->id,
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        Log::info('RunNightlyAnalysis: complete', [
            'clients_processed' => $clients->count(),
            'total_findings'    => $totalFindings,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RunNightlyAnalysis: job permanently failed', [
            'message' => $exception->getMessage(),
        ]);
    }
}
