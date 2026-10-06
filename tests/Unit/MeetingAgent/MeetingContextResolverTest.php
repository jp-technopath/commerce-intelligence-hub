<?php

namespace Tests\Unit\MeetingAgent;

use App\Models\Client;
use App\Models\ClientMeeting;
use App\Services\MeetingAgent\MeetingContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private MeetingContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MeetingContextResolver();
    }

    public function test_resolves_explicit_primary_contact_name_from_metadata(): void
    {
        $meeting = new ClientMeeting([
            'metadata' => ['primary_contact_name' => 'Alice Henderson'],
            'external_attendees' => [
                ['name' => 'Bob Builder', 'email' => 'bob@example.com'],
            ],
        ]);

        $contact = $this->resolver->resolveClientContactName($meeting);
        $this->assertEquals('Alice Henderson', $contact);
    }

    public function test_resolves_first_named_external_attendee_when_explicit_absent(): void
    {
        $meeting = new ClientMeeting([
            'metadata' => [],
            'external_attendees' => [
                ['name' => 'Carol Danvers', 'email' => 'carol@example.com'],
                ['name' => 'Dave Miller', 'email' => 'dave@example.com'],
            ],
        ]);

        $contact = $this->resolver->resolveClientContactName($meeting);
        $this->assertEquals('Carol Danvers', $contact);
    }

    public function test_falls_back_to_not_provided_when_no_external_attendees(): void
    {
        $meeting = new ClientMeeting([
            'metadata' => [],
            'external_attendees' => [],
        ]);

        $contact = $this->resolver->resolveClientContactName($meeting);
        $this->assertEquals('[Not Provided]', $contact);
    }

    public function test_resolves_attendees_string_with_internal_and_external_labels(): void
    {
        $meeting = new ClientMeeting([
            'internal_attendees' => [
                ['name' => 'Eve Internal', 'email' => 'eve@technopath.com'],
            ],
            'external_attendees' => [
                ['name' => 'Frank External', 'email' => 'frank@client.com'],
            ],
        ]);

        $formatted = $this->resolver->resolveAttendeesString($meeting);
        $this->assertEquals('Eve Internal (internal), Frank External (external)', $formatted);
    }
}
