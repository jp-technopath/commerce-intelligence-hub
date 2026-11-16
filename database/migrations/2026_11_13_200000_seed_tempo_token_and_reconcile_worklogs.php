<?php

use App\Models\PmConnection;
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
            Log::warning('Migration seed_tempo_token failed to save connection token: ' . $e->getMessage());
        }

        // 2. Authoritatively reconcile Goldkamp / active month unattributed Jira worklogs using Tempo v4 API
        try {
            Artisan::call('pm:reconcile-customer-worklogs', [
                '--client' => 5,
                '--from'   => now()->subMonths(1)->startOfMonth()->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Migration seed_tempo_token failed to reconcile worklogs: ' . $e->getMessage());
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
