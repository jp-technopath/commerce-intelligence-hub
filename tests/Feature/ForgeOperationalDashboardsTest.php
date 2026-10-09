<?php

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Models\Client;
use App\Models\ConnectedAccount;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\Project;
use App\Models\User;
use App\Services\Intelligence\ProjectAllocationHealthService;
use App\Services\Intelligence\ProjectDeliveryHealthService;
use App\Services\Intelligence\TimeTrackingService;
use App\Services\Intelligence\WorkPrioritizationEngine;
use App\Services\PM\IdentityResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForgeOperationalDashboardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $engineer;
    protected User $manager;
    protected Client $client;
    protected PmConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name'                     => 'Acme Retail',
            'code'                     => 'ACME',
            'jira_project_key'         => 'ACME',
            'status'                   => 'active',
            'monthly_allocated_hours'  => 100,
        ]);

        $this->engineer = User::create([
            'name'     => 'Alice Engineer',
            'email'    => 'alice@technopath.ai',
            'password' => Hash::make('secret123'),
        ]);

        $this->manager = User::create([
            'name'     => 'Bob Manager',
            'email'    => 'bob@technopath.ai',
            'password' => Hash::make('secret123'),
            'is_admin' => true,
        ]);

        $this->connection = PmConnection::create([
            'client_id'      => $this->client->id,
            'provider'       => 'jira',
            'name'           => 'Acme Jira',
            'status'         => 'active',
            'last_synced_at' => now(),
        ]);
    }

    public function test_identity_resolver_maps_by_provider_and_external_account_id(): void
    {
        ConnectedAccount::create([
            'user_id'             => $this->engineer->id,
            'provider'            => 'jira',
            'external_account_id' => 'jira_account_alice_123',
            'authorized_email'    => 'alice@external.com',
            'status'              => ConnectedAccountStatus::Active,
        ]);

        $resolver = app(IdentityResolverService::class);

        // 1. Resolve via provider + external_account_id
        $resolvedId = $resolver->resolveUserId('jira', 'jira_account_alice_123');
        $this->assertEquals($this->engineer->id, $resolvedId);

        // 2. Resolve via verified email fallback
        $resolvedByEmail = $resolver->resolveUserId('jira', 'unknown_id', 'alice@technopath.ai');
        $this->assertEquals($this->engineer->id, $resolvedByEmail);

        // 3. Unresolvable returns null
        $unresolvable = $resolver->resolveUserId('jira', 'unknown_id', 'unknown@external.com');
        $this->assertNull($unresolvable);
    }

    public function test_time_tracking_service_calculates_user_and_manager_hours_with_unattributed(): void
    {
        $timeService = app(TimeTrackingService::class);

        $item = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'ext_time_1',
            'external_item_key'          => 'ACME-TIME-1',
            'summary'                    => 'Time tracking test item',
            'normalized_delivery_status' => 'in_progress',
        ]);

        // Log 10 hours for Alice
        PmWorklog::create([
            'client_id'           => $this->client->id,
            'pm_connection_id'    => $this->connection->id,
            'pm_work_item_id'     => $item->id,
            'user_id'             => $this->engineer->id,
            'external_author_id'  => 'alice_jira',
            'author_name'         => 'Alice Engineer',
            'external_worklog_id' => 'wl_1',
            'time_spent_seconds'  => 10 * 3600,
            'worklog_started_at'  => now(),
        ]);

        // Log 5 hours from unmapped external author
        PmWorklog::create([
            'client_id'           => $this->client->id,
            'pm_connection_id'    => $this->connection->id,
            'pm_work_item_id'     => $item->id,
            'user_id'             => null,
            'external_author_id'  => 'contractor_x',
            'author_name'         => 'Contractor X',
            'external_worklog_id' => 'wl_2',
            'time_spent_seconds'  => 5 * 3600,
            'worklog_started_at'  => now(),
        ]);

        // 1. User monthly hours
        $userHours = $timeService->getUserMonthlyHours($this->engineer);
        $this->assertEquals(10.0, $userHours['total_hours']);
        $this->assertCount(1, $userHours['by_customer']);
        $this->assertEquals('Acme Retail', $userHours['by_customer'][0]['client_name']);

        // 2. Manager portfolio hours
        $mgrHours = $timeService->getManagerMonthlyHours();
        $this->assertEquals(15.0, $mgrHours['total_portfolio_hours']);
        $this->assertEquals(5.0, $mgrHours['total_unattributed_hours']);
        $this->assertContains('Contractor X', $mgrHours['unattributed_authors']);
    }

    public function test_delivery_health_evaluates_independently_from_hours_allocation(): void
    {
        $deliveryHealthService = app(ProjectDeliveryHealthService::class);
        $allocationHealthService = app(ProjectAllocationHealthService::class);

        // Client has 2 active tasks, none blocked, none overdue
        $item = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'ext_1',
            'external_item_key'          => 'ACME-1',
            'summary'                    => 'Build homepage feature',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->engineer->id,
            'is_blocked'                 => false,
            'target_due_date'            => now()->addDays(5),
            'priority'                   => 'Medium',
        ]);

        // Client has 100h allocated, but 120h logged (over budget!)
        PmWorklog::create([
            'client_id'           => $this->client->id,
            'pm_connection_id'    => $this->connection->id,
            'pm_work_item_id'     => $item->id,
            'user_id'             => $this->engineer->id,
            'author_name'         => 'Alice Engineer',
            'external_worklog_id' => 'wl_overage',
            'time_spent_seconds'  => 120 * 3600,
            'worklog_started_at'  => now(),
        ]);

        // 1. Delivery Health must be Healthy (not marked At Risk just because hours exceeded!)
        $deliveryEval = $deliveryHealthService->evaluateClientHealth($this->client);
        $this->assertEquals('Healthy', $deliveryEval['status']);
        $this->assertEquals(1, $deliveryEval['metrics']['total_active_tasks']);
        $this->assertEquals(0, $deliveryEval['metrics']['blocked_tasks_count']);

        // 2. Allocation Health must independently reflect over_allocation
        $allocEval = $allocationHealthService->evaluateClientAllocation($this->client);
        $this->assertEquals('over_allocation', $allocEval['status']);
        $this->assertEquals(120.0, $allocEval['logged_hours']);
        $this->assertEquals(120.0, $allocEval['utilization_pct']);
    }

    public function test_work_prioritization_engine_strictly_excludes_blocked_tasks_from_executable_plan(): void
    {
        $engine = app(WorkPrioritizationEngine::class);

        // Task A: Executable
        $taskA = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'ext_A',
            'external_item_key'          => 'ACME-10',
            'summary'                    => 'Active executable task',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $this->engineer->id,
            'is_blocked'                 => false,
            'priority'                   => 'High',
            'target_due_date'            => now()->addDays(1),
        ]);

        // Task B: Blocked
        $taskB = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'ext_B',
            'external_item_key'          => 'ACME-11',
            'summary'                    => 'Blocked dependency task',
            'normalized_delivery_status' => 'blocked',
            'user_id'                    => $this->engineer->id,
            'is_blocked'                 => true,
            'blocked_reason'             => 'Awaiting client API keys',
            'priority'                   => 'Critical',
        ]);

        $plan = $engine->getPrioritizedPlan($this->engineer, true);

        // 1. Recommended Executable Order should contain Task A
        $recommendedKeys = array_column($plan['recommended_order'], 'key');
        $this->assertContains('ACME-10', $recommendedKeys);

        // 2. Task B MUST NOT be in recommended executable order!
        $this->assertNotContains('ACME-11', $recommendedKeys);

        // 3. Task B MUST appear in Needs Attention
        $attentionKeys = array_column($plan['needs_attention'], 'key');
        $this->assertContains('ACME-11', $attentionKeys);
    }

    public function test_meeting_intelligence_four_stage_lifecycle_and_draft_does_not_complete_obligation(): void
    {
        $service = app(\App\Services\Intelligence\MeetingIntelligenceService::class);

        // 1. Create a meeting tomorrow
        $meeting = \App\Models\ClientMeeting::create([
            'client_id'         => $this->client->id,
            'title'             => 'Weekly Architectural Sync',
            'meeting_start_at'  => now()->addHours(12),
            'internal_owner_id' => $this->engineer->id,
            'status'            => \App\Enums\MeetingStatus::PrepPending,
            'prep_stage'        => 'needed',
        ]);

        $stage = $service->determinePrepStage($meeting);
        $this->assertEquals('needed', $stage);

        // 2. Draft brief generated (e.g. AI draft generated)
        $meeting->update([
            'metadata' => ['prep_stage' => 'draft_generated'],
        ]);

        $stage = $service->determinePrepStage($meeting);
        $this->assertEquals('draft_generated', $stage);
        // Generating draft does NOT mark completed
        $this->assertNotEquals('completed', $stage);

        // 3. Reviewed by human engineer/manager
        $meeting->update([
            'metadata' => ['prep_stage' => 'reviewed_ready'],
        ]);
        $stage = $service->determinePrepStage($meeting);
        $this->assertEquals('reviewed_ready', $stage);

        // 4. Shared / completed
        $meeting->update([
            'metadata' => ['prep_stage' => 'completed'],
        ]);
        $stage = $service->determinePrepStage($meeting);
        $this->assertEquals('completed', $stage);
    }

    public function test_delivery_finding_evaluator_generates_deduplicated_findings(): void
    {
        $evaluator = app(\App\Services\Intelligence\DeliveryFindingEvaluator::class);

        // Blocked task
        PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'ext_blocked_1',
            'external_item_key'          => 'ACME-99',
            'summary'                    => 'Severely blocked item',
            'normalized_delivery_status' => 'blocked',
            'user_id'                    => $this->engineer->id,
            'is_blocked'                 => true,
            'blocked_reason'             => 'Waiting for SSL cert from client IT',
            'external_updated_at'        => now()->subDays(6),
        ]);

        // Evaluate once
        $findings1 = $evaluator->evaluateAll();
        $this->assertNotEmpty($findings1);

        $initialCount = \App\Models\Finding::count();

        // Evaluate again: MUST deduplicate based on sha256 fingerprint
        $findings2 = $evaluator->evaluateAll();
        $secondCount = \App\Models\Finding::count();

        $this->assertEquals($initialCount, $secondCount, 'Findings must be deduplicated across sync runs');
    }

    public function test_engineer_dashboard_page_renders_for_authorized_users(): void
    {
        $response = $this->actingAs($this->engineer)->get('/admin/engineer-dashboard');
        $response->assertSuccessful();
    }

    public function test_manager_dashboard_page_renders_for_authorized_users(): void
    {
        \App\Models\ClientMeeting::create([
            'client_id'          => $this->client->id,
            'title'              => 'Executive Bi-weekly',
            'meeting_start_at'   => now()->addDays(2),
            'meeting_end_at'     => now()->addDays(2)->addHour(),
            'internal_owner_id'  => $this->manager->id,
            'status'             => \App\Enums\MeetingStatus::Detected,
        ]);

        $response = $this->actingAs($this->manager)->get('/admin/manager-dashboard');
        $response->assertSuccessful();
    }

    public function test_backlog_and_on_hold_tasks_are_excluded_and_jira_links_generated(): void
    {
        $engine = app(WorkPrioritizationEngine::class);

        // 1. In-progress task
        $activeTask = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'task_active_1',
            'external_item_key'          => 'ACME-200',
            'summary'                    => 'Active in progress work',
            'normalized_delivery_status' => 'in_progress',
            'external_status'            => 'In Progress',
            'user_id'                    => $this->engineer->id,
            'priority'                   => 'High',
            'target_due_date'            => now()->addDays(2),
        ]);

        // 2. Backlog task (normalized)
        $backlogTask = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'task_backlog_1',
            'external_item_key'          => 'ACME-201',
            'summary'                    => 'Backlog future concept',
            'normalized_delivery_status' => 'backlog',
            'external_status'            => 'Backlog',
            'user_id'                    => $this->engineer->id,
            'priority'                   => 'Low',
        ]);

        // 3. On-hold task (external status)
        $onHoldTask = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'task_onhold_1',
            'external_item_key'          => 'ACME-202',
            'summary'                    => 'On hold pending budget',
            'normalized_delivery_status' => 'planned',
            'external_status'            => 'ON HOLD',
            'user_id'                    => $this->engineer->id,
            'priority'                   => 'Medium',
        ]);

        // Check Jira URL accessor
        $this->assertEquals('https://technopath.atlassian.net/browse/ACME-200', $activeTask->jira_url);

        // Prioritization engine should strictly exclude backlog and on-hold
        $plan = $engine->getPrioritizedPlan($this->engineer, true);
        $recommendedKeys = array_column($plan['recommended_order'], 'key');
        $attentionKeys = array_column($plan['needs_attention'], 'key');

        $this->assertContains('ACME-200', $recommendedKeys);
        $this->assertNotContains('ACME-201', $recommendedKeys);
        $this->assertNotContains('ACME-202', $recommendedKeys);
        $this->assertNotContains('ACME-201', $attentionKeys);
        $this->assertNotContains('ACME-202', $attentionKeys);

        // Check active query scope
        $scopedItems = PmWorkItem::excludeBacklogAndOnHold()
            ->where('user_id', $this->engineer->id)
            ->pluck('external_item_key')
            ->toArray();

        $this->assertContains('ACME-200', $scopedItems);
        $this->assertNotContains('ACME-201', $scopedItems);
        $this->assertNotContains('ACME-202', $scopedItems);

        // Test Livewire Active Tasks Widget table actions
        \Livewire\Livewire::actingAs($this->engineer)
            ->test(\App\Filament\Widgets\Engineer\EngineerActiveTasksWidget::class)
            ->assertTableActionExists('jira')
            ->assertTableActionDoesNotExist('start')
            ->assertTableActionDoesNotExist('log_hours');
    }

    public function test_canceled_tasks_are_strictly_excluded_everywhere(): void
    {
        $engine = app(WorkPrioritizationEngine::class);

        // 1. Canceled task (normalized status)
        $canceledTask1 = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'task_cancel_1',
            'external_item_key'          => 'ACME-301',
            'summary'                    => 'Cancelled concept task',
            'normalized_delivery_status' => 'cancelled',
            'external_status'            => 'Cancelled',
            'user_id'                    => $this->engineer->id,
            'priority'                   => 'High',
        ]);

        // 2. Canceled task (external status contains "won't do")
        $canceledTask2 = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'task_cancel_2',
            'external_item_key'          => 'ACME-302',
            'summary'                    => 'Rejected feature',
            'normalized_delivery_status' => 'planned',
            'external_status'            => "Won't Do",
            'user_id'                    => $this->engineer->id,
            'priority'                   => 'High',
        ]);

        $this->assertTrue($canceledTask1->isCanceled());
        $this->assertTrue($canceledTask2->isCanceled());
        $this->assertTrue($canceledTask1->isInactiveOrExcluded());
        $this->assertTrue($canceledTask2->isInactiveOrExcluded());

        // Scope should exclude them
        $scopedKeys = PmWorkItem::excludeBacklogAndOnHold()
            ->where('user_id', $this->engineer->id)
            ->pluck('external_item_key')
            ->toArray();

        $this->assertNotContains('ACME-301', $scopedKeys);
        $this->assertNotContains('ACME-302', $scopedKeys);

        // Prioritization engine should not include them
        $plan = $engine->getPrioritizedPlan($this->engineer, true);
        $allPlanKeys = array_merge(
            array_column($plan['recommended_order'], 'key'),
            array_column($plan['needs_attention'], 'key')
        );

        $this->assertNotContains('ACME-301', $allPlanKeys);
        $this->assertNotContains('ACME-302', $allPlanKeys);

        // JiraProvider mapping test
        $jiraProvider = app(\App\Services\PM\Providers\JiraProvider::class);
        $this->assertEquals('cancelled', $jiraProvider->mapJiraStatusToForge('Cancelled'));
        $this->assertEquals('cancelled', $jiraProvider->mapJiraStatusToForge('Canceled'));
        $this->assertEquals('cancelled', $jiraProvider->mapJiraStatusToForge("Won't Do"));
        $this->assertEquals('cancelled', $jiraProvider->mapJiraStatusToForge('Rejected'));
    }

    public function test_engineer_dashboard_user_switcher_functionality(): void
    {
        $otherEngineer = User::create([
            'name'     => 'Charlie Dev',
            'email'    => 'charlie@technopath.ai',
            'password' => Hash::make('secret123'),
        ]);

        $otherTask = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'charlie_task_1',
            'external_item_key'          => 'ACME-401',
            'summary'                    => 'Charlie assigned work',
            'normalized_delivery_status' => 'in_progress',
            'user_id'                    => $otherEngineer->id,
            'priority'                   => 'High',
        ]);

        // 1. Mount EngineerDashboard as Alice
        \Livewire\Livewire::actingAs($this->engineer)
            ->test(\App\Filament\Pages\EngineerDashboard::class)
            ->assertSet('selected_user_id', $this->engineer->id)
            ->set('selected_user_id', $otherEngineer->id)
            ->assertDispatched('engineer-user-changed')
            ->call('resetToMe')
            ->assertSet('selected_user_id', $this->engineer->id)
            ->assertDispatched('engineer-user-changed');

        // 2. Active tasks widget switches target user
        \Livewire\Livewire::actingAs($this->engineer)
            ->test(\App\Filament\Widgets\Engineer\EngineerActiveTasksWidget::class, ['userId' => $otherEngineer->id])
            ->assertSee('ACME-401')
            ->assertSee("Charlie Dev's Assigned Tasks");
    }
}

