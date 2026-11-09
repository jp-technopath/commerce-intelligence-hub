<?php

namespace Tests\Feature\Intelligence;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\RecommendationOutcome;
use App\Models\User;
use App\Services\Intelligence\OutcomeMeasurementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutcomeMeasurementAndAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;
    protected User $manager;
    protected OutcomeMeasurementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name'             => 'Cambro Manufacturing',
            'status'           => 'active',
            'jira_project_key' => 'CAMB',
        ]);

        $this->manager = User::factory()->create([
            'name'     => 'Sarah Product Owner',
            'email'    => 'sarah.po@technopath.test',
            'is_admin' => true,
        ]);

        $this->integration = \App\Models\Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => \App\Enums\IntegrationType::AdobeCommerce,
            'status'           => \App\Enums\IntegrationStatus::Active,
        ]);

        $this->service = app(OutcomeMeasurementService::class);
    }

    protected function createFinding(array $attributes = []): Finding
    {
        return Finding::create(array_merge([
            'client_id'                => $this->client->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', uniqid('finding_', true)),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.dormant_reactivation',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => 'Revenue',
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::Approved->value,
            'execution_status'         => FindingExecutionStatus::ActionCompleted->value,
            'outcome_status'           => FindingOutcomeStatus::AwaitingResults->value,
            'action_completed_at'      => now()->subDays(15),
            'title'                    => 'Reactivate Dormant Premier Account',
            'description'              => 'Sales rep outreach to dormant wholesale client.',
            'estimated_revenue_impact' => 10000.00,
            'metadata_json'            => [
                'registered_customer_id' => 'cust_101',
                'account_name'           => 'Acme Hospital Supply',
            ],
        ], $attributes));
    }

    protected function createOrder(array $attributes = []): CommerceOrder
    {
        return CommerceOrder::create(array_merge([
            'client_id'              => $this->client->id,
            'integration_id'         => $this->integration->id,
            'source'                 => 'magento',
            'source_order_id'        => 'ord_' . uniqid(),
            'source_increment_id'    => 'SO-' . rand(10000, 99999),
            'order_status'           => 'complete',
            'registered_customer_id' => 'cust_101',
            'order_date'             => now()->subDays(5),
            'gross_revenue'          => 5000.00,
            'net_revenue'            => 5000.00,
            'currency'               => 'USD',
            'is_valid'               => true,
        ], $attributes));
    }

    public function test_outcome_measurement_fails_closed_if_action_is_not_completed(): void
    {
        $uncompletedFinding = $this->createFinding([
            'execution_status' => FindingExecutionStatus::WorkInProgress->value,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Action must be completed before outcome measurement');

        $this->service->evaluateFindingOutcome($uncompletedFinding, $this->manager);
    }

    public function test_dormant_reactivation_does_not_assume_zero_baseline_and_attributes_only_net_incremental_lift(): void
    {
        // Finding completed 15 days ago
        $finding = $this->createFinding();

        // Observed order of $8,000 placed 5 days ago
        $order = $this->createOrder([
            'registered_customer_id' => 'cust_101',
            'order_date'             => now()->subDays(5),
            'net_revenue'            => 8000.00,
            'gross_revenue'          => 8000.00,
        ]);

        // Evaluate with explicit historical expected baseline of $3,000
        $outcome = $this->service->evaluateFindingOutcome($finding, $this->manager, [
            'attribution_method'        => OutcomeMeasurementService::METHOD_DORMANT_BASELINE,
            'baseline_expected_revenue' => 3000.00,
        ]);

        $this->assertInstanceOf(RecommendationOutcome::class, $outcome);
        $this->assertEquals(8000.00, (float) $outcome->observed_revenue, 'Observed revenue records total order value.');
        $this->assertEquals(3000.00, (float) $outcome->baseline_expected_revenue, 'Baseline expected revenue is recorded and non-zero.');
        $this->assertEquals(5000.00, (float) $outcome->actual_incremental_impact, 'Incremental impact is strictly net lift (8000 - 3000 = 5000).');
        $this->assertEquals(1, $outcome->orders_count);
        $this->assertFalse($outcome->is_zero_outcome);

        $finding->refresh();
        $this->assertEquals(FindingOutcomeStatus::OutcomeMeasured, $finding->outcome_status);
    }

    public function test_completed_action_with_zero_orders_is_explicitly_tracked_as_zero_outcome_and_not_hidden(): void
    {
        // Finding completed 40 days ago, no orders placed
        $finding = $this->createFinding([
            'action_completed_at' => now()->subDays(40),
            'metadata_json'       => ['registered_customer_id' => 'cust_zero_orders'],
        ]);

        $outcome = $this->service->evaluateFindingOutcome($finding, $this->manager, [
            'window_days' => 30,
        ]);

        $this->assertInstanceOf(RecommendationOutcome::class, $outcome);
        $this->assertEquals(0.00, (float) $outcome->observed_revenue);
        $this->assertEquals(0.00, (float) $outcome->actual_incremental_impact);
        $this->assertEquals(0, $outcome->orders_count);
        $this->assertTrue($outcome->is_zero_outcome);
        $this->assertStringContainsString('Zero orders observed', $outcome->methodology_notes);

        $finding->refresh();
        $this->assertEquals(FindingOutcomeStatus::NoOrdersObserved, $finding->outcome_status);

        // Verify zero outcome is retrievable in queries and not hidden
        $this->assertDatabaseHas('recommendation_outcomes', [
            'finding_id'      => $finding->id,
            'is_zero_outcome' => true,
            'orders_count'    => 0,
        ]);
    }

    public function test_commerce_order_deduplication_prevents_double_counting_across_overlapping_opportunities(): void
    {
        // Shared Order placed 4 days ago
        $sharedOrder = $this->createOrder([
            'registered_customer_id' => 'cust_101',
            'order_date'             => now()->subDays(4),
            'net_revenue'            => 12000.00,
        ]);

        // Opportunity 1: Sales outreach completed 10 days ago
        $finding1 = $this->createFinding([
            'title'               => 'Opportunity 1: Account Outreach',
            'action_completed_at' => now()->subDays(10),
        ]);

        // Opportunity 2: Marketing email sequence completed 8 days ago
        $finding2 = $this->createFinding([
            'title'               => 'Opportunity 2: Catalog Promotion',
            'action_completed_at' => now()->subDays(8),
        ]);

        // 1. Evaluate Opportunity 1 first
        $outcome1 = $this->service->evaluateFindingOutcome($finding1, $this->manager, [
            'baseline_expected_revenue' => 2000.00,
        ]);

        $this->assertEquals(12000.00, (float) $outcome1->observed_revenue);
        $this->assertEquals(10000.00, (float) $outcome1->actual_incremental_impact);
        $this->assertEquals(1, $outcome1->orders_count);

        // 2. Evaluate Opportunity 2
        // The shared order is already attributed to Opportunity 1!
        $outcome2 = $this->service->evaluateFindingOutcome($finding2, $this->manager, [
            'baseline_expected_revenue' => 1000.00,
        ]);

        $this->assertEquals(0.00, (float) $outcome2->observed_revenue, 'Order is deduplicated and not counted again.');
        $this->assertEquals(0.00, (float) $outcome2->actual_incremental_impact, 'Incremental impact is not double counted.');
        $this->assertEquals(0, $outcome2->orders_count);
        $this->assertTrue($outcome2->is_zero_outcome);
    }

    public function test_full_recovery_requires_explicit_documented_justification(): void
    {
        $finding = $this->createFinding();
        $this->createOrder([
            'registered_customer_id' => 'cust_101',
            'net_revenue'            => 6000.00,
        ]);

        // Attempting full recovery without justification throws exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Full recovery attribution requires explicit documented justification');

        $this->service->evaluateFindingOutcome($finding, $this->manager, [
            'attribution_method' => OutcomeMeasurementService::METHOD_FULL_RECOVERY,
        ]);
    }
}
