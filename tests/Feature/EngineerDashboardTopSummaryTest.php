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

    public function test_next_due_item_prioritizes_meeting_prep_today_with_direct_action_url(): void
    {
        $page = new EngineerDashboard();

        $upcomingMeetings = [
            [
                'id'          => 42,
                'title'       => 'Weekly Client Sync',
                'action_type' => 'prep',
                'raw_start'   => Carbon::now()->addHours(3),
                'time_label'  => 'Today, 3:00 PM',
                'action_url'  => '/admin/client-meetings/42?tab=prep&action=generatePrep',
            ],
        ];

        $nextDue = $page->getNextDueItem($this->user, [], [], $upcomingMeetings);

        $this->assertEquals('meeting_prep', $nextDue['type']);
        $this->assertEquals('Create prep', $nextDue['action_label']);
        $this->assertEquals('/admin/client-meetings/42?tab=prep&action=generatePrep', $nextDue['action_url']);
    }

    public function test_next_due_item_prioritizes_meeting_followup_with_direct_action_url(): void
    {
        $page = new EngineerDashboard();

        $upcomingMeetings = [
            [
                'id'          => 43,
                'title'       => 'Architecture Review',
                'action_type' => 'follow_up',
                'raw_start'   => Carbon::now()->subHours(2),
                'time_label'  => 'Today, 10:00 AM',
                'action_url'  => '/admin/client-meetings/43?tab=follow-up&action=generateFollowUp',
            ],
        ];

        $nextDue = $page->getNextDueItem($this->user, [], [], $upcomingMeetings);

        $this->assertEquals('meeting_followup', $nextDue['type']);
        $this->assertEquals('Create follow up', $nextDue['action_label']);
        $this->assertEquals('/admin/client-meetings/43?tab=follow-up&action=generateFollowUp', $nextDue['action_url']);
    }

    public function test_next_due_item_upcoming_meeting_prep_future_day_generates_prep_url(): void
    {
        $page = new EngineerDashboard();

        $upcomingMeetings = [
            [
                'id'          => 44,
                'title'       => 'Sprint Planning Next Week',
                'action_type' => 'prep',
                'time_label'  => 'Tomorrow, 10:00 AM',
                // No action_url provided intentionally to test auto-generation
            ],
        ];

        $nextDue = $page->getNextDueItem($this->user, [], [], $upcomingMeetings);

        $this->assertEquals('meeting_prep', $nextDue['type']);
        $this->assertEquals('Create prep', $nextDue['action_label']);
        $this->assertNotNull($nextDue['action_url']);
        $this->assertStringContainsString('tab=prep', $nextDue['action_url']);
        $this->assertStringContainsString('action=generatePrep', $nextDue['action_url']);
    }

    public function test_spotlight_renders_create_prep_button(): void
    {
        $meeting = ClientMeeting::create([
            'client_id'         => $this->client->id,
            'title'             => 'Important Client Review',
            'meeting_start_at'  => Carbon::now()->addDay(),
            'meeting_end_at'    => Carbon::now()->addDay()->addHour(),
            'internal_owner_id' => $this->user->id,
            'status'            => \App\Enums\MeetingStatus::Detected,
        ]);

        $this->actingAs($this->user);

        Livewire::test(EngineerDashboard::class)
            ->assertSee('Next Due Item')
            ->assertSee('Meeting Prep Pending')
            ->assertSee('Create prep')
            ->assertSee('Jump to meeting');
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
            ->assertSee('function jumpToSection', false)
            ->assertDontSee('x-heroicon-m-chevron-down class="w-3.5 h-3.5 text-slate-400"', false)
            ->assertDontSee('Track time');
    }

    public function test_my_hours_generates_jira_filter_url_for_missing_time_tasks(): void
    {
        $mockTimeService = $this->createMock(\App\Services\Intelligence\TimeTrackingService::class);
        $mockTimeService->method('getUserMonthlyHours')->willReturn([
            'total_hours'            => 21.4,
            'working_capacity_hours' => 160,
            'by_customer'            => [
                [
                    'client_name'     => 'Technopath Internal',
                    'hours'           => 8.3,
                    'allocated_hours' => 60,
                ],
            ],
            'missing_time_count'     => 3,
            'missing_time_items'     => [
                ['external_item_key' => 'SHEL-229'],
                ['external_item_key' => 'TEC-277'],
                ['external_item_key' => 'SHEL-98'],
            ],
        ]);

        $this->app->instance(\App\Services\Intelligence\TimeTrackingService::class, $mockTimeService);

        $page = new EngineerDashboard();
        $hoursData = $page->getMyHoursData($this->user);

        $this->assertEquals(3, $hoursData['missing_time_count']);
        $this->assertEquals(['SHEL-229', 'TEC-277', 'SHEL-98'], $hoursData['missing_time_keys']);
        $this->assertNotNull($hoursData['missing_time_jira_url']);
        $this->assertStringContainsString('https://technopath.atlassian.net/issues/?jql=', $hoursData['missing_time_jira_url']);
        $this->assertStringContainsString('SHEL-229', urldecode($hoursData['missing_time_jira_url']));
        $this->assertStringContainsString('TEC-277', urldecode($hoursData['missing_time_jira_url']));
        $this->assertStringContainsString('SHEL-98', urldecode($hoursData['missing_time_jira_url']));

        $this->actingAs($this->user);
        session(['engineer_dashboard_user_id' => $this->user->id]);

        Livewire::test(EngineerDashboard::class)
            ->assertSee('tasks completed with no time entries')
            ->assertSee('View in Jira')
            ->assertSee('https://technopath.atlassian.net/browse/SHEL-229', false)
            ->assertSee('https://technopath.atlassian.net/browse/TEC-277', false)
            ->assertSee('https://technopath.atlassian.net/browse/SHEL-98', false);
    }
}
