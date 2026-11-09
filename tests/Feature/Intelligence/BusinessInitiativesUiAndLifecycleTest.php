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
            ->assertSee('$6,000.00')
            ->assertSee('Historical Reorder Value')
            ->assertSee('Executive Sales');
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
            ->assertSee('All Commercial Opportunities')
            ->assertSee('Technical & Data Health')
            ->assertSee('Resolved / Dismissed');
    }
}
