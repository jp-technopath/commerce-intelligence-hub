<?php

namespace Tests\Unit\Intelligence;

use App\Models\Client;
use App\Models\BehavioralMetric;
use App\Models\Finding;
use App\Services\Intelligence\ChangeDetectionEngine;
use App\Enums\ClientStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScriptErrorNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_traffic_surge_does_not_trigger_script_error_spike_when_rate_is_flat(): void
    {
        $client = Client::create([
            'name'          => 'Client Flat Error Rate',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
            'monitoring_config' => [
                'enabled_metrics' => ['script_errors'],
                'comparison_periods' => [
                    'behavioral' => 7,
                ],
                'thresholds' => [
                    'script_errors_increase' => 0.25, // 25% increase threshold
                ],
            ],
        ]);

        // Prior 7 days: 100 errors across 1000 sessions (0.10 errors/session)
        for ($i = 7; $i <= 13; $i++) {
            BehavioralMetric::create([
                'client_id'     => $client->id,
                'date'          => now()->subDays($i)->startOfDay()->toDateTimeString(),
                'traffic'       => 142,
                'script_errors' => 14,
            ]);
        }

        // Current 7 days: 200 errors across 2000 sessions (0.10 errors/session - identical rate!)
        for ($i = 0; $i <= 6; $i++) {
            BehavioralMetric::create([
                'client_id'     => $client->id,
                'date'          => now()->subDays($i)->startOfDay()->toDateTimeString(),
                'traffic'       => 285,
                'script_errors' => 28,
            ]);
        }

        $engine = app(ChangeDetectionEngine::class);
        $newFindings = $engine->run($client);

        $finding = Finding::where('client_id', $client->id)
            ->where('finding_type', 'script_errors_increase')
            ->first();

        $this->assertNull($finding, 'Flat script error rate should NOT trigger finding despite doubling of raw errors.');
    }

    public function test_true_script_error_rate_spike_triggers_finding_with_normalized_metadata(): void
    {
        $client = Client::create([
            'name'          => 'Client Error Spike',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
            'monitoring_config' => [
                'enabled_metrics' => ['script_errors'],
                'comparison_periods' => [
                    'behavioral' => 7,
                ],
                'thresholds' => [
                    'script_errors_increase' => 0.25,
                ],
            ],
        ]);

        // Prior 7 days: 100 errors across 1000 sessions (0.10 errors/session)
        for ($i = 7; $i <= 13; $i++) {
            BehavioralMetric::create([
                'client_id'     => $client->id,
                'date'          => now()->subDays($i)->startOfDay()->toDateTimeString(),
                'traffic'       => 142,
                'script_errors' => 14,
            ]);
        }

        // Current 7 days: 500 errors across 1000 sessions (0.50 errors/session - 400% surge!)
        for ($i = 0; $i <= 6; $i++) {
            BehavioralMetric::create([
                'client_id'     => $client->id,
                'date'          => now()->subDays($i)->startOfDay()->toDateTimeString(),
                'traffic'       => 142,
                'script_errors' => 71,
            ]);
        }

        $engine = app(ChangeDetectionEngine::class);
        $newFindings = $engine->run($client);

        $finding = Finding::where('client_id', $client->id)
            ->where('finding_type', 'script_errors_increase')
            ->first();

        $this->assertNotNull($finding, 'Legitimate error rate surge should trigger finding.');
        $this->assertArrayHasKey('current_rate', $finding->metadata_json);
        $this->assertArrayHasKey('previous_rate', $finding->metadata_json);
        $this->assertGreaterThan($finding->metadata_json['previous_rate'], $finding->metadata_json['current_rate']);
    }
}
