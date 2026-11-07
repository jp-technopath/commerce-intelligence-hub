<?php

namespace Tests\Unit\Intelligence;

use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\Finding;
use App\Services\Intelligence\DataContextGatherer;
use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataContextGathererLookbackWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_lookback_window_expands_for_multi_day_rules(): void
    {
        $client = Client::create([
            'name'          => 'Client Dynamic Lookback',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
        ]);

        $finding = Finding::create([
            'client_id'        => $client->id,
            'finding_type'     => 'revenue_drop_30d',
            'finding_category' => FindingCategory::Revenue,
            'title'            => '30 Day Revenue Drop',
            'description'      => 'Revenue dropped significantly over 30 days',
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
            'detected_at'      => now(),
            'metadata_json'    => [
                'period_days' => 30,
            ],
        ]);

        $gatherer = new DataContextGatherer();
        $context  = $gatherer->gatherContext($finding);

        $this->assertArrayHasKey('coverage_summary', $context);
        $summary = $context['coverage_summary'];

        // Effective lookback for 30d should be max(14, 30*2 + 7) = 67 days
        $this->assertEquals(67, $summary['lookback_days_requested']);
        $this->assertEquals('insufficient_coverage', $summary['coverage_status']);
        $this->assertFalse($summary['is_coverage_complete']);
    }

    public function test_coverage_status_reports_complete_when_sufficient_history_exists(): void
    {
        $client = Client::create([
            'name'          => 'Client Complete Coverage',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
        ]);

        $periodDays = 7;
        // Effective lookback = max(14, 7*2 + 7) = 21 days
        // Seed 20 days of commerce metrics
        for ($i = 0; $i <= 20; $i++) {
            CommerceMetric::create([
                'client_id' => $client->id,
                'source'    => 'adobe_commerce',
                'date'      => now()->subDays($i)->startOfDay()->toDateTimeString(),
                'revenue'   => 500.00,
                'orders'    => 5,
                'aov'       => 100.00,
                'sessions'  => 200,
            ]);
        }

        $finding = Finding::create([
            'client_id'        => $client->id,
            'finding_type'     => 'revenue_drop_7d',
            'finding_category' => FindingCategory::Revenue,
            'title'            => '7 Day Revenue Drop',
            'description'      => 'Revenue dropped over 7 days',
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::New,
            'detected_at'      => now(),
            'metadata_json'    => [
                'period_days' => 7,
            ],
        ]);

        $gatherer = new DataContextGatherer();
        $context  = $gatherer->gatherContext($finding);

        $this->assertArrayHasKey('coverage_summary', $context);
        $summary = $context['coverage_summary'];

        $this->assertEquals(21, $summary['lookback_days_requested']);
        $this->assertTrue($summary['is_coverage_complete']);
        $this->assertEquals('complete', $summary['coverage_status']);
    }
}
