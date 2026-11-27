<?php

namespace Tests\Feature\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ImpactNature;
use App\Filament\Resources\FindingResource\Pages\ListFindings;
use App\Filament\Widgets\BusinessInitiativesOverviewWidget;
use App\Models\Client;
use App\Models\Finding;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessInitiativesUiAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $salesUser;
    protected Client $goldkamp;
    protected Client $cambro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::factory()->create([
            'name'     => 'Admin Lead',
            'email'    => 'admin@technopath.co',
            'is_admin' => true,
        ]);

        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->first();
        UserRoleAssignment::create([
            'user_id'   => $this->adminUser->id,
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);

        $this->salesUser = User::factory()->create([
            'name'     => 'Jean Paul Hanna',
            'email'    => 'jeanpaul@technopath.co',
            'is_admin' => false,
        ]);

        $this->goldkamp = Client::create([
            'name'          => 'GoldKamp',
            'industry'      => 'Industrial Packaging',
            'platform_type' => 'Magento',
            'status'        => 'active',
        ]);

        $this->cambro = Client::create([
            'name'          => 'Cambro',
            'industry'      => 'Foodservice Equipment',
            'platform_type' => 'Magento',
            'status'        => 'active',
        ]);
    }

    public function test_business_initiatives_widget_renders_consolidated_initiatives(): void
    {
        $this->actingAs($this->adminUser);

        // Create 2 winback findings for Goldkamp
        Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_wb_001'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 2500.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'title'                    => 'Dormant Enterprise Account #001',
            'suggested_action'         => 'Executive sales re-engagement',
            'target_role'              => 'executive_sales',
            'metadata_json'            => [
                'detector_key'  => 'opportunity.long_term_winback',
                'account_label' => 'Account #001',
                'account_hash'  => 'hash001',
            ],
        ]);

        Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_wb_002'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 3500.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'title'                    => 'Dormant Enterprise Account #002',
            'suggested_action'         => 'Executive sales re-engagement',
            'target_role'              => 'executive_sales',
            'metadata_json'            => [
                'detector_key'  => 'opportunity.long_term_winback',
                'account_label' => 'Account #002',
                'account_hash'  => 'hash002',
            ],
        ]);

        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->assertSee('Strategic Business Initiatives')
            ->assertSee('GoldKamp')
            ->assertSee('Enterprise Winback Campaign')
            ->assertSee('$6,000')
            ->assertSee('historical reorder benchmark')
            ->assertSee('2 awaiting review')
            ->assertSee('Review Accounts')
            ->assertDontSee('Commercial Rationale:')
            ->assertDontSee('Not yet measured')
            ->assertDontSee('Batch Approve')
            ->call('openDrillDown', 'init_' . $this->goldkamp->id . '_enterprise-winback-campaign')
            ->assertSee('$6,000.00')
            ->assertSee('Historical Reorder Value')
            ->assertSee('Executive Sales')
            ->assertSee('Batch Approve');
    }

    public function test_initiative_drill_down_shows_account_details_and_lifecycle_controls(): void
    {
        $this->actingAs($this->adminUser);

        $finding = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_react_abc'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.b2b_account_reactivation',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 1250.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'title'                    => 'Dormant Wholesale Account #abc',
            'suggested_action'         => 'Account manager quote outreach',
            'target_role'              => 'account_manager',
            'metadata_json'            => [
                'detector_key'  => 'opportunity.b2b_account_reactivation',
                'account_label' => 'Wholesale Buyer #abc',
                'account_hash'  => 'hash_abc_123',
                'evidence'      => [
                    'cadence'               => 30,
                    'days_since_last_order' => 95,
                    'lifetime_spend'        => 8500.00,
                    'lifetime_orders'       => 7,
                ],
            ],
        ]);

        $test = Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->call('openDrillDown', 'init_' . $this->goldkamp->id . '_b2b-wholesale-retention')
            ->assertSee('Account-Level Drill-Down')
            ->assertSee('Wholesale Buyer #abc')
            ->assertSee('$1,250.00')
            ->assertSee('Historical Reorder Value');

        // Review Finding
        $test->call('reviewFinding', $finding->id);
        $finding->refresh();
        $this->assertEquals(FindingStatus::UnderReview, $finding->status);
        $this->assertNotNull($finding->under_review_at);

        // Approve Finding
        $test->call('approveFinding', $finding->id);
        $finding->refresh();
        $this->assertEquals(FindingStatus::Approved, $finding->status);
        $this->assertNotNull($finding->approved_at);
        $this->assertEquals($this->adminUser->id, $finding->approved_by_user_id);

        // Assign Finding to Jean Paul Hanna
        $test->call('openAssignModal', $finding->id)
            ->set('assigneeUserId', $this->salesUser->id)
            ->set('assignNotes', 'Execute personalized outreach via wholesale portal')
            ->call('confirmAssign');

        $finding->refresh();
        $this->assertEquals(FindingStatus::Assigned, $finding->status);
        $this->assertNotNull($finding->assigned_at);
        $this->assertEquals($this->salesUser->id, $finding->responsible_user_id);

        // Resolve Finding
        $test->call('openResolveModal', $finding->id)
            ->set('resolutionNotes', 'Customer placed $1,400 reorder')
            ->call('confirmResolve');

        $finding->refresh();
        $this->assertEquals(FindingStatus::Resolved, $finding->status);
        $this->assertNotNull($finding->resolved_at);
        $this->assertStringContainsString('Customer placed $1,400 reorder', $finding->resolution_notes);

        // Verify status history audit entries
        $history = $finding->status_history_json;
        $this->assertIsArray($history);
        $this->assertCount(4, $history); // under_review, approved, assigned, resolved
        $this->assertEquals('resolved', $history[3]['to_status']);
        $this->assertEquals($this->adminUser->name, $history[3]['changed_by_name']);
    }

    public function test_batch_approve_entire_initiative(): void
    {
        $this->actingAs($this->adminUser);

        $f1 = Finding::create([
            'client_id'                => $this->cambro->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'cb_wb_A'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'title'                    => 'Winback Account A',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 1000.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'metadata_json'            => [
                'detector_key'  => 'opportunity.long_term_winback',
                'account_label' => 'Account A',
                'account_hash'  => 'hashA',
            ],
        ]);

        $f2 = Finding::create([
            'client_id'                => $this->cambro->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'cb_wb_B'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'title'                    => 'Winback Account B',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'status'                   => FindingStatus::UnderReview->value,
            'estimated_revenue_impact' => 2000.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'metadata_json'            => [
                'detector_key'  => 'opportunity.long_term_winback',
                'account_label' => 'Account B',
                'account_hash'  => 'hashB',
            ],
        ]);

        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->call('approveAllInInitiative', 'init_' . $this->cambro->id . '_enterprise-winback-campaign');

        $this->assertEquals(FindingStatus::Approved, $f1->fresh()->status);
        $this->assertEquals(FindingStatus::Approved, $f2->fresh()->status);
    }

    public function test_list_findings_page_renders_with_header_widget_and_tabs(): void
    {
        $this->actingAs($this->adminUser);

        // Commercial finding
        Finding::create([
            'client_id'        => $this->goldkamp->id,
            'finding_type'     => 'opportunity',
            'fingerprint'      => hash('sha256', 'gk_ret_opp'),
            'finding_pillar'   => FindingPillar::GrowthOpportunity->value,
            'finding_category' => FindingCategory::Revenue->value,
            'status'           => FindingStatus::New->value,
            'title'            => 'B2B Retention Opportunity',
        ]);

        // Technical health finding
        Finding::create([
            'client_id'        => $this->goldkamp->id,
            'finding_type'     => 'technical_finding',
            'fingerprint'      => hash('sha256', 'gk_tech_discrepancy'),
            'finding_pillar'   => FindingPillar::TechnicalHealth->value,
            'finding_category' => FindingCategory::Technical->value,
            'status'           => FindingStatus::New->value,
            'title'            => 'GA4 Integration Tagging Discrepancy',
        ]);

        Livewire::test(ListFindings::class)
            ->assertSee('Strategic Initiatives')
            ->assertSee('All Commercial & Operational')
            ->assertSee('Technical & Data Health')
            ->assertSee('Resolved / Dismissed');
    }

    public function test_list_findings_table_and_badges_filter_strictly_by_selected_client(): void
    {
        $this->actingAs($this->adminUser);

        // Goldkamp finding
        $gkFinding = Finding::create([
            'client_id'        => $this->goldkamp->id,
            'finding_type'     => 'opportunity',
            'fingerprint'      => hash('sha256', 'gk_wb_strict'),
            'finding_pillar'   => FindingPillar::GrowthOpportunity->value,
            'finding_category' => FindingCategory::Revenue->value,
            'status'           => FindingStatus::New->value,
            'title'            => 'Goldkamp Long-Term Winback Opportunity',
        ]);

        // Cambro finding
        $cambroFinding = Finding::create([
            'client_id'        => $this->cambro->id,
            'finding_type'     => 'opportunity',
            'fingerprint'      => hash('sha256', 'cambro_wb_strict'),
            'finding_pillar'   => FindingPillar::GrowthOpportunity->value,
            'finding_category' => FindingCategory::Revenue->value,
            'status'           => FindingStatus::New->value,
            'title'            => 'Cambro Long-Term Winback Opportunity',
        ]);

        // 1. Initial unfiltered state: both records visible
        Livewire::test(ListFindings::class)
            ->assertCanSeeTableRecords([$gkFinding, $cambroFinding]);

        // 2. Filtered by Goldkamp via selectedClientId property or query:
        // Must see Goldkamp finding, must NOT see Cambro finding
        Livewire::test(ListFindings::class, ['selectedClientId' => $this->goldkamp->id])
            ->assertCanSeeTableRecords([$gkFinding])
            ->assertCanNotSeeTableRecords([$cambroFinding]);

        // 3. Dispatch finding-client-filter-changed event with Goldkamp ID:
        Livewire::test(ListFindings::class)
            ->dispatch('finding-client-filter-changed', selectedClientId: $this->goldkamp->id)
            ->assertSet('selectedClientId', $this->goldkamp->id)
            ->assertCanSeeTableRecords([$gkFinding])
            ->assertCanNotSeeTableRecords([$cambroFinding]);

        // 4. Switch to Cambro:
        Livewire::test(ListFindings::class, ['selectedClientId' => $this->goldkamp->id])
            ->dispatch('finding-client-filter-changed', selectedClientId: $this->cambro->id)
            ->assertSet('selectedClientId', $this->cambro->id)
            ->assertCanSeeTableRecords([$cambroFinding])
            ->assertCanNotSeeTableRecords([$gkFinding]);

        // 5. Reset to all clients (null):
        Livewire::test(ListFindings::class, ['selectedClientId' => $this->goldkamp->id])
            ->dispatch('finding-client-filter-changed', selectedClientId: null)
            ->assertSet('selectedClientId', null)
            ->assertCanSeeTableRecords([$gkFinding, $cambroFinding]);
    }

    public function test_business_initiatives_widget_dispatches_client_and_pillar_events_and_receives_table_sync(): void
    {
        $this->actingAs($this->adminUser);

        // Test widget dispatches events when selectedClientId changes
        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->set('selectedClientId', $this->goldkamp->id)
            ->assertDispatched('finding-client-filter-changed', selectedClientId: $this->goldkamp->id)
            ->assertDispatched('finding-filter-changed', selectedClientId: $this->goldkamp->id, selectedPillar: null);

        // Test widget dispatches events when selectedPillar changes
        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->set('selectedPillar', 'growth_opportunity')
            ->assertDispatched('finding-pillar-filter-changed', selectedPillar: 'growth_opportunity')
            ->assertDispatched('finding-filter-changed', selectedClientId: null, selectedPillar: 'growth_opportunity');

        // Test widget updates internal state when table dispatches client change
        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->dispatch('finding-client-filter-changed', selectedClientId: $this->cambro->id)
            ->assertSet('selectedClientId', $this->cambro->id);
    }

    public function test_simplified_overview_hides_customer_badge_when_filtered_and_tracks_true_work_in_progress_and_outcomes(): void
    {
        $this->actingAs($this->adminUser);

        // 1. Overlapping manual verification flags on ONE account (provisional + discordant contact)
        Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_overlap_1'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'status'                   => FindingStatus::New->value,
            'execution_status'         => \App\Enums\FindingExecutionStatus::New->value,
            'outcome_status'           => \App\Enums\FindingOutcomeStatus::PendingExecution->value,
            'estimated_revenue_impact' => 2500.00,
            'impact_nature'            => ImpactNature::HistoricalPurchasingEpisodeBenchmark->value,
            'title'                    => 'Dormant Enterprise Account Overlap',
            'evidence_json'            => [
                'account_hash'  => 'hash_overlap_1',
                'account_label' => 'Acme Supply Co',
                'company_name'  => 'Acme Supply Co',
                'customer_name' => 'John Doe',
                'customer_email'=> 'someone@unrelated-domain.com',
                'is_provisional'=> true,
            ],
        ]);

        // 2. Approved/Assigned finding is NOT yet work_in_progress
        Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_assigned_not_wip'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'status'                   => FindingStatus::Assigned->value,
            'execution_status'         => \App\Enums\FindingExecutionStatus::Assigned->value,
            'outcome_status'           => \App\Enums\FindingOutcomeStatus::PendingExecution->value,
            'estimated_revenue_impact' => 3000.00,
            'impact_nature'            => ImpactNature::HistoricalPurchasingEpisodeBenchmark->value,
            'title'                    => 'Assigned Enterprise Account',
            'evidence_json'            => [
                'account_hash'     => 'hash_assigned_1',
                'account_label'    => 'Beta Corp',
                'company_name'     => 'Beta Corp',
                'customer_name'    => 'Jane Smith',
                'customer_email'   => 'jane@beta-corp.com',
                'detector_version' => \App\Services\Intelligence\Detectors\LongTermWinbackDetector::VERSION,
                'cadence_threshold_days' => 180,
                'days_since_last_purchase' => 220,
                'days_overdue' => 40,
                'median_interval_days' => 60,
                'evaluated_at' => now()->toIso8601String(),
            ],
        ]);

        // 3. True work_in_progress finding
        Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_true_wip'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'status'                   => FindingStatus::Assigned->value,
            'execution_status'         => \App\Enums\FindingExecutionStatus::WorkInProgress->value,
            'outcome_status'           => \App\Enums\FindingOutcomeStatus::PendingExecution->value,
            'estimated_revenue_impact' => 4000.00,
            'impact_nature'            => ImpactNature::HistoricalPurchasingEpisodeBenchmark->value,
            'title'                    => 'Active WIP Enterprise Account',
            'evidence_json'            => [
                'account_hash'     => 'hash_wip_1',
                'account_label'    => 'Gamma Industrial',
                'company_name'     => 'Gamma Industrial',
                'customer_name'    => 'Bob Jones',
                'customer_email'   => 'bob@gamma-ind.com',
                'detector_version' => \App\Services\Intelligence\Detectors\LongTermWinbackDetector::VERSION,
                'cadence_threshold_days' => 180,
                'days_since_last_purchase' => 240,
                'days_overdue' => 60,
                'median_interval_days' => 70,
                'evaluated_at' => now()->toIso8601String(),
            ],
        ]);

        // Filtered to GoldKamp: hide redundant customer eyebrow on card, suppress Measured Outcomes,
        // deduplicate overlapping manual verification flags (1 account flagged, not multiple),
        // and show Work in Progress = 1 (true execution status, excluding merely Assigned finding).
        Livewire::test(BusinessInitiativesOverviewWidget::class, ['selectedClientId' => $this->goldkamp->id])
            ->assertSee('Awaiting Review')
            ->assertSee('Manual Verification')
            ->assertSee('1 account flagged for review')
            ->assertSee('Work in Progress')
            ->assertSee('1 approved/assigned')
            ->assertDontSee('Measured Outcomes')
            ->assertSee('1 awaiting review · 1 approved · 1 in progress');

        // Unfiltered (All Customers): customer eyebrow renders above title
        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->assertSeeInOrder(['GoldKamp', 'Enterprise Winback Campaign']);

        // When an action enters observation window with a finding-specific 45-day window,
        // sibling default-window findings must NOT override its 45-day duration
        $obs45 = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_in_observation_45'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'status'                   => FindingStatus::Resolved->value,
            'execution_status'         => \App\Enums\FindingExecutionStatus::ActionCompleted->value,
            'outcome_status'           => \App\Enums\FindingOutcomeStatus::AwaitingResults->value,
            'estimated_revenue_impact' => 5000.00,
            'impact_nature'            => ImpactNature::HistoricalPurchasingEpisodeBenchmark->value,
            'title'                    => 'Observed Enterprise Account (45d)',
            'metadata_json'            => ['attribution_window_days' => 45],
            'evidence_json'            => ['account_hash' => 'hash_obs_45'],
        ]);

        Livewire::test(BusinessInitiativesOverviewWidget::class, ['selectedClientId' => $this->goldkamp->id])
            ->assertSee('Measured Outcomes')
            ->assertSee('1 active')
            ->assertSee('In 45-day observation window')
            ->assertSee('1 in 45d observation');

        // When a second finding uses a distinct 60-day window, mixed-window summary stays accurate
        // without forcing a single false duration, while each finding retains its own window_days
        $obs60 = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_in_observation_60'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'status'                   => FindingStatus::Resolved->value,
            'execution_status'         => \App\Enums\FindingExecutionStatus::ActionCompleted->value,
            'outcome_status'           => \App\Enums\FindingOutcomeStatus::AwaitingResults->value,
            'estimated_revenue_impact' => 6000.00,
            'impact_nature'            => ImpactNature::HistoricalPurchasingEpisodeBenchmark->value,
            'title'                    => 'Observed Enterprise Account (60d)',
            'metadata_json'            => ['attribution_window_days' => 60],
            'evidence_json'            => ['account_hash' => 'hash_obs_60'],
        ]);

        Livewire::test(BusinessInitiativesOverviewWidget::class, ['selectedClientId' => $this->goldkamp->id])
            ->assertSee('2 active')
            ->assertSee('In active observation windows')
            ->assertSee('2 in observation');

        $svc = app(\App\Services\Intelligence\InitiativeAggregationService::class);
        $winbackInit = $svc->getInitiatives($this->goldkamp)->firstWhere('strategic_goal', 'Enterprise Winback Campaign');
        $acc45 = collect($winbackInit['accounts'])->firstWhere('finding_id', $obs45->id);
        $acc60 = collect($winbackInit['accounts'])->firstWhere('finding_id', $obs60->id);
        $this->assertSame(45, $acc45['outcome_presentation']['window_days']);
        $this->assertSame(60, $acc60['outcome_presentation']['window_days']);
    }
}
