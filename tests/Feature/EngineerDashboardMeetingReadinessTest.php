<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Filament\Pages\EngineerDashboard;
use App\Filament\Resources\ClientMeetingResource\Pages\ViewClientMeeting;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\MeetingFollowUp;
use App\Models\MeetingPrep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class EngineerDashboardMeetingReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Jean Paul Hanna',
            'email' => 'jp@technopath.co',
            'password' => Hash::make('password123'),
        ]);

        $this->client = Client::create([
            'name' => 'GoldKamp',
            'jira_project_key' => 'GK',
            'status' => 'active',
        ]);
    }

    public function test_upcoming_meeting_missing_prep_displays_create_prep_action(): void
    {
        $meeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'GoldKamp Weekly Sync',
            'meeting_start_at' => now()->addDays(2),
            'meeting_end_at' => now()->addDays(2)->addHour(),
            'internal_owner_id' => $this->user->id,
            'status' => MeetingStatus::Detected,
        ]);

        $page = new EngineerDashboard;
        $meetings = $page->getUpcomingMeetingsData($this->user);

        $this->assertCount(1, $meetings);
        $this->assertEquals($meeting->id, $meetings[0]['id']);
        $this->assertEquals('Create prep', $meetings[0]['action_label']);
        $this->assertEquals('prep', $meetings[0]['action_type']);
        $this->assertEquals('Missing prep', $meetings[0]['badge_label']);
        $this->assertStringContainsString('tab=prep', $meetings[0]['action_url']);
        $this->assertStringContainsString('action=generatePrep', $meetings[0]['action_url']);
    }

    public function test_upcoming_meeting_with_prep_but_missing_followup_displays_create_followup_action(): void
    {
        $meeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'GoldKamp Architecture Review',
            'meeting_start_at' => now()->addDays(1),
            'meeting_end_at' => now()->addDays(1)->addHour(),
            'internal_owner_id' => $this->user->id,
            'status' => MeetingStatus::PrepGenerated,
        ]);

        MeetingPrep::create([
            'client_meeting_id' => $meeting->id,
            'internal_summary' => '## Agenda and Sprint deliverables summary',
            'generated_at' => now(),
        ]);

        $page = new EngineerDashboard;
        $meetings = $page->getUpcomingMeetingsData($this->user);

        $this->assertCount(1, $meetings);
        $this->assertEquals($meeting->id, $meetings[0]['id']);
        $this->assertEquals('Create follow up', $meetings[0]['action_label']);
        $this->assertEquals('follow_up', $meetings[0]['action_type']);
        $this->assertEquals('Missing follow-up', $meetings[0]['badge_label']);
        $this->assertStringContainsString('tab=follow-up', $meetings[0]['action_url']);
        $this->assertStringContainsString('action=generateFollowUp', $meetings[0]['action_url']);
    }

    public function test_meeting_with_both_prep_and_followup_is_excluded(): void
    {
        $meeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'GoldKamp Closed Session',
            'meeting_start_at' => now()->addDays(1),
            'meeting_end_at' => now()->addDays(1)->addHour(),
            'internal_owner_id' => $this->user->id,
            'status' => MeetingStatus::FollowUpGenerated,
        ]);

        MeetingPrep::create([
            'client_meeting_id' => $meeting->id,
            'internal_summary' => 'Full prep content ready',
            'generated_at' => now(),
        ]);

        MeetingFollowUp::create([
            'client_meeting_id' => $meeting->id,
            'summary' => 'Full follow-up content and notes',
            'generated_at' => now(),
        ]);

        $page = new EngineerDashboard;
        $meetings = $page->getUpcomingMeetingsData($this->user);

        $this->assertEmpty($meetings, 'Meetings with both prep and follow-up completed must be excluded from the queue');
    }

    public function test_engineer_dashboard_renders_actions_and_calendar_link(): void
    {
        $meeting1 = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'GoldKamp Kickoff',
            'meeting_start_at' => now()->addDays(2),
            'meeting_end_at' => now()->addDays(2)->addHour(),
            'internal_owner_id' => $this->user->id,
            'status' => MeetingStatus::Detected,
        ]);

        $meeting2 = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'GoldKamp Followup Needed',
            'meeting_start_at' => now()->addDays(3),
            'meeting_end_at' => now()->addDays(3)->addHour(),
            'internal_owner_id' => $this->user->id,
            'status' => MeetingStatus::PrepGenerated,
        ]);

        MeetingPrep::create([
            'client_meeting_id' => $meeting2->id,
            'internal_summary' => 'Existing prep',
            'generated_at' => now(),
        ]);

        $this->actingAs($this->user);

        Livewire::test(EngineerDashboard::class)
            ->assertSuccessful()
            ->assertSee('4. Upcoming Meetings')
            ->assertSee('2 upcoming')
            ->assertSee('GoldKamp Kickoff')
            ->assertSee('Create prep')
            ->assertSee('GoldKamp Followup Needed')
            ->assertSee('Create follow up')
            ->assertSee('View calendar');
    }

    public function test_view_client_meeting_mounts_actions_from_query_params(): void
    {
        $meeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'GoldKamp Deep Dive',
            'meeting_start_at' => now()->addDays(2),
            'meeting_end_at' => now()->addDays(2)->addHour(),
            'internal_owner_id' => $this->user->id,
            'status' => MeetingStatus::Detected,
        ]);

        $this->actingAs($this->user);

        // Test defaultAction set for generatePrep
        Livewire::withQueryParams(['tab' => 'prep', 'action' => 'generatePrep'])
            ->test(ViewClientMeeting::class, ['record' => $meeting->id])
            ->assertSuccessful()
            ->assertSet('defaultAction', 'generatePrep');

        // Test defaultAction set for generateFollowUp
        Livewire::withQueryParams(['tab' => 'follow-up', 'action' => 'generateFollowUp'])
            ->test(ViewClientMeeting::class, ['record' => $meeting->id])
            ->assertSuccessful()
            ->assertSet('defaultAction', 'generateFollowUp');
    }

    public function test_upcoming_meetings_only_include_creator_or_owner_and_exclude_attendee_only_meetings(): void
    {
        $otherUser = User::factory()->create([
            'email' => 'other.engineer@technopath.co',
        ]);

        // Meeting 1: Owned by $this->user
        $ownedMeeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'Owned by User Meeting',
            'meeting_start_at' => now()->addDays(1),
            'meeting_end_at' => now()->addDays(1)->addHour(),
            'internal_owner_id' => $this->user->id,
            'status' => MeetingStatus::Detected,
        ]);

        // Meeting 2: Created by $this->user in Google Calendar (metadata->creator_email)
        $createdMeeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'Created by User Meeting',
            'meeting_start_at' => now()->addDays(2),
            'meeting_end_at' => now()->addDays(2)->addHour(),
            'internal_owner_id' => null,
            'metadata' => [
                'creator_email' => $this->user->email,
            ],
            'status' => MeetingStatus::Detected,
        ]);

        // Meeting 3: Owned and created by otherUser, where $this->user is only in internal_attendees
        $attendeeOnlyMeeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'Other User Meeting (Attendee Only)',
            'meeting_start_at' => now()->addDays(1),
            'meeting_end_at' => now()->addDays(1)->addHour(),
            'internal_owner_id' => $otherUser->id,
            'internal_attendees' => [
                ['name' => $this->user->name, 'email' => $this->user->email],
                ['name' => 'Client Contact', 'email' => 'client@goldkamp.com'],
            ],
            'metadata' => [
                'creator_email' => $otherUser->email,
            ],
            'status' => MeetingStatus::Detected,
        ]);

        $page = new EngineerDashboard;
        $userMeetings = collect($page->getUpcomingMeetingsData($this->user));

        $userMeetingIds = $userMeetings->pluck('id')->all();

        $this->assertContains($ownedMeeting->id, $userMeetingIds, 'Owned meeting must be included');
        $this->assertContains($createdMeeting->id, $userMeetingIds, 'Created meeting must be included');
        $this->assertNotContains($attendeeOnlyMeeting->id, $userMeetingIds, 'Attendee-only meeting must be excluded');

        $this->actingAs($this->user);

        Livewire::test(EngineerDashboard::class)
            ->assertSuccessful()
            ->assertSee('Owned by User Meeting')
            ->assertSee('Created by User Meeting')
            ->assertDontSee('Other User Meeting (Attendee Only)');
    }
}
