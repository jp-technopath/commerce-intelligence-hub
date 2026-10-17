<?php

namespace Tests\Feature;

use App\Filament\Pages\EngineerDashboard;
use App\Jobs\SyncPmProjectJob;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EngineerDashboardTopSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'Test Engineer',
            'email'    => 'engineer@technopath.co',
            'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
        ]);

        $this->client = Client::create([
            'name'             => 'Test Client',
            'jira_project_key' => 'TEST',
            'status'           => 'active',
        ]);
    }

    public function test_get_last_synced_at_returns_max_timestamp(): void
    {
        $page = new EngineerDashboard();
        $this->assertNull($page->getLastSyncedAt());

        $pmTime = Carbon::now()->subMinutes(15);
        $conn = PmConnection::create([
            'client_id'      => $this->client->id,
            'provider'       => 'jira',
            'name'           => 'Test Jira',
            'is_active'      => true,
            'last_synced_at' => $pmTime,
        ]);

        $this->assertEquals($pmTime->toDateTimeString(), $page->getLastSyncedAt()->toDateTimeString());

        $newerTime = Carbon::now()->subMinutes(5);
        PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $conn->id,
            'external_item_key'          => 'TEST-1',
            'external_item_id'           => '10001',
            'user_id'                    => $this->user->id,
            'summary'                    => 'Task 1',
            'normalized_delivery_status' => 'in_progress',
            'last_synced_at'             => $newerTime,
        ]);

        $this->assertEquals($newerTime->toDateTimeString(), $page->getLastSyncedAt()->toDateTimeString());
    }

    public function test_refresh_dashboard_data_dispatches_sync_jobs(): void
    {
        Queue::fake();

        $conn = PmConnection::create([
            'client_id' => $this->client->id,
            'provider'  => 'jira',
            'name'      => 'Test Jira',
            'is_active' => true,
        ]);

        $project = PmProject::create([
            'client_id'            => $this->client->id,
            'pm_connection_id'     => $conn->id,
            'external_project_id'  => '100',
            'external_project_key' => 'TEST',
            'name'                 => 'Test Project',
            'is_active'            => true,
        ]);

        $page = new EngineerDashboard();
        $page->refreshDashboardData();

        Queue::assertPushed(SyncPmProjectJob::class, function ($job) use ($project) {
            return $job->project->id === $project->id;
        });
    }

    public function test_next_due_item_prioritizes_meeting_prep_today(): void
    {
        $page = new EngineerDashboard();

        $upcomingMeetings = [
            [
                'id'          => 1,
                'title'       => 'Client Standup',
                'action_type' => 'prep',
                'raw_start'   => Carbon::now()->addHours(2),
                'time_label'  => 'Today, 2:00 PM',
                'action_url'  => '/admin/client-meetings/1?tab=prep',
            ],
        ];

        $needsAttention = [
            [
                'title'       => 'Blocked on API',
                'description' => 'Waiting for key',
                'key'         => 'TEST-12',
                'priority'    => 'High',
            ],
        ];

        $workPlan = [
            [
                'title'       => 'Fix Login Bug',
                'key'         => 'TEST-1',
                'project'     => 'Portal',
                'est_time'    => '2h',
                'why_matters' => 'Urgent',
            ],
        ];

        $nextDue = $page->getNextDueItem($this->user, $workPlan, $needsAttention, $upcomingMeetings);

        $this->assertEquals('meeting_prep', $nextDue['type']);
        $this->assertEquals('section-upcoming-meetings', $nextDue['target_id']);
        $this->assertStringContainsString('Client Standup', $nextDue['title']);
    }

    public function test_next_due_item_falls_back_to_high_priority_blocker(): void
    {
        $page = new EngineerDashboard();

        $upcomingMeetings = [];

        $needsAttention = [
            [
                'title'       => 'Blocked on API',
                'description' => 'Waiting for client credentials',
                'key'         => 'TEST-12',
                'priority'    => 'High',
            ],
        ];

        $workPlan = [
            [
                'title'       => 'Fix Login Bug',
                'key'         => 'TEST-1',
                'project'     => 'Portal',
                'est_time'    => '2h',
                'why_matters' => 'Urgent',
            ],
        ];

        $nextDue = $page->getNextDueItem($this->user, $workPlan, $needsAttention, $upcomingMeetings);

        $this->assertEquals('blocker', $nextDue['type']);
        $this->assertEquals('section-needs-attention', $nextDue['target_id']);
        $this->assertStringContainsString('TEST-12', $nextDue['title']);
    }

    public function test_next_due_item_falls_back_to_work_plan_number_one(): void
    {
        $page = new EngineerDashboard();

        $upcomingMeetings = [];
        $needsAttention = [];
        $workPlan = [
            [
                'title'       => 'Implement Checkout',
                'key'         => 'TEST-99',
                'project'     => 'Checkout Flow',
                'est_time'    => '3h',
                'why_matters' => 'Milestone deliverable',
            ],
        ];

        $nextDue = $page->getNextDueItem($this->user, $workPlan, $needsAttention, $upcomingMeetings);

        $this->assertEquals('work_plan', $nextDue['type']);
        $this->assertEquals('section-work-plan', $nextDue['target_id']);
        $this->assertStringContainsString('TEST-99: Implement Checkout', $nextDue['title']);
    }

    public function test_engineer_dashboard_renders_top_card_sync_button_and_section_anchors(): void
    {
        $this->actingAs($this->user);

        Livewire::test(EngineerDashboard::class)
            ->assertSee('Synced')
            ->assertSee('Refresh')
            ->assertSee('Next Due Item')
            ->assertSee('id="section-work-plan"', false)
            ->assertSee('id="section-needs-attention"', false)
            ->assertSee('id="section-hours"', false)
            ->assertSee('id="section-upcoming-meetings"', false)
            ->assertSee('id="section-project-health"', false)
            ->assertSee('function jumpToSection', false);
    }
}
