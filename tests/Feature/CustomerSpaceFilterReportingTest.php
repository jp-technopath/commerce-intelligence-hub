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

        // 3. Service Desk / non-customer space attached to Cambro
        $item3 = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_1',
            'external_item_key'          => 'SUP-888',
            'summary'                    => 'Service desk ticket not matching Jira key',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // 4. Random space (RPD) attached to a client
        $item4 = PmWorkItem::create([
            'client_id'                  => $this->validClientB->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'rpd_1',
            'external_item_key'          => 'RPD-42',
            'summary'                    => 'Random Space Task',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // 5. Item under client with null jira_project_key
        $item5 = PmWorkItem::create([
            'client_id'                  => $this->clientWithoutJiraKey->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'test_1',
            'external_item_key'          => 'TEST-1',
            'summary'                    => 'Test Lab Task',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        // Global scope check: only item1 and item2 should match
        $allScopedItems = PmWorkItem::forCustomerSpacesWithJiraCode()->pluck('id')->all();
        $this->assertContains($item1->id, $allScopedItems);
        $this->assertContains($item2->id, $allScopedItems);
        $this->assertNotContains($item3->id, $allScopedItems);
        $this->assertNotContains($item4->id, $allScopedItems);
        $this->assertNotContains($item5->id, $allScopedItems);
        $this->assertCount(2, $allScopedItems);

        // Client-specific scope check for Cambro: only item1
        $cambroScoped = PmWorkItem::forCustomerSpacesWithJiraCode($this->validClientA->id)->pluck('id')->all();
        $this->assertEquals([$item1->id], $cambroScoped);

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

        // Non-customer space item assigned to same user
        PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_invalid_1',
            'external_item_key'          => 'SUP-321',
            'summary'                    => 'Internal Service Request',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
        ]);

        $engine = app(WorkPrioritizationEngine::class);
        $plan = $engine->getPrioritizedPlan($this->user);

        $keysInPlan = collect($plan['recommended_order'])->pluck('key')->all();
        $this->assertContains('CMBR2-55', $keysInPlan);
        $this->assertNotContains('SUP-321', $keysInPlan);
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
        $supItem = PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_time_1',
            'external_item_key'          => 'SUP-77',
            'summary'                    => 'Service desk work',
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

        // Log 5 hours on SUP (should be excluded)
        PmWorklog::create([
            'client_id'            => $this->validClientA->id,
            'user_id'              => $this->user->id,
            'pm_connection_id'     => $this->connection->id,
            'pm_work_item_id'      => $supItem->id,
            'external_worklog_id'  => 'wl_sup_1',
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

        // Non-customer space item
        PmWorkItem::create([
            'client_id'                  => $this->validClientA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'sup_eng_1',
            'external_item_key'          => 'SUP-999',
            'summary'                    => 'Internal ticket that should not appear',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->user->id,
            'priority'                   => 'High',
        ]);

        $response = $this->actingAs($this->user)->get('/admin/engineer-dashboard');
        $response->assertSuccessful();
        $response->assertSee('CMBR2-99');
        $response->assertDontSee('SUP-999');
    }
}
