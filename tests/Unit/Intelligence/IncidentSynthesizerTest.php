<?php

namespace Tests\Unit\Intelligence;

use App\Services\Intelligence\Synthesis\IncidentSynthesizer;
use App\Services\Intelligence\Synthesis\RelationshipClassifier;
use Carbon\Carbon;
use Tests\TestCase;

class IncidentSynthesizerTest extends TestCase
{
    private IncidentSynthesizer $synthesizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->synthesizer = new IncidentSynthesizer();
    }

    public function test_packaging_tapes_js_error_and_direct_traffic_surge_remain_independent_incidents(): void
    {
        $clientId = 5;
        $detectedDate = Carbon::parse('2026-11-05 02:00:15');

        $rawSignals = [
            [
                'family' => 'technical.script_error',
                'scope_type' => 'page',
                'scope_target' => 'tapes_packaging_tapes',
                'title' => 'JavaScript error rate increased on packaging tapes (+767.3% per session)',
                'evidence' => [
                    'affected_url' => 'https://www.tgoldkamp.com/tapes/packaging-tapes.html',
                    'exception_type' => 'cart_item_options',
                ],
            ],
            [
                'family' => 'traffic.volume_surge',
                'scope_type' => 'channel',
                'scope_target' => 'direct',
                'title' => 'Direct traffic volume surged (+915% volume with flat conversion)',
                'evidence' => [
                    'dominant_channel' => 'direct',
                    'direct_share_pct' => 91.7,
                ],
            ],
        ];

        $result = $this->synthesizer->synthesize($clientId, $rawSignals, $detectedDate);

        // Verify two separate incidents are emitted, NOT merged into one
        $this->assertCount(2, $result['incidents']);

        $incidentFamilies = array_column($result['incidents'], 'family');
        $this->assertContains('technical.script_error', $incidentFamilies);
        $this->assertContains('traffic.volume_surge', $incidentFamilies);

        // Verify both are marked as independently actionable
        foreach ($result['incidents'] as $inc) {
            $this->assertTrue($inc['is_independently_actionable']);
        }

        // Verify cross relationship is classified as independent
        $this->assertNotEmpty($result['cross_relationships']);
        $crossRel = $result['cross_relationships'][0];
        $this->assertEquals(RelationshipClassifier::INDEPENDENT, $crossRel['relationship']);
        $this->assertStringContainsString('distinct scopes and mechanisms', $crossRel['rationale']);
    }

    public function test_shared_page_scope_signals_are_grouped_as_possible_shared_cause(): void
    {
        $clientId = 5;
        $detectedDate = Carbon::parse('2026-11-05 02:00:15');

        $rawSignals = [
            [
                'family' => 'technical.script_error',
                'scope_type' => 'page',
                'scope_target' => 'tapes_packaging_tapes',
                'title' => 'JavaScript error rate increased on packaging tapes',
            ],
            [
                'family' => 'behavioral.bounce_rate',
                'scope_type' => 'page',
                'scope_target' => 'tapes_packaging_tapes',
                'title' => 'Bounce rate increased on packaging tapes (+45%)',
            ],
        ];

        $result = $this->synthesizer->synthesize($clientId, $rawSignals, $detectedDate);

        // Should combine into 1 incident with 1 supporting symptom
        $this->assertCount(1, $result['incidents']);
        $incident = $result['incidents'][0];
        $this->assertCount(1, $incident['supporting_signals']);
        $this->assertEquals(RelationshipClassifier::POSSIBLE_SHARED_CAUSE, $incident['supporting_signals'][0]['relationship_to_root']);
    }

    public function test_confirmed_causal_trace_groups_signals_with_high_confidence(): void
    {
        $clientId = 5;
        $detectedDate = Carbon::parse('2026-11-05 02:00:15');

        $rawSignals = [
            [
                'family' => 'technical.checkout_api_failure',
                'scope_type' => 'page',
                'scope_target' => 'checkout',
                'title' => 'Payment gateway API returning 500 errors',
                'evidence' => ['direct_causal_trace' => true],
            ],
            [
                'family' => 'commercial.order_drop',
                'scope_type' => 'global',
                'scope_target' => 'storewide',
                'title' => 'Zero completed orders in last 2 hours',
                'evidence' => ['direct_causal_trace' => true],
            ],
        ];

        $result = $this->synthesizer->synthesize($clientId, $rawSignals, $detectedDate);

        $this->assertCount(1, $result['incidents']);
        $incident = $result['incidents'][0];
        $this->assertEquals(RelationshipClassifier::CONFIRMED_SHARED_CAUSE, $incident['supporting_signals'][0]['relationship_to_root']);
    }
}
