<?php

namespace Tests\Unit\Intelligence;

use App\Models\Client;
use App\Services\Intelligence\DataQualification\DataCompletenessGuard;
use App\Services\Intelligence\DataQualification\DataQualificationService;
use App\Services\Intelligence\DataQualification\SampleSufficiencyEvaluator;
use App\Services\Intelligence\DataQualification\TrafficQualityClassifier;
use Carbon\Carbon;
use Tests\TestCase;

class DataQualificationServiceTest extends TestCase
{
    private DataQualificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DataQualificationService();
    }

    public function test_sample_sufficiency_retains_high_value_low_volume_b2b_orders(): void
    {
        $client = new Client();
        $client->id = 5; // Goldkamp
        $client->monitoring_config = ['business_model' => 'b2b'];

        $evaluator = new SampleSufficiencyEvaluator();
        $result = $evaluator->evaluate($client, 'adobe_commerce.orders', 2.0, 5.0, [
            'orders' => 2,
            'revenue' => 4500.0,
        ]);

        $this->assertTrue($result['is_sufficient']);
        $this->assertTrue($result['is_low_sample_size']);
        $this->assertStringContainsString('retained due to high-value B2B transaction significance', $result['rationale']);
    }

    public function test_incomplete_current_day_is_excluded_from_evaluation(): void
    {
        $guard = new DataCompletenessGuard();
        $now = Carbon::now();
        $from = $now->copy()->subDays(7);
        $to = $now->copy(); // Today

        $result = $guard->evaluate($from, $to, []);

        $this->assertFalse($result['is_complete']);
        $this->assertTrue($result['includes_current_day']);
        $this->assertStringContainsString('Evaluation window includes current partial day', $result['current_day_notice']);
    }

    public function test_backward_compatible_data_quality_metadata_defaults(): void
    {
        $guard = new DataCompletenessGuard();
        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-10-07');

        // Records without metadata_json keys (older/legacy records)
        $legacyRecords = [
            ['id' => 1, 'traffic' => 100],
            ['id' => 2, 'traffic' => 120, 'metadata_json' => []],
            ['id' => 3, 'traffic' => 110, 'metadata_json' => ['data_status' => 'estimated', 'is_verified' => false]],
        ];

        $result = $guard->evaluate($from, $to, $legacyRecords, 3);

        $this->assertEquals(2, $result['quality_breakdown']['verified']);
        $this->assertEquals(1, $result['quality_breakdown']['estimated']);
        $this->assertTrue($result['has_estimated_data']);
        $this->assertLessThan(1.0, $result['confidence_modifier']);
    }

    public function test_traffic_quality_classifier_preserves_contractual_cvr_while_flagging_investigation(): void
    {
        $classifier = new TrafficQualityClassifier();

        // Traffic surges by +900% dominated by direct traffic
        $result = $classifier->classify(
            6373, // current sessions
            628,  // previous sessions
            ['direct_share_pct' => 91.7, 'direct_sessions' => 5844],
            10    // orders
        );

        $this->assertTrue($result['requires_investigation']);
        $this->assertEquals('suspicious_traffic_surge_requiring_investigation', $result['classification']);
        $this->assertStringContainsString('NOT confirmed automated/bot activity', $result['rationale']);

        // Verify contractual conversion rate is strictly preserved as orders / total sessions
        $expectedContractualCvr = (10 / 6373) * 100;
        $this->assertEquals(round($expectedContractualCvr, 4), $result['contractual_conversion_rate']);

        // Verify auxiliary metrics are present for diagnostic assistance
        $this->assertTrue($result['auxiliary_metrics']['has_auxiliary_metrics']);
        $this->assertGreaterThan($result['contractual_conversion_rate'], $result['auxiliary_metrics']['qualified_conversion_rate_diagnostic']);
    }
}
