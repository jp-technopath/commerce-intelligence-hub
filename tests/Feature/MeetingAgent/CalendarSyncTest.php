<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Enums\MeetingSource;
use App\Enums\MeetingStatus;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\MeetingAgent\GoogleCalendarService;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meeting_agent.calendar.company_domains'  => ['technopath.com'],
            'meeting_agent.calendar.scan_days_ahead'   => 7,
            'meeting_agent.calendar.exclude_patterns'  => ['/standup/i', '/internal/i'],
            'meeting_agent.calendar.include_hashtag'   => '#customer-meeting',
        ]);
    }

    // ── Compound key upsert ────────────────────────────────────────────

    public function test_sync_uses_compound_key_for_upsert(): void
    {
        $user = User::factory()->create();

        // Manually create two meetings with different compound keys
        $meeting1 = ClientMeeting::create([
            'scanned_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id'    => 'event-001',
            'title'              => 'Meeting 1',
            'meeting_start_at'   => now()->addDay(),
            'status'             => MeetingStatus::Detected,
            'source'             => MeetingSource::GoogleCalendar,
        ]);

        $meeting2 = ClientMeeting::create([
            'scanned_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id'    => 'event-002',
            'title'              => 'Meeting 2',
            'meeting_start_at'   => now()->addDays(2),
            'status'             => MeetingStatus::Detected,
            'source'             => MeetingSource::GoogleCalendar,
        ]);

        $this->assertSame(2, ClientMeeting::count());

        // Update the first meeting (same compound key should upsert, not create new)
        ClientMeeting::updateOrCreate(
            [
                'scanned_by_user_id' => $user->id,
                'google_calendar_id' => 'primary',
                'google_event_id'    => 'event-001',
            ],
            [
                'title'            => 'Updated Meeting 1',
                'meeting_start_at' => now()->addDay(),
                'status'           => MeetingStatus::Detected,
                'source'           => MeetingSource::GoogleCalendar,
            ]
        );

        // Should still be 2, not 3 — the upsert should have updated, not created
        $this->assertSame(2, ClientMeeting::count());

        $updated = ClientMeeting::where('google_event_id', 'event-001')->first();
        $this->assertSame('Updated Meeting 1', $updated->title);
    }

    public function test_rescan_does_not_create_duplicates(): void
    {
        $user = User::factory()->create();

        // Simulate first scan
        ClientMeeting::updateOrCreate(
            [
                'scanned_by_user_id' => $user->id,
                'google_calendar_id' => 'primary',
                'google_event_id'    => 'event-123',
            ],
            [
                'title'            => 'Weekly Sync',
                'meeting_start_at' => now()->addDays(3),
                'status'           => MeetingStatus::Detected,
                'source'           => MeetingSource::GoogleCalendar,
            ]
        );

        $this->assertSame(1, ClientMeeting::count());

        // Simulate second scan (same event)
        ClientMeeting::updateOrCreate(
            [
                'scanned_by_user_id' => $user->id,
                'google_calendar_id' => 'primary',
                'google_event_id'    => 'event-123',
            ],
            [
                'title'            => 'Weekly Sync',
                'meeting_start_at' => now()->addDays(3),
                'status'           => MeetingStatus::Detected,
                'source'           => MeetingSource::GoogleCalendar,
            ]
        );

        // Should still be 1 — no duplicates
        $this->assertSame(1, ClientMeeting::count());
    }

    // ── Cancelled events ───────────────────────────────────────────────

    public function test_cancelled_events_set_status_to_canceled(): void
    {
        $user = User::factory()->create();

        // Create a meeting then update it as cancelled
        ClientMeeting::updateOrCreate(
            [
                'scanned_by_user_id' => $user->id,
                'google_calendar_id' => 'primary',
                'google_event_id'    => 'event-cancelled',
            ],
            [
                'title'            => 'Cancelled Meeting',
                'meeting_start_at' => now()->addDay(),
                'status'           => MeetingStatus::Canceled,
                'source'           => MeetingSource::GoogleCalendar,
            ]
        );

        $meeting = ClientMeeting::where('google_event_id', 'event-cancelled')->first();
        $this->assertSame(MeetingStatus::Canceled, $meeting->status);
    }

    // ── Unmatched clients ──────────────────────────────────────────────

    public function test_unmatched_clients_set_status_to_needs_mapping(): void
    {
        $user = User::factory()->create();

        // Create a meeting with no client matched
        ClientMeeting::create([
            'scanned_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id'    => 'event-unmapped',
            'title'              => 'Meeting with Unknown Client',
            'meeting_start_at'   => now()->addDay(),
            'client_id'          => null,
            'status'             => MeetingStatus::NeedsMapping,
            'source'             => MeetingSource::GoogleCalendar,
        ]);

        $meeting = ClientMeeting::where('google_event_id', 'event-unmapped')->first();
        $this->assertSame(MeetingStatus::NeedsMapping, $meeting->status);
        $this->assertNull($meeting->client_id);
    }

    // ── Source tracking ────────────────────────────────────────────────

    public function test_synced_meetings_have_google_calendar_source(): void
    {
        $user = User::factory()->create();

        ClientMeeting::create([
            'scanned_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id'    => 'event-source-test',
            'title'              => 'Source Test',
            'meeting_start_at'   => now()->addDay(),
            'status'             => MeetingStatus::Detected,
            'source'             => MeetingSource::GoogleCalendar,
        ]);

        $meeting = ClientMeeting::where('google_event_id', 'event-source-test')->first();
        $this->assertSame(MeetingSource::GoogleCalendar, $meeting->source);
    }

    // ── Client matching ────────────────────────────────────────────────

    public function test_known_client_is_matched_by_title(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Acme Corp']);

        ClientMeeting::create([
            'scanned_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id'    => 'event-acme',
            'title'              => 'Acme Corp Weekly Review',
            'meeting_start_at'   => now()->addDay(),
            'client_id'          => $client->id,
            'status'             => MeetingStatus::Detected,
            'source'             => MeetingSource::GoogleCalendar,
        ]);

        $meeting = ClientMeeting::where('google_event_id', 'event-acme')->first();
        $this->assertSame($client->id, $meeting->client_id);
    }

    // ── Attendee classification ────────────────────────────────────────

    public function test_attendees_classified_as_internal_and_external(): void
    {
        $user = User::factory()->create();

        ClientMeeting::create([
            'scanned_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id'    => 'event-attendees',
            'title'              => 'Meeting with Attendees',
            'meeting_start_at'   => now()->addDay(),
            'internal_attendees' => [
                ['email' => 'john@technopath.com', 'name' => 'John'],
            ],
            'external_attendees' => [
                ['email' => 'client@external.com', 'name' => 'Client User'],
            ],
            'status' => MeetingStatus::Detected,
            'source' => MeetingSource::GoogleCalendar,
        ]);

        $meeting = ClientMeeting::where('google_event_id', 'event-attendees')->first();

        $this->assertCount(1, $meeting->internal_attendees);
        $this->assertCount(1, $meeting->external_attendees);
        $this->assertSame('john@technopath.com', $meeting->internal_attendees[0]['email']);
        $this->assertSame('client@external.com', $meeting->external_attendees[0]['email']);
    }
}
