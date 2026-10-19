<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectLeadMeetingAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected User $userDonia;

    protected User $userNour;

    protected User $userJp;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userDonia = User::create([
            'id' => 3,
            'name' => 'Donia Attwa',
            'email' => 'donia@technopath.co',
            'password' => Hash::make('password123'),
        ]);

        $this->userNour = User::create([
            'id' => 7,
            'name' => 'Nour Ayman',
            'email' => 'nour@technopath.ai',
            'password' => Hash::make('password123'),
        ]);

        $this->userJp = User::create([
            'id' => 2,
            'name' => 'Jean Paul Hanna',
            'email' => 'jp@technopath.co',
            'password' => Hash::make('password123'),
        ]);

        $this->client = Client::create([
            'name' => 'USG',
            'jira_project_key' => 'USG',
            'status' => 'active',
            'project_lead_id' => null,
        ]);
    }

    public function test_client_has_project_lead_relationship(): void
    {
        $this->client->update(['project_lead_id' => $this->userDonia->id]);
        $this->assertInstanceOf(User::class, $this->client->projectLead);
        $this->assertEquals($this->userDonia->id, $this->client->projectLead->id);
    }

    public function test_project_has_project_lead_relationship_and_syncs_with_owner(): void
    {
        $project = Project::create([
            'client_id' => $this->client->id,
            'name' => 'USG Commerce',
            'code' => 'USG',
            'status' => 'active',
            'project_lead_id' => $this->userDonia->id,
        ]);

        $this->assertInstanceOf(User::class, $project->projectLead);
        $this->assertEquals($this->userDonia->id, $project->projectLead->id);
        $this->assertEquals($this->userDonia->id, $project->owner_id);

        $project->update(['owner_id' => $this->userJp->id]);
        $project->refresh();
        $this->assertEquals($this->userJp->id, $project->project_lead_id);
    }

    public function test_client_saving_reattributes_external_meetings_to_new_project_lead(): void
    {
        // External meeting organized by Benjamin White (Tech2Success / USG)
        $externalMeeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'USG - T2S & Technopath weekly check-in',
            'meeting_start_at' => now()->addDays(1),
            'meeting_end_at' => now()->addDays(1)->addHour(),
            'internal_owner_id' => $this->userNour->id,
            'status' => MeetingStatus::Detected,
            'metadata' => [
                'organizer_email' => 'benjamin@tech2success.com',
                'creator_email' => 'nour@technopath.co',
            ],
        ]);

        // Internal meeting organized by Nour
        $internalMeeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'Internal USG Sprint Review',
            'meeting_start_at' => now()->addDays(2),
            'meeting_end_at' => now()->addDays(2)->addHour(),
            'internal_owner_id' => $this->userNour->id,
            'status' => MeetingStatus::Detected,
            'metadata' => [
                'organizer_email' => 'nour@technopath.co',
                'creator_email' => 'nour@technopath.co',
            ],
        ]);

        // When client project lead is set to Donia
        $this->client->update(['project_lead_id' => $this->userDonia->id]);

        $externalMeeting->refresh();
        $internalMeeting->refresh();

        // External meeting is re-attributed to Donia (Project Lead)
        $this->assertEquals($this->userDonia->id, $externalMeeting->internal_owner_id);

        // Internal meeting organized by Nour remains owned by Nour
        $this->assertEquals($this->userNour->id, $internalMeeting->internal_owner_id);
    }

    public function test_scope_owned_or_created_by_includes_project_lead_and_excludes_non_lead_attendees_for_external_meetings(): void
    {
        // Meeting organized by external client Benjamin White, attributed to Project Lead Donia
        $externalMeeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'USG - T2S & Technopath weekly check-in',
            'meeting_start_at' => now()->addDays(1),
            'meeting_end_at' => now()->addDays(1)->addHour(),
            'internal_owner_id' => $this->userDonia->id,
            'status' => MeetingStatus::Detected,
            'metadata' => [
                'organizer_email' => 'benjamin@tech2success.com',
                'creator_email' => 'nour@technopath.co',
            ],
        ]);

        // Donia (the Project Lead and owner) MUST see it
        $doniaMeetings = ClientMeeting::ownedOrCreatedBy($this->userDonia)->get();
        $this->assertTrue($doniaMeetings->contains('id', $externalMeeting->id));

        // Nour (merely an attendee whose calendar created/synced the token) MUST NOT see it
        $nourMeetings = ClientMeeting::ownedOrCreatedBy($this->userNour)->get();
        $this->assertFalse($nourMeetings->contains('id', $externalMeeting->id));
    }

    public function test_scope_owned_or_created_by_still_includes_creator_for_internal_meetings(): void
    {
        // Internal meeting created and organized by Nour
        $internalMeeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'title' => 'Cambro - Internal Sync',
            'meeting_start_at' => now()->addDays(1),
            'meeting_end_at' => now()->addDays(1)->addHour(),
            'internal_owner_id' => $this->userNour->id,
            'status' => MeetingStatus::Detected,
            'metadata' => [
                'organizer_email' => 'nour@technopath.co',
                'creator_email' => 'nour@technopath.co',
            ],
        ]);

        $nourMeetings = ClientMeeting::ownedOrCreatedBy($this->userNour)->get();
        $this->assertTrue($nourMeetings->contains('id', $internalMeeting->id));

        $doniaMeetings = ClientMeeting::ownedOrCreatedBy($this->userDonia)->get();
        $this->assertFalse($doniaMeetings->contains('id', $internalMeeting->id));
    }

    public function test_project_saving_reattributes_external_meetings_to_new_project_lead(): void
    {
        $project = Project::create([
            'client_id' => $this->client->id,
            'name' => 'USG Web Store',
            'code' => 'USG',
            'jira_project_key' => 'USG',
            'status' => 'active',
            'project_lead_id' => $this->userNour->id,
        ]);

        $externalMeeting = ClientMeeting::create([
            'client_id' => $this->client->id,
            'project_key' => 'USG',
            'title' => 'USG - T2S & Technopath weekly check-in',
            'meeting_start_at' => now()->addDays(1),
            'meeting_end_at' => now()->addDays(1)->addHour(),
            'internal_owner_id' => $this->userNour->id,
            'status' => MeetingStatus::Detected,
            'metadata' => [
                'organizer_email' => 'benjamin@tech2success.com',
                'creator_email' => 'nour@technopath.co',
            ],
        ]);

        $project->update(['project_lead_id' => $this->userDonia->id]);

        $externalMeeting->refresh();
        $this->assertEquals($this->userDonia->id, $externalMeeting->internal_owner_id);
    }
}
