<?php

namespace Tests\Unit\MeetingAgent;

use App\Models\Client;
use App\Services\MeetingAgent\GoogleCalendarService;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meeting_agent.calendar.company_domains'  => ['technopath.com'],
            'meeting_agent.calendar.exclude_patterns'  => [
                '/standup/i', '/stand-up/i', '/daily sync/i',
                '/sprint planning/i', '/retro/i', '/retrospective/i',
                '/1:1/i', '/1-on-1/i', '/internal/i', '/team meeting/i',
            ],
            'meeting_agent.calendar.include_hashtag' => '#customer-meeting',
        ]);
    }

    /**
     * Build a mock Event object with optional summary, description, and attendees.
     */
    private function buildMockEvent(
        string $summary = '',
        string $description = '',
        array $attendeeEmails = []
    ): Event {
        $event = $this->createMock(Event::class);
        $event->method('getSummary')->willReturn($summary);
        $event->method('getDescription')->willReturn($description);

        $attendees = [];
        foreach ($attendeeEmails as $email) {
            $attendee = $this->createMock(EventAttendee::class);
            $attendee->method('getEmail')->willReturn($email);
            $attendee->method('getDisplayName')->willReturn($email);
            $attendees[] = $attendee;
        }

        $event->method('getAttendees')->willReturn($attendees);

        return $event;
    }

    /**
     * Call isLikelyClientMeeting() without constructing the full service.
     * The method only uses config values and Client DB lookups, not Google API.
     */
    private function callIsLikelyClientMeeting(Event $event): bool
    {
        // Use ReflectionMethod to test without requiring Google API credentials
        $method = new \ReflectionMethod(GoogleCalendarService::class, 'isLikelyClientMeeting');

        // Create a partial mock that bypasses the constructor
        $service = (new \ReflectionClass(GoogleCalendarService::class))
            ->newInstanceWithoutConstructor();

        return $method->invoke($service, $event);
    }

    // ── External attendees ─────────────────────────────────────────────

    public function test_returns_true_when_external_attendee_present(): void
    {
        $event = $this->buildMockEvent(
            summary: 'Q3 Review Meeting',
            attendeeEmails: ['john@technopath.com', 'client@external-company.com']
        );

        $this->assertTrue($this->callIsLikelyClientMeeting($event));
    }

    public function test_returns_true_when_only_internal_attendees(): void
    {
        $event = $this->buildMockEvent(
            summary: 'Team Sync',
            attendeeEmails: ['john@technopath.com', 'jane@technopath.com']
        );

        $this->assertTrue($this->callIsLikelyClientMeeting($event));
    }

    // ── Exclude patterns ───────────────────────────────────────────────

    public function test_returns_false_when_title_matches_exclude_pattern(): void
    {
        $event = $this->buildMockEvent(
            summary: 'Daily Standup',
            attendeeEmails: ['john@technopath.com', 'client@external.com']
        );

        $this->assertFalse($this->callIsLikelyClientMeeting($event));
    }

    public function test_returns_false_for_internal_meeting_pattern(): void
    {
        $event = $this->buildMockEvent(
            summary: 'Sprint Planning Session',
            attendeeEmails: ['john@technopath.com', 'client@external.com']
        );

        $this->assertFalse($this->callIsLikelyClientMeeting($event));
    }

    // ── Include hashtag ────────────────────────────────────────────────

    public function test_returns_true_when_include_hashtag_in_description(): void
    {
        $event = $this->buildMockEvent(
            summary: 'Internal Meeting',
            description: 'This is tagged #customer-meeting',
            attendeeEmails: ['john@technopath.com']
        );

        $this->assertTrue($this->callIsLikelyClientMeeting($event));
    }

    public function test_returns_true_when_include_hashtag_in_title(): void
    {
        $event = $this->buildMockEvent(
            summary: 'Sync #customer-meeting',
            attendeeEmails: ['john@technopath.com']
        );

        $this->assertTrue($this->callIsLikelyClientMeeting($event));
    }

    // ── Known client name match ────────────────────────────────────────

    public function test_returns_true_when_known_client_name_in_title(): void
    {
        // Create a known client in the database
        Client::create(['name' => 'Acme Corp']);

        $event = $this->buildMockEvent(
            summary: 'Acme Corp Weekly Sync',
            attendeeEmails: ['john@technopath.com']
        );

        $this->assertTrue($this->callIsLikelyClientMeeting($event));
    }

    // ── No attendees, no matches ───────────────────────────────────────

    public function test_returns_true_when_no_attendees_and_no_pattern_match(): void
    {
        $event = $this->buildMockEvent(
            summary: 'My Personal Reminder',
            attendeeEmails: []
        );

        $this->assertTrue($this->callIsLikelyClientMeeting($event));
    }
}
