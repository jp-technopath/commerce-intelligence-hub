<?php

namespace Tests\Feature;

use App\Filament\Pages\EngineerDashboard;
use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\Intelligence\WorkPrioritizationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class EngineerDashboardDoneAndMockExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Client $clientCambro;
    protected Client $clientShell;
    protected PmConnection $connectionCambro;
    protected PmConnection $connectionShell;
    protected PmProject $projectCambro;
    protected PmProject $projectShell;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'Jean Paul Hanna',
            'email'    => 'jp@technopath.co',
            'password' => Hash::make('secret123'),
        ]);

        $this->clientCambro = Client::create([
            'name'                     => 'Cambro',
            'code'                     => 'CAMBRO',
            'jira_project_key'         => 'CMBR2',
            'status'                   => 'active',
            'monthly_allocated_hours'  => 80,
        ]);

        $this->clientShell = Client::create([
            'name'                     => 'ShellProof',
            'code'                     => 'SHELL',
            'jira_project_key'         => 'SHEL',
            'status'                   => 'active',
            'monthly_allocated_hours'  => 40,
        ]);

        $this->connectionCambro = PmConnection::create([
            'client_id'          => $this->clientCambro->id,
            'provider'           => 'jira',
            'name'               => 'Cambro Jira',
            'base_url'           => 'https://technopath.atlassian.net',
            'status'             => 'active',
            'sync_health_status' => 'healthy',
            'last_synced_at'     => now(),
        ]);

        $this->connectionShell = PmConnection::create([
            'client_id'          => $this->clientShell->id,
            'provider'           => 'jira',
            'name'               => 'Shell Jira',
            'base_url'           => 'https://technopath.atlassian.net',
            'status'             => 'active',
            'sync_health_status' => 'healthy',
            'last_synced_at'     => now(),
        ]);

        $this->projectCambro = PmProject::create([
            'pm_connection_id'     => $this->connectionCambro->id,
            'client_id'            => $this->clientCambro->id,
            'external_project_id'  => 'PROJ-CMBR2',
            'external_project_key' => 'CMBR2',
            'name'                 => 'Cambro Jira Project',
        ]);

        $this->projectShell = PmProject::create([
            'pm_connection_id'     => $this->connectionShell->id,
            'client_id'            => $this->clientShell->id,
            'external_project_id'  => 'PROJ-SHEL',
            'external_project_key' => 'SHEL',
            'name'                 => 'ShellProof Jira Project',
        ]);
    }

    public function test_done_task_shel_237_is_excluded_from_prioritization_engine(): void
    {
        // Active task for user
        PmWorkItem::create([
            'pm_connection_id'           => $this->connectionCambro->id,
            'pm_project_id'              => $this->projectCambro->id,
            'client_id'                  => $this->clientCambro->id,
            'user_id'                    => $this->user->id,
            'external_item_id'           => 'ITEM-101',
            'external_item_key'          => 'CMBR2-101',
            'summary'                    => 'Active Checkout Bug',
            'normalized_delivery_status' => 'in_progress',
            'external_status'            => 'In Progress',
            'priority'                   => 'High',
            'issue_type'                 => 'Bug',
        ]);

        // Completed task SHEL-237 with 0 hours logged
        PmWorkItem::create([
            'pm_connection_id'           => $this->connectionShell->id,
            'pm_project_id'              => $this->projectShell->id,
            'client_id'                  => $this->clientShell->id,
            'user_id'                    => $this->user->id,
            'external_item_id'           => 'ITEM-237',
            'external_item_key'          => 'SHEL-237',
            'summary'                    => 'Completed Task SHEL-237',
            'normalized_delivery_status' => 'completed',
            'external_status'            => 'Done',
            'priority'                   => 'High',
            'issue_type'                 => 'Task',
        ]);

        // Completed task with external_status 'Resolved'
        PmWorkItem::create([
            'pm_connection_id'           => $this->connectionShell->id,
            'pm_project_id'              => $this->projectShell->id,
            'client_id'                  => $this->clientShell->id,
            'user_id'                    => $this->user->id,
            'external_item_id'           => 'ITEM-239',
            'external_item_key'          => 'SHEL-239',
            'summary'                    => 'Resolved Task SHEL-239',
            'normalized_delivery_status' => 'planned',
            'external_status'            => 'Resolved',
            'priority'                   => 'Medium',
            'issue_type'                 => 'Task',
        ]);

        $engine = app(WorkPrioritizationEngine::class);
        $engine->invalidateUserPlan($this->user);
        $plan = $engine->getPrioritizedPlan($this->user);

        $recommendedKeys = array_column($plan['recommended_order'], 'key');
        $attentionKeys = array_column($plan['needs_attention'], 'key');

        $this->assertContains('CMBR2-101', $recommendedKeys);
        $this->assertNotContains('SHEL-237', $recommendedKeys, 'SHEL-237 must not appear in recommended order');
        $this->assertNotContains('SHEL-237', $attentionKeys, 'SHEL-237 must not appear in needs attention');
        $this->assertNotContains('SHEL-239', $recommendedKeys, 'Resolved tasks must not appear in recommended order');
        $this->assertNotContains('SHEL-239', $attentionKeys, 'Resolved tasks must not appear in needs attention');
    }

    public function test_engineer_dashboard_does_not_pad_with_mock_r40_items(): void
    {
        // Engineer has only 1 active task
        PmWorkItem::create([
            'pm_connection_id'           => $this->connectionCambro->id,
            'pm_project_id'              => $this->projectCambro->id,
            'client_id'                  => $this->clientCambro->id,
            'user_id'                    => $this->user->id,
            'external_item_id'           => 'ITEM-101',
            'external_item_key'          => 'CMBR2-101',
            'summary'                    => 'Active Checkout Bug',
            'normalized_delivery_status' => 'in_progress',
            'external_status'            => 'In Progress',
            'priority'                   => 'High',
            'issue_type'                 => 'Bug',
        ]);

        $page = new EngineerDashboard();
        $workPlan = $page->getWorkPlanItems($this->user);
        $attention = $page->getNeedsAttentionItems($this->user);

        // Should return exactly 1 item and NOT pad up to 5 with mock tasks like R40-118, R40-210
        $this->assertCount(1, $workPlan);
        $this->assertEquals('CMBR2-101', $workPlan[0]['key']);

        foreach ($workPlan as $item) {
            $this->assertNotEquals('R40-118', $item['key'] ?? '');
            $this->assertNotEquals('R40-210', $item['key'] ?? '');
            $this->assertNotEquals('Room40', $item['project'] ?? '');
        }

        // Needs attention should be empty (or contain only real items), never mock R40-156
        foreach ($attention as $item) {
            $this->assertNotEquals('R40-156', $item['key'] ?? '');
            $this->assertStringNotContainsString('Room40', $item['description'] ?? '');
        }
    }

    public function test_dashboard_components_exclude_room40(): void
    {
        $page = new EngineerDashboard();

        // Check Hours
        $hours = $page->getMyHoursData($this->user);
        foreach ($hours['customers'] as $c) {
            $this->assertNotEquals('Room40', $c['name']);
        }

        // Check Meetings
        $meetings = $page->getUpcomingMeetingsData($this->user);
        $this->assertEmpty($meetings, 'When user has no scheduled meetings, mock Room40 meetings should not appear');

        // Check Project Health
        $health = $page->getProjectHealthItems($this->user);
        foreach ($health as $h) {
            $this->assertNotEquals('Room40', $h['name']);
        }

        // Check AI Insight text
        $insight = $page->getAiInsightText($this->user, [], $meetings);
        $this->assertStringNotContainsString('Room40', $insight);
    }

    public function test_engineer_dashboard_page_renders_cleanly_with_empty_states(): void
    {
        $this->actingAs($this->user);

        Livewire::test(EngineerDashboard::class)
            ->assertSuccessful()
            ->assertDontSee('R40-156')
            ->assertDontSee('R40-118')
            ->assertDontSee('R40-210')
            ->assertDontSee('Room40 Technical Sync');
    }
}
