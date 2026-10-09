<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\User;
use App\Services\Intelligence\ProjectDeliveryHealthService;
use App\Services\Intelligence\TimeTrackingService;
use App\Services\Intelligence\WorkPrioritizationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerSpaceFilterReportingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Client $validClientA;
    protected Client $validClientB;
    protected Client $clientWithoutJiraKey;
    protected PmConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'Jane Developer',
            'email'    => 'jane@technopath.ai',
            'password' => Hash::make('password123'),
        ]);

        $this->validClientA = Client::create([
            'name'                     => 'Cambro Manufacturing',
            'code'                     => 'CAMBRO',
            'jira_project_key'         => 'CMBR2',
            'status'                   => 'active',
            'monthly_allocated_hours'  => 80,
        ]);

        $this->validClientB = Client::create([
            'name'                     => 'Transpart Systems',
            'code'                     => 'TRANSPART',
            'jira_project_key'         => 'TRAN',
            'status'                   => 'active',
            'monthly_allocated_hours'  => 60,
        ]);

        $this->clientWithoutJiraKey = Client::create([
            'name'                     => 'Test Internal Lab',
            'code'                     => 'TESTLAB',
            'jira_project_key'         => null,
            'status'                   => 'active',
            'monthly_allocated_hours'  => 0,
        ]);

        $this->connection = PmConnection::create([
            'client_id'          => $this->validClientA->id,
            'provider'           => 'jira',
            'name'               => 'Technopath Jira',
            'base_url'           => 'https://technopath.atlassian.net',
            'status'             => 'active',
            'sync_health_status' => 'healthy',
            'last_synced_at'     => now(),
        ]);
    }

    public function test_pm_work_item_scope_for_customer_spaces_with_jira_code(): void
    {
        // 1. Valid item matching Cambro's Jira key
        $item1 = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'cmbr_1',
            'external_item_key'          => 'CMBR2-101',
            'summary'                    => 'Valid Cambro Task',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // 2. Valid item matching Transpart's Jira key
        $item2 = PmWorkItem::create([
            'client_id'                  => $this->validClientB->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'tran_1',
            'external_item_key'          => 'TRAN-205',
            'summary'                    => 'Valid Transpart Task',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // 3. Active Service Desk ticket attached to Cambro
        $item3 = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_1',
            'external_item_key'          => 'SUP-888',
            'summary'                    => 'Active Cambro service desk ticket',
            'external_status'            => 'Waiting for support',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // 4. Resolved Service Desk ticket attached to Cambro
        $itemResolved = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_res_1',
            'external_item_key'          => 'SUP-889',
            'summary'                    => 'Resolved Cambro service desk ticket',
            'external_status'            => 'Resolved',
            'normalized_delivery_status' => 'completed',
            'user_id'                    => $this->user->id,
        ]);

        // 5. Auto resolve Service Desk ticket attached to Cambro
        $itemAutoResolve = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_auto_1',
            'external_item_key'          => 'SUP-890',
            'summary'                    => 'Auto resolve Cambro ticket',
            'external_status'            => 'Auto resolve',
            'normalized_delivery_status' => 'completed',
            'user_id'                    => $this->user->id,
        ]);

        // 6. Random space (RPD) attached to a client
        $item6 = PmWorkItem::create([
            'client_id'                  => $this->validClientB->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'rpd_1',
            'external_item_key'          => 'RPD-42',
            'summary'                    => 'Random Space Task',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // 7. Item under client with null jira_project_key
        $item7 = PmWorkItem::create([
            'client_id'                  => $this->clientWithoutJiraKey->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'test_1',
            'external_item_key'          => 'TEST-1',
            'summary'                    => 'Test Lab Task',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // Global scope check: item1, item2, and active item3 should match
        // itemResolved, itemAutoResolve, item6 (RPD), item7 (TEST) must be excluded
        $allScopedItems = PmWorkItem::forCustomerSpacesWithJiraCode()->pluck('id')->all();
        $this->assertContains($item1->id, $allScopedItems);
        $this->assertContains($item2->id, $allScopedItems);
        $this->assertContains($item3->id, $allScopedItems);
        $this->assertNotContains($itemResolved->id, $allScopedItems);
        $this->assertNotContains($itemAutoResolve->id, $allScopedItems);
        $this->assertNotContains($item6->id, $allScopedItems);
        $this->assertNotContains($item7->id, $allScopedItems);
        $this->assertCount(3, $allScopedItems);

        // Client-specific scope check for Cambro: item1 and item3 (active SUP)
        $cambroScoped = PmWorkItem::forCustomerSpacesWithJiraCode($this->validClientA->id)->pluck('id')->all();
        $this->assertContains($item1->id, $cambroScoped);
        $this->assertContains($item3->id, $cambroScoped);
        $this->assertNotContains($itemResolved->id, $cambroScoped);
        $this->assertNotContains($itemAutoResolve->id, $cambroScoped);
        $this->assertCount(2, $cambroScoped);

        // Client-specific scope check for client without Jira key: empty
        $emptyScoped = PmWorkItem::forCustomerSpacesWithJiraCode($this->clientWithoutJiraKey->id)->pluck('id')->all();
        $this->assertEmpty($emptyScoped);
    }

    public function test_work_prioritization_engine_limits_to_customer_spaces_with_jira_code(): void
    {
        // Valid Cambro task
        PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'cmbr_valid_1',
            'external_item_key'          => 'CMBR2-55',
            'summary'                    => 'Cambro Core Work',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // Resolved SUP task assigned to same user
        PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_res_1',
            'external_item_key'          => 'SUP-321',
            'summary'                    => 'Resolved Service Desk Request',
            'external_status'            => 'Resolved',
            'normalized_delivery_status' => 'completed',
            'user_id'                    => $this->user->id,
        ]);

        // Non-customer space item assigned to same user
        PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'rpd_invalid_1',
            'external_item_key'          => 'RPD-99',
            'summary'                    => 'Internal Task',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        $engine = app(WorkPrioritizationEngine::class);
        $plan = $engine->getPrioritizedPlan($this->user);

        $keysInPlan = collect($plan['recommended_order'])->pluck('key')->all();
        $this->assertContains('CMBR2-55', $keysInPlan);
        $this->assertNotContains('SUP-321', $keysInPlan);
        $this->assertNotContains('RPD-99', $keysInPlan);
    }

    public function test_time_tracking_service_limits_to_customer_spaces_with_jira_code(): void
    {
        // Valid Cambro work item
        $cambroItem = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'cmbr_time_1',
            'external_item_key'          => 'CMBR2-77',
            'summary'                    => 'Cambro Development',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // Non-customer space work item
        $rpdItem = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'rpd_time_1',
            'external_item_key'          => 'RPD-77',
            'summary'                    => 'Non customer space work',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // Log 3 hours on Cambro
        PmWorklog::create([
            'client_id'            => $this->validClientA->id,
            'user_id'              => $this->user->id,
            'pm_connection_id'     => $this->connection->id,
            'pm_work_item_id'      => $cambroItem->id,
            'external_worklog_id'  => 'wl_cambro_1',
            'time_spent_seconds'   => 10800, // 3 hours
            'worklog_started_at'   => now(),
        ]);

        // Log 5 hours on RPD (should be excluded)
        PmWorklog::create([
            'client_id'            => $this->validClientA->id,
            'user_id'              => $this->user->id,
            'pm_connection_id'     => $this->connection->id,
            'pm_work_item_id'      => $rpdItem->id,
            'external_worklog_id'  => 'wl_rpd_1',
            'time_spent_seconds'   => 18000, // 5 hours
            'worklog_started_at'   => now(),
        ]);

        $timeService = app(TimeTrackingService::class);
        $userHours = $timeService->getUserMonthlyHours($this->user);

        // Only the 3 hours on CMBR2 should be reported
        $this->assertEquals(3.0, $userHours['total_hours']);
        $this->assertCount(1, $userHours['by_customer']);
        $this->assertEquals('Cambro Manufacturing', $userHours['by_customer'][0]['client_name']);
        $this->assertEquals(3.0, $userHours['by_customer'][0]['hours']);
    }

    public function test_project_delivery_health_service_ignores_clients_without_jira_project_code(): void
    {
        $healthService = app(ProjectDeliveryHealthService::class);

        // Client without Jira project key returns Unknown with specific reason
        $eval = $healthService->evaluateClientHealth($this->clientWithoutJiraKey);
        $this->assertEquals('Unknown', $eval['status']);
        $this->assertEquals('No Jira Project Code', $eval['summary']);

        // Client with Jira project key evaluates normally
        $evalA = $healthService->evaluateClientHealth($this->validClientA);
        $this->assertNotEquals('No Jira Project Code', $evalA['summary']);
    }

    public function test_engineer_dashboard_page_filters_to_customer_spaces(): void
    {
        // Valid Cambro work item
        PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'cmbr_eng_1',
            'external_item_key'          => 'CMBR2-99',
            'summary'                    => 'Important Feature for Cambro',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
            'priority'                   => 'High',
        ]);

        // Resolved SUP ticket
        PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_eng_res',
            'external_item_key'          => 'SUP-999',
            'summary'                    => 'Resolved support ticket that should not appear',
            'external_status'            => 'Resolved',
            'normalized_delivery_status' => 'completed',
            'user_id'                    => $this->user->id,
            'priority'                   => 'High',
        ]);

        $response = $this->actingAs($this->user)->get('/admin/engineer-dashboard');
        $response->assertSuccessful();
        $response->assertSee('CMBR2-99');
        $response->assertDontSee('SUP-999');
    }

    public function test_customer_attention_items_scope_unresolved_excludes_resolved_tickets(): void
    {
        $resolvedTicket = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_att_res',
            'external_item_key'          => 'SUP-9396',
            'summary'                    => 'Tax Holiday',
            'external_status'            => 'Resolved',
            'normalized_delivery_status' => 'completed',
            'user_id'                    => $this->user->id,
        ]);

        $activeTicket = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_att_act',
            'external_item_key'          => 'SUP-9555',
            'summary'                    => 'Active Ticket',
            'external_status'            => 'Waiting for customer',
            'normalized_delivery_status' => 'customer_review',
            'user_id'                    => $this->user->id,
        ]);

        $resolvedAtt = \App\Models\CustomerAttentionItem::create([
            'client_id'   => $this->validClientA->id,
            'source_type' => 'pm_work_item',
            'source_id'   => (string) $resolvedTicket->id,
            'category'    => 'waiting_on_customer',
            'title'       => 'SUP-9396: Tax Holiday',
            'is_resolved' => false,
        ]);

        $activeAtt = \App\Models\CustomerAttentionItem::create([
            'client_id'   => $this->validClientA->id,
            'source_type' => 'pm_work_item',
            'source_id'   => (string) $activeTicket->id,
            'category'    => 'waiting_on_customer',
            'title'       => 'SUP-9555: Active Ticket',
            'is_resolved' => false,
        ]);

        $unresolvedItems = \App\Models\CustomerAttentionItem::unresolved()->pluck('id')->all();
        $this->assertContains($activeAtt->id, $unresolvedItems);
        $this->assertNotContains($resolvedAtt->id, $unresolvedItems);
    }
}
