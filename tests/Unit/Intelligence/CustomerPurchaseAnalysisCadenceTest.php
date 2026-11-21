<?php

namespace Tests\Unit\Intelligence;

use App\Models\Client;
use App\Services\Intelligence\CustomerPurchaseAnalysisService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CustomerPurchaseAnalysisCadenceTest extends TestCase
{
    private CustomerPurchaseAnalysisService $service;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CustomerPurchaseAnalysisService::class);
        $this->client = new Client(['id' => 999, 'name' => 'Test Client', 'timezone' => 'America/New_York']);
    }

    public function test_cluster_orders_enforces_strict_48_hours_from_first_order_window(): void
    {
        $o1 = (object) [
            'id' => 1,
            'order_date' => '2026-01-01 00:00:00',
            'net_revenue' => 100.0,
            'metadata_json' => null,
        ];
        $o2 = (object) [
            'id' => 2,
            'order_date' => '2026-01-02 06:00:00', // +30h
            'net_revenue' => 200.0,
            'metadata_json' => null,
        ];
        $o3 = (object) [
            'id' => 3,
            'order_date' => '2026-01-03 12:00:00', // +60h from o1, +30h from o2
            'net_revenue' => 300.0,
            'metadata_json' => null,
        ];

        $episodes = $this->service->clusterOrdersIntoEpisodes(collect([$o1, $o2, $o3]), 48.0);

        $this->assertCount(2, $episodes);
        $this->assertEquals(2, $episodes[0]['order_count']);
        $this->assertEquals(300.0, $episodes[0]['total_spend']);
        $this->assertCount(2, $episodes[0]['orders']);

        $this->assertEquals(1, $episodes[1]['order_count']);
        $this->assertEquals(300.0, $episodes[1]['total_spend']);
        $this->assertCount(1, $episodes[1]['orders']);
    }

    public function test_cluster_orders_prevents_merging_when_po_references_conflict(): void
    {
        $o1 = (object) [
            'id' => 1,
            'order_date' => '2026-01-01 10:00:00',
            'net_revenue' => 150.0,
            'metadata_json' => ['po_number' => 'PO-ABC'],
        ];
        $o2 = (object) [
            'id' => 2,
            'order_date' => '2026-01-01 14:00:00',
            'net_revenue' => 250.0,
            'metadata_json' => ['po_number' => 'PO-XYZ'],
        ];

        $episodes = $this->service->clusterOrdersIntoEpisodes(collect([$o1, $o2]), 48.0);

        $this->assertCount(2, $episodes);
        $this->assertEquals(1, $episodes[0]['order_count']);
        $this->assertEquals(150.0, $episodes[0]['total_spend']);
        $this->assertEquals(1, $episodes[1]['order_count']);
        $this->assertEquals(250.0, $episodes[1]['total_spend']);
    }

    public function test_cluster_orders_merges_when_po_references_match_within_window(): void
    {
        $o1 = (object) [
            'id' => 1,
            'order_date' => '2026-01-01 10:00:00',
            'net_revenue' => 150.0,
            'metadata_json' => ['po_number' => 'PO-MATCH'],
        ];
        $o2 = (object) [
            'id' => 2,
            'order_date' => '2026-01-01 20:00:00',
            'net_revenue' => 250.0,
            'metadata_json' => ['po_number' => 'PO-MATCH'],
        ];

        $episodes = $this->service->clusterOrdersIntoEpisodes(collect([$o1, $o2]), 48.0);

        $this->assertCount(1, $episodes);
        $this->assertEquals(2, $episodes[0]['order_count']);
        $this->assertEquals(400.0, $episodes[0]['total_spend']);
    }

    public function test_cluster_orders_correctly_groups_merryweather_split_orders(): void
    {
        $o1 = (object) [
            'id' => 1715,
            'order_date' => '2025-03-20 01:08:25',
            'net_revenue' => 648.34,
            'metadata_json' => null,
        ];
        $o2 = (object) [
            'id' => 1716,
            'order_date' => '2025-03-22 00:41:57', // 47.55h after o1
            'net_revenue' => 648.34,
            'metadata_json' => null,
        ];
        $o3 = (object) [
            'id' => 1763,
            'order_date' => '2025-04-01 19:02:37', // 10 days later
            'net_revenue' => 304.64,
            'metadata_json' => null,
        ];

        $episodes = $this->service->clusterOrdersIntoEpisodes(collect([$o1, $o2, $o3]), 48.0);

        $this->assertCount(2, $episodes);
        $this->assertEquals(2, $episodes[0]['order_count']);
        $this->assertEquals(1296.68, $episodes[0]['total_spend']);
        $this->assertEquals(1, $episodes[1]['order_count']);
        $this->assertEquals(304.64, $episodes[1]['total_spend']);
    }

    public function test_cadence_excludes_single_episode_accounts(): void
    {
        $episodes = collect([
            ['first_order_at' => '2025-01-01 10:00:00', 'latest_order_at' => '2025-01-01 10:00:00', 'total_spend' => 500.0],
        ]);

        $cadence = $this->service->calculateAccountCadence($this->client, collect(), $episodes);

        $this->assertFalse($cadence['has_cadence']);
        $this->assertEquals('single_episode_insufficient', $cadence['evidence_level']);
        $this->assertEquals('unsupported', $cadence['confidence']);
    }

    public function test_cadence_excludes_two_episodes_within_fourteen_days_as_project_purchase(): void
    {
        $episodes = collect([
            ['first_order_at' => '2025-01-01 10:00:00', 'latest_order_at' => '2025-01-01 10:00:00', 'total_spend' => 500.0],
            ['first_order_at' => '2025-01-08 10:00:00', 'latest_order_at' => '2025-01-08 10:00:00', 'total_spend' => 700.0],
        ]);

        $cadence = $this->service->calculateAccountCadence($this->client, collect(), $episodes);

        $this->assertFalse($cadence['has_cadence']);
        $this->assertEquals('split_or_project_purchase', $cadence['evidence_level']);
        $this->assertEquals('unsupported', $cadence['confidence']);
    }

    public function test_cadence_qualifies_two_episodes_with_sufficient_span_as_provisional(): void
    {
        $episodes = collect([
            ['first_order_at' => '2025-01-01 10:00:00', 'latest_order_at' => '2025-01-01 10:00:00', 'total_spend' => 2000.0],
            ['first_order_at' => '2025-03-01 10:00:00', 'latest_order_at' => '2025-03-01 10:00:00', 'total_spend' => 2000.0],
        ]);

        $cadence = $this->service->calculateAccountCadence($this->client, collect(), $episodes);

        $this->assertTrue($cadence['has_cadence']);
        $this->assertEquals('provisional_two_episodes', $cadence['evidence_level']);
        $this->assertEquals('provisional', $cadence['confidence']);
        $this->assertEquals(2.5, $cadence['adaptive_winback_multiplier']);
        $this->assertEquals(59, $cadence['median_interval_days']);
    }

    public function test_cadence_calculates_calibrated_multipliers_for_three_plus_episodes(): void
    {
        // Highly regular account (every 30 days exactly: intervals = [30, 30, 30])
        $regularEpisodes = collect([
            ['first_order_at' => '2025-01-01 10:00:00', 'latest_order_at' => '2025-01-01 10:00:00', 'total_spend' => 1000.0],
            ['first_order_at' => '2025-01-31 10:00:00', 'latest_order_at' => '2025-01-31 10:00:00', 'total_spend' => 1000.0],
            ['first_order_at' => '2025-03-02 10:00:00', 'latest_order_at' => '2025-03-02 10:00:00', 'total_spend' => 1000.0],
            ['first_order_at' => '2025-04-01 10:00:00', 'latest_order_at' => '2025-04-01 10:00:00', 'total_spend' => 1000.0],
        ]);

        $cadence = $this->service->calculateAccountCadence($this->client, collect(), $regularEpisodes);

        $this->assertTrue($cadence['has_cadence']);
        $this->assertEquals('qualified_three_plus_episodes', $cadence['evidence_level']);
        $this->assertEquals('high', $cadence['confidence']);
        $this->assertEquals('highly_regular', $cadence['regularity']);
        $this->assertEquals(2.0, $cadence['adaptive_winback_multiplier']);

        // Variable/irregular account (intervals = [10, 120, 20])
        $variableEpisodes = collect([
            ['first_order_at' => '2025-01-01 10:00:00', 'latest_order_at' => '2025-01-01 10:00:00', 'total_spend' => 1000.0],
            ['first_order_at' => '2025-01-11 10:00:00', 'latest_order_at' => '2025-01-11 10:00:00', 'total_spend' => 1000.0],
            ['first_order_at' => '2025-05-11 10:00:00', 'latest_order_at' => '2025-05-11 10:00:00', 'total_spend' => 1000.0],
            ['first_order_at' => '2025-05-31 10:00:00', 'latest_order_at' => '2025-05-31 10:00:00', 'total_spend' => 1000.0],
        ]);

        $cadenceVar = $this->service->calculateAccountCadence($this->client, collect(), $variableEpisodes);

        $this->assertTrue($cadenceVar['has_cadence']);
        $this->assertEquals('irregular_or_project_based', $cadenceVar['regularity']);
        $this->assertEquals(3.2, $cadenceVar['adaptive_winback_multiplier']);
    }
}
