<?php

namespace Tests\Feature\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\Integration;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\RecommendationOutcome;
use App\Models\User;
use App\Services\Intelligence\InitiativeAggregationService;
use App\Services\Intelligence\OutcomeMeasurementService;
use App\Services\Intelligence\WorkPrioritizationEngine;
use App\Services\PM\FindingPmSyncService;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PilotValidationEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected Client $goldkamp;
    protected Client $cambro;
    protected User $admin;
    protected User $goldkampUser;
    protected User $cambroUser;
    protected PmConnection $goldkampConn;
    protected PmConnection $cambroConn;
    protected PmProject $goldkampProject;
    protected PmProject $cambroProject;
    protected Integration $goldkampIntegration;
    protected Integration $cambroIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Goldkamp Client
        $this->goldkamp = Client::create([
            'name'                    => 'Goldkamp Equipment',
            'status'                  => 'active',
            'jira_project_key'        => 'GOLD',
            'monthly_allocated_hours' => 100.0,
        ]);

        // 2. Setup Cambro Client
        $this->cambro = Client::create([
            'name'                    => 'Cambro Manufacturing',
            'status'                  => 'active',
            'jira_project_key'        => 'CAMB',
            'monthly_allocated_hours' => 80.0,
        ]);

        // 3. Setup Users
        $this->admin = User::factory()->create([
            'name'     => 'System Admin',
            'email'    => 'admin@technopath.test',
            'is_admin' => true,
        ]);

        $role = \App\Models\Role::firstOrCreate(
            ['name' => \App\Models\Role::ROLE_CUSTOMER_ADMIN],
            ['display_name' => 'Customer Admin']
        );

        $this->goldkampUser = User::factory()->create([
            'name'     => 'Brian Goldkamp',
            'email'    => 'brian@goldkamp.test',
            'is_admin' => false,
        ]);
        \App\Models\UserRoleAssignment::create([
            'user_id'   => $this->goldkampUser->id,
            'role_id'   => $role->id,
            'client_id' => $this->goldkamp->id,
            'is_active' => true,
        ]);

        $this->cambroUser = User::factory()->create([
            'name'     => 'Carol Cambro',
            'email'    => 'carol@cambro.test',
            'is_admin' => false,
        ]);
        \App\Models\UserRoleAssignment::create([
            'user_id'   => $this->cambroUser->id,
            'role_id'   => $role->id,
            'client_id' => $this->cambro->id,
            'is_active' => true,
        ]);

        // 4. Setup PM Connections and Projects
        $this->goldkampConn = PmConnection::create([
            'client_id'          => $this->goldkamp->id,
            'provider'           => 'jira',
            'name'               => 'Goldkamp Jira',
            'is_active'          => true,
            'configuration_json' => ['base_url' => 'https://goldkamp.atlassian.net'],
        ]);

        $this->goldkampProject = PmProject::create([
            'client_id'            => $this->goldkamp->id,
            'pm_connection_id'     => $this->goldkampConn->id,
            'external_project_id'  => 'proj_gold_1',
            'external_project_key' => 'GOLD',
            'name'                 => 'Goldkamp Platform',
        ]);

        $this->cambroConn = PmConnection::create([
            'client_id'          => $this->cambro->id,
            'provider'           => 'jira',
            'name'               => 'Cambro Jira',
            'is_active'          => true,
            'configuration_json' => ['base_url' => 'https://cambro.atlassian.net'],
        ]);

        $this->cambroProject = PmProject::create([
            'client_id'            => $this->cambro->id,
            'pm_connection_id'     => $this->cambroConn->id,
            'external_project_id'  => 'proj_camb_1',
            'external_project_key' => 'CAMB',
            'name'                 => 'Cambro B2B',
        ]);

        // 5. Setup Integrations
        $this->goldkampIntegration = Integration::create([
            'client_id'        => $this->goldkamp->id,
            'integration_type' => IntegrationType::AdobeCommerce,
            'status'           => IntegrationStatus::Active,
        ]);

        $this->cambroIntegration = Integration::create([
            'client_id'        => $this->cambro->id,
            'integration_type' => IntegrationType::AdobeCommerce,
            'status'           => IntegrationStatus::Active,
        ]);
    }

    protected function createFinding(Client $client, array $attributes = []): Finding
    {
        return Finding::create(array_merge([
            'client_id'                => $client->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', uniqid('pilot_finding_', true)),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => 'Revenue',
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::Approved->value,
            'execution_status'         => FindingExecutionStatus::Approved->value,
            'outcome_status'           => FindingOutcomeStatus::PendingExecution->value,
            'title'                    => 'Pilot Opportunity',
            'description'              => 'Opportunity description',
            'estimated_revenue_impact' => 5000.00,
        ], $attributes));
    }

    public function test_multi_tenant_customer_isolation_fails_closed_across_goldkamp_and_cambro(): void
    {
        $this->actingAs($this->goldkampUser);

        // Goldkamp finding
        $goldFinding = $this->createFinding($this->goldkamp, ['title' => 'Goldkamp Account Expansion']);
        
        // Cambro finding
        $cambFinding = $this->createFinding($this->cambro, ['title' => 'Cambro Cart Recovery']);

        // Goldkamp user can query Goldkamp findings but not Cambro findings
        $scopedFindings = Finding::whereIn('client_id', $this->goldkampUser->getAssignedClientIds())->get();
        $this->assertTrue($scopedFindings->contains('id', $goldFinding->id));
        $this->assertFalse($scopedFindings->contains('id', $cambFinding->id));

        // Linking Cambro finding to Goldkamp project throws AuthorizationException
        $syncService = app(FindingPmSyncService::class);
        $workItem = PmWorkItem::create([
            'client_id'                  => $this->goldkamp->id,
            'pm_connection_id'           => $this->goldkampConn->id,
            'external_item_id'           => 'gk_100',
            'external_item_key'          => 'GOLD-100',
            'summary'                    => 'Goldkamp Task',
            'external_status'            => 'To Do',
            'normalized_delivery_status' => 'to_do',
        ]);

        $this->expectException(AuthorizationException::class);
        $syncService->linkFindingToWorkItem($cambFinding, $workItem, $this->goldkampUser);
    }

    public function test_goldkamp_reconciliation_of_six_proven_accounts_and_batch_approval_of_five_eligible_accounts(): void
    {
        $this->actingAs($this->admin);

        // Goldkamp has 6 proven accounts total:
        // 1 is already approved
        $alreadyApproved = $this->createFinding($this->goldkamp, [
            'title'            => 'Proven Account 1 (Previously Approved)',
            'status'           => FindingStatus::Approved->value,
            'execution_status' => FindingExecutionStatus::Assigned->value,
            'confidence_score' => 95.0,
            'metadata_json'    => ['qualification_tier' => 'proven', 'customer_id' => 'gk_cust_1'],
        ]);

        // 5 are pending review and eligible for batch approval
        $pendingFindings = [];
        for ($i = 2; $i <= 6; $i++) {
            $pendingFindings[] = $this->createFinding($this->goldkamp, [
                'title'            => "Proven Account {$i} (Pending Review)",
                'status'           => FindingStatus::UnderReview->value,
                'execution_status' => FindingExecutionStatus::UnderReview->value,
                'confidence_score' => 92.0,
                'metadata_json'    => ['qualification_tier' => 'proven', 'customer_id' => "gk_cust_{$i}"],
            ]);
        }

        // Verify total proven accounts is 6
        $allProven = Finding::where('client_id', $this->goldkamp->id)
            ->where('metadata_json->qualification_tier', 'proven')
            ->get();
        $this->assertCount(6, $allProven, 'Goldkamp has exactly 6 listed proven accounts.');

        // Batch approval eligible filter (must be UnderReview or New, not already approved)
        $eligibleForBatch = Finding::where('client_id', $this->goldkamp->id)
            ->where('metadata_json->qualification_tier', 'proven')
            ->whereIn('status', [FindingStatus::UnderReview->value, FindingStatus::New->value])
            ->get();

        $this->assertCount(5, $eligibleForBatch, 'Exactly 5 accounts are eligible for batch approval.');

        // Execute batch approval on the 5 eligible accounts
        foreach ($eligibleForBatch as $finding) {
            $finding->transitionTo(FindingStatus::Approved, $this->admin, 'Batch approved via Management Overview');
        }

        // Verify all 6 are now approved
        $nowApproved = Finding::where('client_id', $this->goldkamp->id)
            ->where('metadata_json->qualification_tier', 'proven')
            ->where('status', FindingStatus::Approved->value)
            ->count();
        $this->assertEquals(6, $nowApproved, 'All 6 proven accounts are approved following batch approval.');
    }

    public function test_end_to_end_pilot_flow_from_pm_delivery_to_defensible_outcome_measurement(): void
    {
        $this->actingAs($this->admin);

        // 1. Setup Cambro Opportunity
        $finding = $this->createFinding($this->cambro, [
            'title'                    => 'Reactivate Inactive Distributor',
            'status'                   => FindingStatus::Approved->value,
            'execution_status'         => FindingExecutionStatus::Approved->value,
            'estimated_revenue_impact' => 14000.00,
            'metadata_json'            => ['registered_customer_id' => 'distributor_888'],
        ]);

        // 2. Execution Integration: Link to PM ticket & mark In Progress
        $workItem = PmWorkItem::create([
            'client_id'                  => $this->cambro->id,
            'pm_connection_id'           => $this->cambroConn->id,
            'external_item_id'           => 'camb_ticket_1',
            'external_item_key'          => 'CAMB-2166',
            'summary'                    => 'Distributor Outreach Program',
            'external_status'            => 'In Progress',
            'normalized_delivery_status' => 'in_progress',
        ]);

        $syncService = app(FindingPmSyncService::class);
        $syncService->linkFindingToWorkItem($finding, $workItem, $this->admin);

        $finding->refresh();
        $this->assertEquals(FindingExecutionStatus::WorkInProgress, $finding->execution_status);
        $this->assertEquals(FindingOutcomeStatus::PendingExecution, $finding->outcome_status);

        // 3. Mark PM Ticket as Done -> Finding transitions to ActionCompleted and AwaitingResults
        $workItem->update(['external_status' => 'Done', 'normalized_delivery_status' => 'done']);
        $syncService->syncWorkItemToFinding($workItem);

        $finding->refresh();
        $this->assertEquals(FindingExecutionStatus::ActionCompleted, $finding->execution_status);
        $this->assertEquals(FindingOutcomeStatus::AwaitingResults, $finding->outcome_status);
        $this->assertNotNull($finding->action_completed_at);

        // 4. Record post-action Commerce Order of $18,000
        $order = CommerceOrder::create([
            'client_id'              => $this->cambro->id,
            'integration_id'         => $this->cambroIntegration->id,
            'source'                 => 'magento',
            'source_order_id'        => 'ord_camb_dist_1',
            'source_increment_id'    => 'SO-88801',
            'order_status'           => 'complete',
            'registered_customer_id' => 'distributor_888',
            'order_date'             => now(),
            'gross_revenue'          => 18000.00,
            'net_revenue'            => 18000.00,
            'currency'               => 'USD',
            'is_valid'               => true,
        ]);

        // 5. Outcome Measurement: Calculate Defensible Incremental Impact
        // Expected historical baseline is $4,000 -> Net incremental lift is $14,000
        $outcomeService = app(OutcomeMeasurementService::class);
        $outcome = $outcomeService->evaluateFindingOutcome($finding, $this->admin, [
            'attribution_method'        => OutcomeMeasurementService::METHOD_DORMANT_BASELINE,
            'baseline_expected_revenue' => 4000.00,
        ]);

        $this->assertInstanceOf(RecommendationOutcome::class, $outcome);
        $this->assertEquals(18000.00, (float) $outcome->observed_revenue);
        $this->assertEquals(4000.00, (float) $outcome->baseline_expected_revenue);
        $this->assertEquals(14000.00, (float) $outcome->actual_incremental_impact);
        $this->assertEquals(1, $outcome->orders_count);
        $this->assertFalse($outcome->is_zero_outcome);

        $finding->refresh();
        $this->assertEquals(FindingOutcomeStatus::OutcomeMeasured, $finding->outcome_status);
    }
}
