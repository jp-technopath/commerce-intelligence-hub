<?php

namespace Tests\Feature\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\PmConnection;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\User;
use App\Services\Intelligence\WorkPrioritizationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkPrioritizationAndMultiRoleRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected Client $goldkamp;
    protected Client $cambro;
    protected User $manager;
    protected User $engineer;
    protected PmConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->goldkamp = Client::create([
            'name'                     => 'Goldkamp Equipment',
            'status'                   => 'active',
            'jira_project_key'         => 'GOLD',
            'monthly_allocated_hours'  => 100.0, // 100 hours budget
        ]);

        $this->cambro = Client::create([
            'name'                     => 'Cambro Manufacturing',
            'status'                   => 'active',
            'jira_project_key'         => 'CAMB',
            'monthly_allocated_hours'  => 80.0, // 80 hours budget
        ]);

        $this->manager = User::factory()->create([
            'name'     => 'Alex Manager',
            'email'    => 'alex.manager@technopath.test',
            'is_admin' => true,
        ]);

        $this->engineer = User::factory()->create([
            'name'     => 'Dave Engineer',
            'email'    => 'dave.engineer@technopath.test',
            'is_admin' => false,
        ]);

        $this->connection = PmConnection::create([
            'client_id'          => $this->cambro->id,
            'provider'           => 'jira',
            'name'               => 'Jira Connection',
            'is_active'          => true,
            'configuration_json' => ['base_url' => 'https://jira.test'],
        ]);
    }

    protected function createFinding(array $attributes = []): Finding
    {
        return Finding::create(array_merge([
            'client_id'                => $this->cambro->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', uniqid('finding_', true)),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => 'Revenue',
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::Approved->value,
            'execution_status'         => FindingExecutionStatus::Approved->value,
            'outcome_status'           => FindingOutcomeStatus::PendingExecution->value,
            'title'                    => 'Commercial Opportunity',
            'description'              => 'Description text',
            'estimated_revenue_impact' => 5000.00,
        ], $attributes));
    }

    public function test_multi_role_routing_separates_actions_to_appropriate_business_teams(): void
    {
        // 1. Sales Action
        $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Reactivate Dormant Premier Account',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => 'Revenue',
            'target_role'              => WorkPrioritizationEngine::ROLE_SALES,
            'estimated_revenue_impact' => 12500.00,
        ]);

        // 2. Marketing Action
        $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Abandoned B2B Cart Recovery Sequence',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => 'Conversion',
            'target_role'              => WorkPrioritizationEngine::ROLE_MARKETING,
            'estimated_revenue_impact' => 8400.00,
        ]);

        // 3. Engineering Action
        $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Fix Checkout Payment Gateway Timeout',
            'finding_pillar'           => FindingPillar::TechnicalHealth->value,
            'finding_category'         => 'Technical',
            'target_role'              => WorkPrioritizationEngine::ROLE_ENGINEERING,
            'estimated_revenue_impact' => 15000.00,
        ]);

        // 4. Analytics Action
        $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Audit Wholesale Funnel Tracking Anomaly',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => 'Behavioral',
            'target_role'              => WorkPrioritizationEngine::ROLE_ANALYTICS,
            'estimated_revenue_impact' => 3000.00,
        ]);

        // 5. Project Management Action
        $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Resolve Blocked Integration Dependency',
            'finding_pillar'           => FindingPillar::BusinessRisk->value,
            'finding_category'         => 'ProjectRisk',
            'target_role'              => WorkPrioritizationEngine::ROLE_PROJECT_MANAGEMENT,
            'estimated_revenue_impact' => 0.00,
        ]);

        $engine = app(WorkPrioritizationEngine::class);

        // Fetch Sales Plan
        $salesPlan = $engine->getPrioritizedPlanForRole(WorkPrioritizationEngine::ROLE_SALES);
        $this->assertEquals(1, $salesPlan['all_executable_count']);
        $this->assertEquals('Reactivate Dormant Premier Account', $salesPlan['recommended_order'][0]['title']);
        $this->assertEquals(12500.00, $salesPlan['total_revenue_impact']);

        // Fetch Marketing Plan
        $marketingPlan = $engine->getPrioritizedPlanForRole(WorkPrioritizationEngine::ROLE_MARKETING);
        $this->assertEquals(1, $marketingPlan['all_executable_count']);
        $this->assertEquals('Abandoned B2B Cart Recovery Sequence', $marketingPlan['recommended_order'][0]['title']);

        // Fetch Engineering Plan
        $engPlan = $engine->getPrioritizedPlanForRole(WorkPrioritizationEngine::ROLE_ENGINEERING);
        $this->assertEquals(1, $engPlan['all_executable_count']);
        $this->assertEquals('Fix Checkout Payment Gateway Timeout', $engPlan['recommended_order'][0]['title']);

        // Fetch Analytics Plan
        $analyticsPlan = $engine->getPrioritizedPlanForRole(WorkPrioritizationEngine::ROLE_ANALYTICS);
        $this->assertEquals(1, $analyticsPlan['all_executable_count']);
        $this->assertEquals('Audit Wholesale Funnel Tracking Anomaly', $analyticsPlan['recommended_order'][0]['title']);

        // Fetch PM Plan
        $pmPlan = $engine->getPrioritizedPlanForRole(WorkPrioritizationEngine::ROLE_PROJECT_MANAGEMENT);
        $this->assertEquals(1, $pmPlan['all_executable_count']);
        $this->assertEquals('Resolve Blocked Integration Dependency', $pmPlan['recommended_order'][0]['title']);
    }

    public function test_revenue_impact_and_urgency_increase_priority_score_deterministically(): void
    {
        $engine = app(WorkPrioritizationEngine::class);

        // High Revenue Finding ($25,000)
        $highImpact = $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Major Enterprise Re-order Opportunity',
            'estimated_revenue_impact' => 25000.00,
            'severity'                 => FindingSeverity::Critical->value,
            'target_role'              => WorkPrioritizationEngine::ROLE_SALES,
        ]);

        // Modest Revenue Finding ($1,200)
        $lowImpact = $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Minor SKU Replenishment',
            'estimated_revenue_impact' => 1200.00,
            'severity'                 => FindingSeverity::Medium->value,
            'target_role'              => WorkPrioritizationEngine::ROLE_SALES,
        ]);

        $plan = $engine->getPrioritizedPlanForRole(WorkPrioritizationEngine::ROLE_SALES);

        $this->assertCount(2, $plan['recommended_order']);
        $firstItem = $plan['recommended_order'][0];
        $secondItem = $plan['recommended_order'][1];

        $this->assertEquals($highImpact->id, $firstItem['finding_id']);
        $this->assertEquals($lowImpact->id, $secondItem['finding_id']);
        $this->assertGreaterThan($secondItem['score'], $firstItem['score']);
    }

    public function test_budget_exhaustion_triggers_warning_and_approval_gate_without_reducing_business_value(): void
    {
        // 1. Simulate client exceeding 100% of allocation (Goldkamp allocated 100 hrs, logged 115 hrs)
        $workItem = PmWorkItem::create([
            'client_id'                  => $this->goldkamp->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'gk_item_1',
            'external_item_key'          => 'GOLD-101',
            'summary'                    => 'Platform Modernization Task',
            'external_status'            => 'In Progress',
            'normalized_delivery_status' => 'in_progress',
        ]);

        PmWorklog::create([
            'client_id'          => $this->goldkamp->id,
            'pm_connection_id'   => $this->connection->id,
            'pm_work_item_id'    => $workItem->id,
            'external_worklog_id'=> 'wl_gk_1',
            'time_spent_seconds' => 115 * 3600, // 115 hours
            'worklog_started_at' => now(),
        ]);

        // 2. Create high-value commercial opportunity for Goldkamp ($35,000)
        $finding = $this->createFinding([
            'client_id'                => $this->goldkamp->id,
            'title'                    => 'High Value Commercial Expansion',
            'estimated_revenue_impact' => 35000.00,
            'severity'                 => FindingSeverity::Critical->value,
            'target_role'              => WorkPrioritizationEngine::ROLE_SALES,
        ]);

        $engine = app(WorkPrioritizationEngine::class);

        // Score the finding
        $scoreData = $engine->calculateFindingScore($finding, now());

        // A. Verify budget exhaustion warning is triggered and approval is required
        $this->assertTrue($scoreData['requires_budget_approval'], 'Approval gate is required due to exhausted client budget.');
        $this->assertNotNull($scoreData['budget_warning']);
        $this->assertTrue($scoreData['budget_warning']['is_budget_exhausted']);
        $this->assertEquals(115.0, $scoreData['budget_warning']['consumption_pct']);
        $this->assertStringContainsString('Budget threshold exceeded', $scoreData['budget_warning']['warning_message']);

        // B. CRITICAL SAFEGUARD: Verify underlying business value and score are NOT artificially suppressed or penalized!
        $this->assertGreaterThanOrEqual(80, $scoreData['score'], 'Score reflects true underlying business value and is not suppressed.');
        $this->assertEquals(35000.00, (float) $finding->estimated_revenue_impact);

        // C. Verify role plan surfaces the budget warning cleanly
        $salesPlan = $engine->getPrioritizedPlanForRole(WorkPrioritizationEngine::ROLE_SALES, $this->goldkamp->id, $this->manager);
        $this->assertEquals(1, $salesPlan['budget_warnings']);
        $topAction = $salesPlan['recommended_order'][0];
        $this->assertTrue($topAction['requires_budget_approval']);
        $this->assertEquals(35000.00, $topAction['estimated_revenue_impact']);
    }

    public function test_manager_overview_aggregates_multi_role_priorities_and_tracks_outcome_milestones(): void
    {
        // 1. Sales action
        $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Premier Account Expansion',
            'target_role'              => WorkPrioritizationEngine::ROLE_SALES,
            'estimated_revenue_impact' => 18000.00,
            'execution_status'         => FindingExecutionStatus::Approved->value,
            'outcome_status'           => FindingOutcomeStatus::PendingExecution->value,
        ]);

        // 2. Action completed finding (awaiting outcome measurement)
        $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Completed Customer Outreach Campaign',
            'target_role'              => WorkPrioritizationEngine::ROLE_SALES,
            'estimated_revenue_impact' => 9500.00,
            'execution_status'         => FindingExecutionStatus::ActionCompleted->value,
            'outcome_status'           => FindingOutcomeStatus::AwaitingResults->value,
        ]);

        // 3. Measured outcome finding
        $this->createFinding([
            'client_id'                => $this->cambro->id,
            'title'                    => 'Successfully Measured Category Merchandising Lift',
            'target_role'              => WorkPrioritizationEngine::ROLE_MARKETING,
            'estimated_revenue_impact' => 6200.00,
            'execution_status'         => FindingExecutionStatus::Closed->value,
            'outcome_status'           => FindingOutcomeStatus::OutcomeMeasured->value,
        ]);

        $engine = app(WorkPrioritizationEngine::class);
        $overview = $engine->getManagerOverview();

        $this->assertArrayHasKey('roles', $overview);
        $this->assertArrayHasKey(WorkPrioritizationEngine::ROLE_SALES, $overview['roles']);
        $this->assertArrayHasKey(WorkPrioritizationEngine::ROLE_MARKETING, $overview['roles']);

        $summary = $overview['portfolio_summary'];
        $this->assertEquals(1, $summary['actions_completed_count']);
        $this->assertEquals(1, $summary['awaiting_results_count']);
        $this->assertEquals(1, $summary['outcomes_measured_count']);
        $this->assertNotEmpty($overview['top_immediate_priorities']);
    }
}
