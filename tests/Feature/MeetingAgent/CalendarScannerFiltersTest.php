<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\Client;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\MeetingAgent\GoogleCalendarService;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarScannerFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ConnectedAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meeting_agent.calendar.company_domains'  => ['technopath.com'],
            'meeting_agent.calendar.scan_days_ahead'   => 7,
            'meeting_agent.calendar.exclude_patterns'  => ['/standup/i', '/internal/i'],
            'meeting_agent.calendar.include_hashtag'   => '#customer-meeting',
        ]);

        $this->user = User::factory()->create();

        $this->account = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'provider'         => 'google_workspace',
            'status'           => ConnectedAccountStatus::Active,
            'credentials_json' => ['refresh_token' => 'mock-refresh-token'],
            'settings_json'    => [],
        ]);
    }

    private function createMockEvent(string $title, string $description = '', array $attendeeEmails = []): Event
    {
        $event = new Event();
        $event->setSummary($title);
        $event->setDescription($description);

        $attendees = [];
        foreach ($attendeeEmails as $email) {
            $attendee = new EventAttendee();
            $attendee->setEmail($email);
            $attendees[] = $attendee;
        }
        $event->setAttendees($attendees);

        return $event;
    }

    /**
     * Build the GoogleCalendarService using reflection to bypass constructor HTTP requests
     * while preserving the connected account setting lookup.
     */
    private function buildServiceWithAccount(): GoogleCalendarService
    {
        $service = (new \ReflectionClass(GoogleCalendarService::class))
            ->newInstanceWithoutConstructor();

        $refAccount = new \ReflectionProperty(GoogleCalendarService::class, 'account');
        $refAccount->setAccessible(true);
        $refAccount->setValue($service, $this->account);

        return $service;
    }

    public function test_default_auto_scan_mode_accepts_external_meetings(): void
    {
        $this->account->update([
            'settings_json' => [
                'scan_mode' => 'auto',
            ],
        ]);

        $service = $this->buildServiceWithAccount();
        $event = $this->createMockEvent('Acme Project Sync', 'Discussion', ['john@technopath.com', 'alice@acme.com']);

        $this->assertTrue($service->isLikelyClientMeeting($event));
    }

    public function test_filters_events_by_custom_exclusion_keywords(): void
    {
        $this->account->update([
            'settings_json' => [
                'scan_mode'        => 'auto',
                'exclude_keywords' => ['dentist', 'private appointment', 'personal'],
            ],
        ]);

        $service = $this->buildServiceWithAccount();

        // Should be skipped (contains 'dentist')
        $event1 = $this->createMockEvent('Dentist Appointment', '', ['john@technopath.com']);
        $this->assertFalse($service->isLikelyClientMeeting($event1));

        // Should be skipped (contains 'personal')
        $event2 = $this->createMockEvent('Personal time blocks', '', ['john@technopath.com']);
        $this->assertFalse($service->isLikelyClientMeeting($event2));

        // Should be accepted
        $event3 = $this->createMockEvent('Client Kickoff', '', ['john@technopath.com', 'client@acme.com']);
        $this->assertTrue($service->isLikelyClientMeeting($event3));
    }

    public function test_filters_events_by_regex_exclusion(): void
    {
        $this->account->update([
            'settings_json' => [
                'scan_mode'        => 'auto',
                'exclude_keywords' => ['/\[private\]/i'],
            ],
        ]);

        $service = $this->buildServiceWithAccount();

        // Should be skipped due to matching [private] regex
        $event1 = $this->createMockEvent('[Private] Catch up with Bob', '', ['john@technopath.com']);
        $this->assertFalse($service->isLikelyClientMeeting($event1));

        // Should be accepted
        $event2 = $this->createMockEvent('Public catch up', '', ['john@technopath.com', 'bob@acme.com']);
        $this->assertTrue($service->isLikelyClientMeeting($event2));
    }

    public function test_filters_internal_meetings_when_skip_internal_enabled(): void
    {
        $this->account->update([
            'settings_json' => [
                'scan_mode'     => 'auto',
                'skip_internal' => true,
            ],
        ]);

        $service = $this->buildServiceWithAccount();

        // Should be skipped: all attendees are internal
        $event1 = $this->createMockEvent('Design Alignment', '', ['john@technopath.com', 'jane@technopath.com']);
        $this->assertFalse($service->isLikelyClientMeeting($event1));

        // Should be accepted: contains external attendee
        $event2 = $this->createMockEvent('Client Pitch', '', ['john@technopath.com', 'client@acme.com']);
        $this->assertTrue($service->isLikelyClientMeeting($event2));
    }

    public function test_filters_solo_meetings_when_skip_without_external_enabled(): void
    {
        $this->account->update([
            'settings_json' => [
                'scan_mode'             => 'auto',
                'skip_without_external' => true,
            ],
        ]);

        $service = $this->buildServiceWithAccount();

        // Should be skipped: no external attendees
        $event1 = $this->createMockEvent('Work block', '', ['john@technopath.com']);
        $this->assertFalse($service->isLikelyClientMeeting($event1));

        // Should be accepted: contains external attendee
        $event2 = $this->createMockEvent('Client Review', '', ['john@technopath.com', 'external@acme.com']);
        $this->assertTrue($service->isLikelyClientMeeting($event2));
    }

    public function test_filters_by_hashtag_mode(): void
    {
        $this->account->update([
            'settings_json' => [
                'scan_mode'        => 'hashtag',
                'include_keywords' => ['#client-meeting', '#prep-required'],
            ],
        ]);

        $service = $this->buildServiceWithAccount();

        // Should be skipped: no matching hashtags
        $event1 = $this->createMockEvent('Acme Project Catchup', '', ['john@technopath.com', 'external@acme.com']);
        $this->assertFalse($service->isLikelyClientMeeting($event1));

        // Should be accepted: contains #client-meeting hashtag
        $event2 = $this->createMockEvent('Catchup #client-meeting', '', ['john@technopath.com', 'external@acme.com']);
        $this->assertTrue($service->isLikelyClientMeeting($event2));

        // Should be accepted: contains #prep-required in description
        $event3 = $this->createMockEvent('Acme Project Sync', 'Please prepare slides, #prep-required', ['john@technopath.com', 'external@acme.com']);
        $this->assertTrue($service->isLikelyClientMeeting($event3));
    }

    public function test_jira_project_key_inheritance_on_sync(): void
    {
        // 1. Create a client with a Jira Project Key
        $client = Client::create([
            'name'             => 'Acme Corporation',
            'jira_project_key' => 'ACMEKEY',
            'status'           => 'active',
        ]);

        $service = (new \ReflectionClass(GoogleCalendarService::class))
            ->newInstanceWithoutConstructor();

        $refAccount = new \ReflectionProperty(GoogleCalendarService::class, 'account');
        $refAccount->setAccessible(true);
        $refAccount->setValue($service, $this->account);

        $refUser = new \ReflectionProperty(GoogleCalendarService::class, 'user');
        $refUser->setAccessible(true);
        $refUser->setValue($service, $this->user);

        // Create an event that matches the client "Acme Corporation"
        $event = $this->createMockEvent('Acme Corporation Sync Meeting', 'Sync');
        $event->setId('google-mock-event-id-123');

        $start = new \Google\Service\Calendar\EventDateTime();
        $start->setDateTime(now()->toRfc3339String());
        $event->setStart($start);

        $end = new \Google\Service\Calendar\EventDateTime();
        $end->setDateTime(now()->addHour()->toRfc3339String());
        $event->setEnd($end);

        // Let's call the private upsertMeetingFromEvent method using Reflection!
        $method = new \ReflectionMethod(GoogleCalendarService::class, 'upsertMeetingFromEvent');
        $method->setAccessible(true);

        /** @var \App\Models\ClientMeeting $meeting */
        $meeting = $method->invoke($service, $event);

        // Verify the meeting inherited the Client's Jira Project Key!
        $this->assertEquals($client->id, $meeting->client_id);
        $this->assertEquals('ACMEKEY', $meeting->project_key);

        // Now update the meeting with a manual override project_key
        $meeting->update(['project_key' => 'MANUAL-OVERRIDE']);

        // Run the upsert sync again, it should NOT overwrite the manual override!
        $meeting2 = $method->invoke($service, $event);
        $this->assertEquals('MANUAL-OVERRIDE', $meeting2->project_key);
    }
}
