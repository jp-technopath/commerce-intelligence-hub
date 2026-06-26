<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\SyncLog;
use App\Enums\SyncStatus;
use App\Services\Connectors\AdobeCommerceConnector;
use App\Services\Connectors\ClarityConnector;
use App\Services\Connectors\KlaviyoConnector;
use App\Services\Connectors\NewRelicConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TriggerIntegrationSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        public readonly Integration $integration,
        public readonly int $numOfDays = 1,
    ) {
        $this->onQueue('syncs');
    }

    public function handle(): void
    {
        $syncLog = SyncLog::create([
            'integration_id' => $this->integration->id,
            'status'         => SyncStatus::Running,
            'started_at'     => now(),
        ]);

        try {
            $type = $this->integration->integration_type?->value;

            match ($type) {
                'ga4'             => (new \App\Services\Connectors\GA4Connector($this->integration))->sync($syncLog),
                'clarity'         => (new ClarityConnector($this->integration))->sync($syncLog, $this->numOfDays),
                'adobe_commerce'  => (new AdobeCommerceConnector($this->integration))->sync($syncLog, $this->numOfDays),
                'new_relic'       => (new NewRelicConnector($this->integration))->sync($syncLog),
                'klaviyo'         => (new KlaviyoConnector($this->integration))->sync($syncLog),
                default           => $syncLog->update([
                    'status'            => SyncStatus::Skipped,
                    'completed_at'      => now(),
                    'error_message'     => "Connector for [{$type}] not yet implemented.",
                    'records_processed' => 0,
                ]),
            };

            // Update integration last_sync_at
            $this->integration->update(['last_sync_at' => now()]);

        } catch (\Throwable $e) {
            $syncLog->update([
                'status'        => SyncStatus::Failed,
                'completed_at'  => now(),
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);

            Log::error('TriggerIntegrationSync failed', [
                'integration_id' => $this->integration->id,
                'error'          => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('TriggerIntegrationSync job permanently failed', [
            'integration_id' => $this->integration->id,
            'error'          => $exception->getMessage(),
        ]);
    }
}
