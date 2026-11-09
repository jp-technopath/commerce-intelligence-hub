<?php

namespace Tests\Feature\Intelligence;

use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\User;
use App\Services\Intelligence\InitiativeAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitiativeAggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Client $goldkamp;
    private Client $cambro;
    private InitiativeAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->goldkamp = Client::create([
            'name'          => 'Goldkamp Wholesale Supply',
            'industry'      => 'packaging_and_industrial',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
        ]);

        $this->cambro = Client::create([
            'name'          => 'Cambro Manufacturing',
            'industry'      => 'foodservice_equipment',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
        ]);

        $this->service = app(InitiativeAggregationService::class);

        $admin = User::factory()->create(['name' => 'Admin User', 'is_admin' => true]);
        $this->actingAs($admin);
    }

    public function test_aggregates_findings_into_strategic_initiatives_with_segregated_financials(): void
    {
        $user = User::factory()->create(['name' => 'Sarah Connor']);

        // 1. Create 2 Reactivation findings for Goldkamp (Strategic Goal: B2B Wholesale Retention)
        Finding::create([
            'client_id'                  => $this->goldkamp->id,
            'finding_type'               => 'opportunity',
            'fingerprint'                => hash('sha256', 'gk_react_1'),
            'source_type'                => 'detector',
            'source_id'                  => 'opportunity.b2b_account_reactivation',
            'title'                      => 'Dormant Account: Account Alpha',
            'severity'                   => FindingSeverity::High,
            'finding_pillar'             => FindingPillar::GrowthOpportunity,
            'finding_category'           => FindingCategory::Revenue,
            'status'                     => FindingStatus::Approved,
            'impact_nature'              => 'historical_reorder_value',
            'estimated_revenue_impact'   => 4500.00,
            'confidence_score'           => 92.0,
            'priority_score'             => 88.0,
            'target_role'                => 'account_executive',
            'suggested_action'           => 'Schedule outbound re-engagement call',
            'evidence_json'              => [
                'account_hash'           => 'alpha_hash',
                'account_label'          => 'Account Alpha (ID: 101)',
                'lifetime_net_revenue'   => 25000.00,
                'lifetime_orders'        => 8,
                'cadence_days'           => 45,
                'days_since_last_order'  => 120,
            ],
            'approved_at'                => now(),
        ]);

        Finding::create([
            'client_id'                  => $this->goldkamp->id,
            'finding_type'               => 'opportunity',
            'fingerprint'                => hash('sha256', 'gk_react_2'),
            'source_type'                => 'detector',
            'source_id'                  => 'opportunity.b2b_account_reactivation',
            'title'                      => 'Dormant Account: Account Beta',
            'severity'                   => FindingSeverity::Medium,
            'finding_pillar'             => FindingPillar::GrowthOpportunity,
            'finding_category'           => FindingCategory::Revenue,
            'status'                     => FindingStatus::Assigned,
            'impact_nature'              => 'historical_reorder_value',
            'estimated_revenue_impact'   => 3200.00,
            'confidence_score'           => 85.0,
            'priority_score'             => 80.0,
            'target_role'                => 'account_executive',
            'responsible_user_id'        => $user->id,
            'suggested_action'           => 'Send personalized replenishment catalog',
            'evidence_json'              => [
                'account_hash'           => 'beta_hash',
                'account_label'          => 'Account Beta (ID: 102)',
                'lifetime_net_revenue'   => 12000.00,
                'lifetime_orders'        => 4,
                'cadence_days'           => 60,
                'days_since_last_order'  => 140,
            ],
            'assigned_at'                => now(),
        ]);

        // 2. Create 1 Contraction finding for Goldkamp (Strategic Goal: Key Account Churn Prevention)
        Finding::create([
            'client_id'                  => $this->goldkamp->id,
            'finding_type'               => 'risk',
            'fingerprint'                => hash('sha256', 'gk_contract_1'),
            'source_type'                => 'detector',
            'source_id'                  => 'risk.account_contraction',
            'title'                      => 'Spend Contraction: Account Gamma',
            'severity'                   => FindingSeverity::Critical,
            'finding_pillar'             => FindingPillar::BusinessRisk,
            'finding_category'           => FindingCategory::Revenue,
            'status'                     => FindingStatus::UnderReview,
            'impact_nature'              => 'observed_spending_decline',
            'estimated_revenue_impact'   => 7800.00,
            'confidence_score'           => 90.0,
            'priority_score'             => 95.0,
            'target_role'                => 'vp_sales',
            'suggested_action'           => 'Conduct executive health-check review',
            'evidence_json'              => [
                'account_hash'           => 'gamma_hash',
                'account_label'          => 'Account Gamma (ID: 103)',
                'lifetime_net_revenue'   => 45000.00,
                'lifetime_orders'        => 12,
                'baseline_spend'         => 12000.00,
                'eval_spend'             => 4200.00,
                'decline_amount'         => 7800.00,
                'decline_pct'            => 65.0,
                'has_outlier_normalization' => false,
            ],
            'under_review_at'            => now(),
        ]);

        // 3. Create 1 Replenishment finding for Cambro (Strategic Goal: Consumable Reorder Capture)
        Finding::create([
            'client_id'                  => $this->cambro->id,
            'finding_type'               => 'opportunity',
            'fingerprint'                => hash('sha256', 'cambro_replenish_1'),
            'source_type'                => 'detector',
            'source_id'                  => 'opportunity.replenishment_cadence',
            'title'                      => 'Overdue Replenishment: Delta Logistics',
            'severity'                   => FindingSeverity::High,
            'finding_pillar'             => FindingPillar::GrowthOpportunity,
            'finding_category'           => FindingCategory::Revenue,
            'status'                     => FindingStatus::New,
            'impact_nature'              => 'historical_reorder_value',
            'estimated_revenue_impact'   => 2100.00,
            'confidence_score'           => 88.0,
            'priority_score'             => 82.0,
            'target_role'                => 'account_manager',
            'suggested_action'           => 'Trigger automated replenishment notification',
            'evidence_json'              => [
                'account_hash'           => 'delta_hash',
                'account_label'          => 'Delta Logistics (ID: 201)',
                'lifetime_net_revenue'   => 15000.00,
                'lifetime_orders'        => 6,
                'cadence_days'           => 30,
                'days_since_last_order'  => 45,
            ],
        ]);

        // Test aggregation across all clients
        $initiatives = $this->service->getInitiatives();

        $this->assertCount(3, $initiatives);

        // Verify Key Account Churn Prevention initiative
        $churnInitiative = $initiatives->firstWhere('strategic_goal', 'Key Account Churn Prevention');
        $this->assertNotNull($churnInitiative);
        $this->assertEquals('Goldkamp Wholesale Supply', $churnInitiative['client_name']);
        $this->assertEquals(1, $churnInitiative['qualifying_accounts_count']);
        $this->assertEquals(7800.00, $churnInitiative['financial_metrics']['observed_spending_decline']);
        $this->assertEquals(0.0, $churnInitiative['financial_metrics']['historical_reorder_value']);
        $this->assertEquals('Observed Spending Decline', $churnInitiative['primary_financial_label']);
        $this->assertEquals('under_review', $churnInitiative['execution_status']['status']);
        $this->assertCount(1, $churnInitiative['accounts']);
        $this->assertEquals('Account Gamma (ID: 103)', $churnInitiative['accounts'][0]['account_label']);

        // Verify B2B Wholesale Retention initiative
        $retentionInitiative = $initiatives->firstWhere('strategic_goal', 'B2B Wholesale Retention');
        $this->assertNotNull($retentionInitiative);
        $this->assertEquals('Goldkamp Wholesale Supply', $retentionInitiative['client_name']);
        $this->assertEquals(2, $retentionInitiative['qualifying_accounts_count']);
        $this->assertEquals(7700.00, $retentionInitiative['financial_metrics']['historical_reorder_value']);
        $this->assertEquals(0.0, $retentionInitiative['financial_metrics']['observed_spending_decline']);
        $this->assertEquals('Historical Reorder Value', $retentionInitiative['primary_financial_label']);
        $this->assertEquals('approved', $retentionInitiative['execution_status']['status']);
        $this->assertCount(2, $retentionInitiative['accounts']);

        // Verify Cambro initiative
        $cambroInitiative = $initiatives->firstWhere('client_id', $this->cambro->id);
        $this->assertNotNull($cambroInitiative);
        $this->assertEquals('Cambro Manufacturing', $cambroInitiative['client_name']);
        $this->assertEquals('Consumable Reorder Capture', $cambroInitiative['strategic_goal']);
        $this->assertEquals(2100.00, $cambroInitiative['financial_metrics']['historical_reorder_value']);

        // Verify client-specific filtering
        $goldkampInitiatives = $this->service->getInitiatives($this->goldkamp);
        $this->assertCount(2, $goldkampInitiatives);
        $this->assertTrue($goldkampInitiatives->every(fn($i) => $i['client_id'] === $this->goldkamp->id));
    }

    public function test_non_commerce_and_technical_health_findings_aggregate_cleanly(): void
    {
        // Create a Technical Health finding
        Finding::create([
            'client_id'                  => $this->cambro->id,
            'finding_type'               => 'technical',
            'fingerprint'                => hash('sha256', 'cambro_tech_1'),
            'source_type'                => 'pipeline',
            'source_id'                  => 'data_pipeline_gap',
            'title'                      => 'Order Sync Discrepancy Detected',
            'severity'                   => FindingSeverity::High,
            'finding_pillar'             => FindingPillar::TechnicalHealth,
            'finding_category'           => FindingCategory::Technical,
            'status'                     => FindingStatus::New,
            'impact_nature'              => 'directional_opportunity',
            'estimated_revenue_impact'   => 0.0,
            'confidence_score'           => 95.0,
            'priority_score'             => 75.0,
            'target_role'                => 'data_engineer',
            'suggested_action'           => 'Validate daily order sync schedule',
            'evidence_json'              => ['gap_count' => 3],
        ]);

        $initiatives = $this->service->getInitiatives($this->cambro, FindingPillar::TechnicalHealth->value);

        $this->assertCount(1, $initiatives);
        $techInit = $initiatives->first();
        $this->assertEquals('Data & Technical Health Governance', $techInit['strategic_goal']);
        $this->assertEquals(FindingPillar::TechnicalHealth->value, $techInit['pillar']);
        $this->assertEquals('data_engineer', $techInit['target_role']);
    }

    public function test_five_management_questions_are_explicitly_answered_in_each_initiative(): void
    {
        Finding::create([
            'client_id'                  => $this->goldkamp->id,
            'finding_type'               => 'opportunity',
            'fingerprint'                => hash('sha256', 'winback_1'),
            'source_type'                => 'detector',
            'source_id'                  => 'opportunity.long_term_winback',
            'title'                      => 'Long-Term Inactive: Mega Corp',
            'severity'                   => FindingSeverity::High,
            'finding_pillar'             => FindingPillar::GrowthOpportunity,
            'finding_category'           => FindingCategory::Revenue,
            'status'                     => FindingStatus::New,
            'impact_nature'              => 'historical_reorder_value',
            'estimated_revenue_impact'   => 15000.00,
            'confidence_score'           => 90.0,
            'priority_score'             => 85.0,
            'target_role'                => 'executive_sales',
            'suggested_action'           => 'Executive outreach with volume pricing incentive',
            'evidence_json'              => [
                'account_hash'  => 'mega_hash',
                'account_label' => 'Mega Corp (ID: 999)',
            ],
        ]);

        $initiatives = $this->service->getInitiatives($this->goldkamp);
        $winbackInit = $initiatives->firstWhere('strategic_goal', 'Enterprise Winback Campaign');

        $this->assertNotNull($winbackInit);

        // Q1: What business opportunity exists?
        $this->assertNotEmpty($winbackInit['initiative_title']);
        $this->assertNotEmpty($winbackInit['business_opportunity']);
        $this->assertStringContainsString('enterprise accounts', $winbackInit['business_opportunity']);

        // Q2: Why should we care?
        $this->assertNotEmpty($winbackInit['business_rationale']);
        $this->assertStringContainsString('$15,000.00', $winbackInit['business_rationale']);
        $this->assertStringContainsString('Historical Reorder Value', $winbackInit['business_rationale']);
        $this->assertEquals(15000.00, $winbackInit['financial_metrics']['historical_reorder_value']);
        $this->assertEquals(0.0, $winbackInit['financial_metrics']['observed_spending_decline']);

        // Q3: Who or what is affected?
        $this->assertEquals('Goldkamp Wholesale Supply', $winbackInit['client_name']);
        $this->assertEquals(1, $winbackInit['qualifying_accounts_count']);
        $this->assertCount(1, $winbackInit['accounts']);
        $this->assertEquals('Mega Corp (ID: 999)', $winbackInit['accounts'][0]['account_label']);

        // Q4: What should we do next?
        $this->assertNotEmpty($winbackInit['recommended_action']);
        $this->assertStringContainsString('sales outreach', $winbackInit['recommended_action']);

        // Q5: Who is responsible?
        $this->assertEquals('executive_sales', $winbackInit['target_role']);
        $this->assertEquals('new', $winbackInit['execution_status']['status']);
    }
}

