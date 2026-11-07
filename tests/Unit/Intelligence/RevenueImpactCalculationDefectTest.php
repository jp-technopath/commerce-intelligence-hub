<?php

namespace Tests\Unit\Intelligence;

use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\Finding;
use App\Services\Intelligence\FindingRuleEvaluator;
use App\Enums\ClientStatus;
use App\Enums\FindingSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueImpactCalculationDefectTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribution_variance_has_null_revenue_impact(): void
    {
        $client = Client::create([
            'name'          => 'Client Test',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
        ]);

        // Create metrics for 7 days
        for ($i = 0; $i < 7; $i++) {
            $date = now()->subDays($i)->startOfDay()->toDateString();
            CommerceMetric::create([
                'client_id' => $client->id,
                'source'    => 'adobe_commerce',
                'date'      => $date,
                'revenue'   => 1000.00,
                'orders'    => 10,
                'aov'       => 100.00,
                'sessions'  => 500,
            ]);
            CommerceMetric::create([
                'client_id' => $client->id,
                'source'    => 'ga4',
                'date'      => $date,
                'revenue'   => 2000.00,
                'orders'    => 15,
                'aov'       => 133.33,
                'sessions'  => 500,
            ]);
        }

        $rule = [
            'key'         => 'adobe_vs_ga4_revenue_discrepancy',
            'name'        => 'Revenue Discrepancy',
            'metric_a'    => 'adobe_commerce.revenue',
            'metric_b'    => 'ga4.revenue',
            'operator'    => 'percentage_difference',
            'threshold'   => 20.0,
            'period_days' => 7,
            'severity'    => 'high',
        ];

        $evaluator = new FindingRuleEvaluator();
        $triggered = $evaluator->evaluateRule($client, $rule);

        $this->assertTrue($triggered);

        $finding = Finding::where('client_id', $client->id)->first();
        $this->assertNotNull($finding);
        $this->assertNull($finding->estimated_revenue_impact);
        $this->assertArrayHasKey('revenue_impact_calculation', $finding->metadata_json);
        $this->assertFalse($finding->metadata_json['revenue_impact_calculation']['modeled']);
        $this->assertStringContainsString('variance represents reporting alignment differences', $finding->metadata_json['revenue_impact_calculation']['reason']);
    }

    public function test_conversion_rate_drop_during_volatile_traffic_has_null_impact(): void
    {
        $client = Client::create([
            'name'          => 'Client Volatile Traffic',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
        ]);

        // Prior period (days 8-14): 500 sessions, 10 orders (CVR 2.0%)
        for ($i = 8; $i <= 14; $i++) {
            $date = now()->subDays($i)->startOfDay()->toDateString();
            CommerceMetric::create([
                'client_id' => $client->id,
                'source'    => 'ga4',
                'date'      => $date,
                'revenue'   => 1000.00,
                'orders'    => 2,
                'aov'       => 500.00,
                'sessions'  => 100, // total 700
            ]);
        }

        // Current period (days 1-7): Traffic surges to 5,000 sessions, orders 2 (CVR 0.04% - a 98% drop)
        for ($i = 1; $i <= 7; $i++) {
            $date = now()->subDays($i)->startOfDay()->toDateString();
            CommerceMetric::create([
                'client_id' => $client->id,
                'source'    => 'ga4',
                'date'      => $date,
                'revenue'   => 1000.00,
                'orders'    => 2,
                'aov'       => 500.00,
                'sessions'  => 1000, // total 7,000 (10x surge)
            ]);
        }

        $rule = [
            'key'         => 'ga4_conversion_rate_drop',
            'name'        => 'Conversion Rate Drop',
            'metric_a'    => 'ga4.conversion_rate',
            'operator'    => 'percentage_change',
            'threshold'   => -20.0,
            'period_days' => 7,
            'severity'    => 'high',
        ];

        $evaluator = new FindingRuleEvaluator();
        $triggered = $evaluator->evaluateRule($client, $rule);

        $this->assertTrue($triggered);

        $finding = Finding::where('client_id', $client->id)->first();
        $this->assertNotNull($finding);
        // Under volatile traffic surge, estimated lost revenue must be null
        $this->assertNull($finding->estimated_revenue_impact);
        $this->assertStringContainsString('volatile traffic conditions', $finding->metadata_json['revenue_impact_calculation']['reason']);
    }

    public function test_direct_revenue_drop_records_observed_shortfall(): void
    {
        $client = Client::create([
            'name'          => 'Client Revenue Drop',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
        ]);

        // Prior period (days 8-14): $10,000 revenue
        for ($i = 8; $i <= 14; $i++) {
            $date = now()->subDays($i)->startOfDay()->toDateString();
            CommerceMetric::create([
                'client_id' => $client->id,
                'source'    => 'adobe_commerce',
                'date'      => $date,
                'revenue'   => 1428.57,
                'orders'    => 10,
                'aov'       => 142.86,
                'sessions'  => 500,
            ]);
        }

        // Current period (days 1-7): $5,000 revenue (50% drop)
        for ($i = 1; $i <= 7; $i++) {
            $date = now()->subDays($i)->startOfDay()->toDateString();
            CommerceMetric::create([
                'client_id' => $client->id,
                'source'    => 'adobe_commerce',
                'date'      => $date,
                'revenue'   => 714.28,
                'orders'    => 5,
                'aov'       => 142.86,
                'sessions'  => 500,
            ]);
        }

        $rule = [
            'key'         => 'adobe_revenue_drop',
            'name'        => 'Adobe Revenue Drop',
            'metric_a'    => 'adobe_commerce.revenue',
            'operator'    => 'percentage_change',
            'threshold'   => -20.0,
            'period_days' => 7,
            'severity'    => 'critical',
        ];

        $evaluator = new FindingRuleEvaluator();
        $triggered = $evaluator->evaluateRule($client, $rule);

        $this->assertTrue($triggered);

        $finding = Finding::where('client_id', $client->id)->first();
        $this->assertNotNull($finding);
        $this->assertNotNull($finding->estimated_revenue_impact);
        $this->assertGreaterThan(4900.0, $finding->estimated_revenue_impact);
        $this->assertEquals('val_prev - val_current', $finding->metadata_json['revenue_impact_calculation']['formula']);
    }
}
