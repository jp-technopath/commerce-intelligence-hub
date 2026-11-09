<?php

namespace Tests\Feature\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ImpactNature;
use App\Filament\Widgets\BusinessInitiativesOverviewWidget;
use App\Models\Client;
use App\Models\Finding;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\Intelligence\InitiativeAggregationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessInitiativesSecurityAndSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $cambroOnlyUser;
    protected User $goldkampUser;
    protected Client $goldkamp;
    protected Client $cambro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

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

        // Super Admin
        $this->superAdmin = User::factory()->create([
            'name'     => 'Super Admin',
            'email'    => 'admin@technopath.co',
            'is_admin' => true,
        ]);
        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->first();
        UserRoleAssignment::create([
            'user_id'   => $this->superAdmin->id,
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);

        // Cambro-restricted User
        $this->cambroOnlyUser = User::factory()->create([
            'name'     => 'Irem Aydin',
            'email'    => 'irem@cambro.com',
            'is_admin' => false,
        ]);
        $analystRole = Role::where('name', Role::ROLE_ANALYST)->first();
        UserRoleAssignment::create([
            'user_id'   => $this->cambroOnlyUser->id,
            'role_id'   => $analystRole->id,
            'client_id' => $this->cambro->id,
            'is_active' => true,
        ]);

        // Goldkamp Authorized User
        $this->goldkampUser = User::factory()->create([
            'name'     => 'Jean Paul Goldkamp Lead',
            'email'    => 'jp@goldkamp.com',
            'is_admin' => false,
        ]);
        UserRoleAssignment::create([
            'user_id'   => $this->goldkampUser->id,
            'role_id'   => $analystRole->id,
            'client_id' => $this->goldkamp->id,
            'is_active' => true,
        ]);
    }

    public function test_cross_customer_isolation_blocks_unauthorized_direct_service_query(): void
    {
        $service = app(InitiativeAggregationService::class);

        // Cambro user trying to query Goldkamp client directly must be blocked
        $this->expectException(AuthorizationException::class);
        $service->getInitiatives($this->goldkamp, null, $this->cambroOnlyUser);
    }

    public function test_cli_and_job_execution_context_runs_without_authenticated_user(): void
    {
        $service = app(InitiativeAggregationService::class);

        // 1. Fail-closed: In console/worker without logged-in user and without ClientScopeContext, it MUST throw AuthorizationException
        try {
            $service->getInitiatives($this->goldkamp, null, null);
            $this->fail('Expected AuthorizationException when running without client scope context');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertStringContainsString('Unauthenticated execution without valid client scope context', $e->getMessage());
        }

        // 2. Authorized background execution: With explicit ClientScopeContext::forClient(), execution succeeds
        $initiatives = \App\Services\Security\ClientScopeContext::forClient($this->goldkamp, function () use ($service) {
            return $service->getInitiatives($this->goldkamp, null, null);
        });

        $this->assertNotNull($initiatives);

        // 3. Trusted system maintenance context: System maintenance allows portfolio-wide processing
        $portfolioInitiatives = \App\Services\Security\ClientScopeContext::forSystemMaintenance('Nightly reconciliation', function () use ($service) {
            return $service->getInitiatives(null, null, null);
        });

        $this->assertNotNull($portfolioInitiatives);
    }

    public function test_assignable_users_strictly_scopes_to_customer_eligible_members(): void
    {
        // Goldkamp should only show Goldkamp users and Super Admin, never Cambro-only users
        $eligibleForGoldkamp = User::getAuthorizedUsersForClient($this->goldkamp->id);

        $this->assertTrue($eligibleForGoldkamp->contains('id', $this->goldkampUser->id));
        $this->assertTrue($eligibleForGoldkamp->contains('id', $this->superAdmin->id));
        $this->assertFalse($eligibleForGoldkamp->contains('id', $this->cambroOnlyUser->id));

        // Cambro should only show Cambro users and Super Admin, never Goldkamp-only users
        $eligibleForCambro = User::getAuthorizedUsersForClient($this->cambro->id);

        $this->assertTrue($eligibleForCambro->contains('id', $this->cambroOnlyUser->id));
        $this->assertTrue($eligibleForCambro->contains('id', $this->superAdmin->id));
        $this->assertFalse($eligibleForCambro->contains('id', $this->goldkampUser->id));
    }

    public function test_assigning_finding_to_unauthorized_user_throws_exception(): void
    {
        $finding = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_assign_test'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 1200.00,
            'title'                    => 'Goldkamp Wholesale Account',
            'metadata_json'            => ['account_label' => 'GK Acc #1'],
        ]);

        $this->expectException(ValidationException::class);
        // Attempting to assign Goldkamp finding to Cambro-only user must throw ValidationException
        $finding->transitionTo(FindingStatus::Assigned, $this->superAdmin, 'Assign note', $this->cambroOnlyUser);
    }

    public function test_financial_presentation_prioritizes_verified_reorders_and_segregates_provisional_accounts(): void
    {
        // Create 2 proven repeat accounts
        Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_proven_1'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 1500.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'title'                    => 'Proven Repeat Account #1',
            'metadata_json'            => [
                'detector_key'  => 'opportunity.wholesale_retention',
                'account_label' => 'Proven #1',
            ],
            'evidence_json'            => [
                'lifetime_orders' => 6,
                'cadence_days'    => 45,
            ],
        ]);

        Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_proven_2'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 2329.63,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'title'                    => 'Proven Repeat Account #2',
            'metadata_json'            => [
                'detector_key'  => 'opportunity.wholesale_retention',
                'account_label' => 'Proven #2',
            ],
            'evidence_json'            => [
                'lifetime_orders' => 4,
                'cadence_days'    => 30,
            ],
        ]);

        // Create 1 provisional account (e.g. only 2 orders, directional impact)
        Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'gk_provisional_1'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::Medium->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 4927.15,
            'impact_nature'            => ImpactNature::DirectionalOpportunity->value,
            'title'                    => 'Provisional 2-Order Account #3',
            'metadata_json'            => [
                'detector_key'  => 'opportunity.wholesale_retention',
                'account_label' => 'Provisional #3',
            ],
            'evidence_json'            => [
                'lifetime_orders'        => 2,
                'is_provisional'         => true,
                'cadence_evidence_level' => 'limited_two_orders',
            ],
        ]);

        $service = app(InitiativeAggregationService::class);
        $initiatives = $service->getInitiatives($this->goldkamp, null, $this->superAdmin);

        $this->assertCount(1, $initiatives);
        $init = $initiatives->first();

        // Must prioritize verified historical purchasing evidence: 1500 + 2329.63 = $3,829.63
        $this->assertEquals(3829.63, $init['primary_financial_value']);
        $this->assertEquals('historical_reorder_value', $init['primary_financial_nature']);
        $this->assertEquals('$3,829.63', $init['formatted_primary_financial']);

        // Provisional accounts must be segregated
        $this->assertEquals(2, $init['proven_accounts_count']);
        $this->assertEquals(1, $init['provisional_accounts_count']);
        $this->assertEquals(4927.15, $init['provisional_spend_total']);
        $this->assertTrue($init['has_provisional_accounts']);
        $this->assertStringContainsString('1 provisional account(s) require manual review', $init['provisional_notice']);

        // In accounts drill-down, provisional account must have financial_impact = 0.0 and prior_spend preserved
        $provisionalRow = collect($init['accounts'])->firstWhere('account_label', 'Provisional #3');
        $this->assertNotNull($provisionalRow);
        $this->assertTrue($provisionalRow['is_provisional']);
        $this->assertEquals(0.0, $provisionalRow['financial_impact']);
        $this->assertEquals(4927.15, $provisionalRow['prior_spend']);
    }

    public function test_batch_approval_safeguard_excludes_provisional_and_already_approved_accounts(): void
    {
        $this->actingAs($this->superAdmin);

        // Proven account 1
        $proven1 = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'batch_proven_1'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 1000.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'title'                    => 'Batch Proven 1',
            'metadata_json'            => ['detector_key' => 'opportunity.wholesale_retention', 'account_label' => 'Acc 1'],
            'evidence_json'            => ['lifetime_orders' => 5],
        ]);

        // Proven account 2 (Already approved)
        $proven2 = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'batch_proven_2'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::Approved->value,
            'estimated_revenue_impact' => 1200.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'title'                    => 'Batch Proven 2 (Already Approved)',
            'metadata_json'            => ['detector_key' => 'opportunity.wholesale_retention', 'account_label' => 'Acc 2'],
            'evidence_json'            => ['lifetime_orders' => 4],
        ]);

        // Provisional account 3 (Should be skipped by batch approve)
        $provisional = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'batch_prov_3'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::Medium->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 2000.00,
            'impact_nature'            => ImpactNature::DirectionalOpportunity->value,
            'title'                    => 'Batch Provisional 3',
            'metadata_json'            => ['detector_key' => 'opportunity.wholesale_retention', 'account_label' => 'Acc 3'],
            'evidence_json'            => ['is_provisional' => true, 'lifetime_orders' => 2],
        ]);

        $service = app(InitiativeAggregationService::class);
        $initiatives = $service->getInitiatives($this->goldkamp, null, $this->superAdmin);
        $initiativeId = $initiatives->first()['id'];

        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->call('openBatchApproveModal', $initiativeId)
            ->assertSet('batchApproveInitiativeId', $initiativeId)
            ->assertSee('Batch Approval Confirmation')
            ->assertSee('Proven repeat accounts eligible for immediate approval')
            ->call('confirmBatchApprove');

        // Verify proven1 transitioned to approved
        $proven1->refresh();
        $this->assertEquals(FindingStatus::Approved, $proven1->status);

        // Verify provisional was NOT batch-approved and remains new
        $provisional->refresh();
        $this->assertEquals(FindingStatus::New, $provisional->status);

        // Verify proven2 was unaffected
        $proven2->refresh();
        $this->assertEquals(FindingStatus::Approved, $proven2->status);
    }

    public function test_resolution_requires_meaningful_notes_and_records_business_outcome_without_mutating_revenue(): void
    {
        $this->actingAs($this->superAdmin);

        $finding = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'resolve_test_01'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 3500.00,
            'impact_nature'            => ImpactNature::HistoricalReorderValue->value,
            'title'                    => 'Resolve Test Finding',
            'metadata_json'            => ['account_label' => 'Resolve Test'],
        ]);

        // Attempting to resolve with notes < 10 characters in widget should fail validation
        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->call('openResolveModal', $finding->id)
            ->set('resolutionNotes', 'Short')
            ->set('businessOutcome', 'reorder_secured')
            ->call('confirmResolve')
            ->assertHasErrors(['resolutionNotes']);

        // Submitting with valid notes >= 10 characters should succeed
        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->call('openResolveModal', $finding->id)
            ->set('resolutionNotes', 'Customer placed $3,500 repeat order via wholesale portal.')
            ->set('businessOutcome', 'reorder_secured')
            ->call('confirmResolve')
            ->assertHasNoErrors();

        $finding->refresh();
        $this->assertEquals(FindingStatus::Resolved, $finding->status);

        // Revenue impact must be preserved and not modified or zeroed out
        $this->assertEquals(3500.00, (float) $finding->estimated_revenue_impact);

        // Audit history must contain business outcome and user details
        $history = $finding->status_history_json;
        $this->assertNotEmpty($history);
        $lastEntry = end($history);
        $this->assertEquals('resolved', $lastEntry['to_status']);
        $this->assertEquals('reorder_secured', $lastEntry['business_outcome']);
        $this->assertEquals($this->superAdmin->name, $lastEntry['changed_by_name']);
    }

    public function test_unauthorized_user_blocked_from_widget_finding_actions(): void
    {
        $this->actingAs($this->cambroOnlyUser);

        $finding = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'unauth_widget_action'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::New->value,
            'estimated_revenue_impact' => 2000.00,
            'title'                    => 'Goldkamp Finding',
            'metadata_json'            => ['account_label' => 'GK Acc'],
        ]);

        Livewire::test(BusinessInitiativesOverviewWidget::class)
            ->call('approveFinding', $finding->id)
            ->assertForbidden();
    }

    public function test_outcome_measurement_fails_closed_without_authorized_client_scope_context(): void
    {
        $finding = Finding::create([
            'client_id'                => $this->goldkamp->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'outcome_sec_test'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => FindingCategory::Revenue->value,
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::Approved->value,
            'execution_status'         => \App\Enums\FindingExecutionStatus::ActionCompleted->value,
            'action_completed_at'      => now()->subDays(5),
            'estimated_revenue_impact' => 5000.00,
            'title'                    => 'Goldkamp Outreach',
            'metadata_json'            => ['account_label' => 'GK Acc'],
        ]);

        $service = app(\App\Services\Intelligence\OutcomeMeasurementService::class);

        // 1. Cross-client user is blocked
        try {
            $service->evaluateFindingOutcome($finding, $this->cambroOnlyUser);
            $this->fail('Expected AuthorizationException for cross-client outcome evaluation');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('is not authorized for customer ID', $e->getMessage());
        }

        // 2. Unauthenticated background execution without ClientScopeContext is blocked
        try {
            $service->evaluateFindingOutcome($finding, null);
            $this->fail('Expected AuthorizationException for unauthenticated outcome evaluation without context');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Unauthenticated execution without valid client scope context', $e->getMessage());
        }

        // 3. Execution succeeds when wrapped in authorized ClientScopeContext
        $outcome = \App\Services\Security\ClientScopeContext::forClient($this->goldkamp, function () use ($service, $finding) {
            return $service->evaluateFindingOutcome($finding, null, ['force_finalize' => true]);
        });
        $this->assertInstanceOf(\App\Models\RecommendationOutcome::class, $outcome);
    }

    public function test_work_prioritization_engine_fails_closed_without_authorized_client_scope_context(): void
    {
        $engine = app(\App\Services\Intelligence\WorkPrioritizationEngine::class);

        // 1. Cross-client user is blocked from viewing Goldkamp plan
        try {
            $engine->getPrioritizedPlanForRole(\App\Services\Intelligence\WorkPrioritizationEngine::ROLE_SALES, $this->goldkamp->id, $this->cambroOnlyUser);
            $this->fail('Expected AuthorizationException for cross-client plan access');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('is not authorized for customer ID', $e->getMessage());
        }

        // 2. Unauthenticated background execution without ClientScopeContext is blocked
        try {
            $engine->getPrioritizedPlanForRole(\App\Services\Intelligence\WorkPrioritizationEngine::ROLE_SALES, $this->goldkamp->id, null);
            $this->fail('Expected AuthorizationException for unauthenticated plan access without context');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Unauthenticated execution without valid client scope context', $e->getMessage());
        }

        // 3. Execution succeeds when wrapped in authorized ClientScopeContext
        $plan = \App\Services\Security\ClientScopeContext::forClient($this->goldkamp, function () use ($engine) {
            return $engine->getPrioritizedPlanForRole(\App\Services\Intelligence\WorkPrioritizationEngine::ROLE_SALES, $this->goldkamp->id, null);
        });
        $this->assertIsArray($plan);
        $this->assertArrayHasKey('recommended_order', $plan);
    }
}
