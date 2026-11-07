<?php

namespace Tests\Unit\Intelligence;

use App\Enums\IntegrationStatus;
use App\Models\Client;
use App\Models\Integration;
use App\Models\BehavioralMetric;
use App\Services\Connectors\ClarityConnector;
use App\Enums\ClientStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClarityIngestionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_subtract_downstream_metrics_isolates_single_day_volume(): void
    {
        $connector = new ClarityConnector();

        $threeDayCumulative = [
            'traffic'       => 5151,
            'script_errors' => 11210,
            'rage_clicks'   => 45,
            'dead_clicks'   => 30,
        ];

        // Downstream records (Nov 3 and Nov 4)
        $downstreamNov3 = (object) [
            'traffic'       => 2500,
            'script_errors' => 6000,
            'rage_clicks'   => 20,
            'dead_clicks'   => 15,
        ];

        $downstreamNov4 = (object) [
            'traffic'       => 2309,
            'script_errors' => 5096,
            'rage_clicks'   => 15,
            'dead_clicks'   => 10,
        ];

        $isolatedNov2 = $connector->subtractDownstreamMetrics(
            $threeDayCumulative,
            collect([$downstreamNov3, $downstreamNov4])
        );

        $this->assertEquals(342, $isolatedNov2['traffic']);
        $this->assertEquals(114, $isolatedNov2['script_errors']);
        $this->assertEquals(10, $isolatedNov2['rage_clicks']);
        $this->assertEquals(5, $isolatedNov2['dead_clicks']);
    }

    public function test_average_metrics_over_days_prevents_multi_day_dump_into_single_day(): void
    {
        $connector = new ClarityConnector();

        $threeDayCumulative = [
            'traffic'       => 3000,
            'script_errors' => 300,
            'rage_clicks'   => 90,
        ];

        $averaged = $connector->averageMetricsOverDays($threeDayCumulative, 3);

        $this->assertEquals(1000, $averaged['traffic']);
        $this->assertEquals(100, $averaged['script_errors']);
        $this->assertEquals(30, $averaged['rage_clicks']);
    }

    public function test_fetch_for_date_range_preserves_existing_single_day_record(): void
    {
        $client = Client::create([
            'name'          => 'Client Clarity Test',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
        ]);

        $integration = Integration::create([
            'client_id'        => $client->id,
            'integration_type' => \App\Enums\IntegrationType::Clarity,
            'status'           => IntegrationStatus::Active,
            'credentials_json' => [
                'project_id'   => 'fake_proj',
                'bearer_token' => 'fake_token_12345',
            ],
        ]);

        $existingDate = now()->subDays(1)->startOfDay();

        BehavioralMetric::create([
            'client_id'     => $client->id,
            'date'          => $existingDate->toDateTimeString(),
            'traffic'       => 342,
            'script_errors' => 114,
            'friction_score'=> 10.5,
            'metadata_json' => [
                'num_of_days'          => 1,
                'is_rolling_aggregate' => false,
            ],
        ]);

        $connector = new ClarityConnector($integration);
        $result = $connector->fetchForDateRange($existingDate, $existingDate);

        // Result 1 indicates preserved existing record without overwriting
        $this->assertEquals(1, $result);

        $record = BehavioralMetric::where('client_id', $client->id)
            ->where('date', $existingDate->toDateTimeString())
            ->first();

        $this->assertEquals(342, $record->traffic);
        $this->assertEquals(114, $record->script_errors);
    }
}
