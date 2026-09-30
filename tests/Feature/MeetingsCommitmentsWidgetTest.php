<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Filament\Widgets\Customer\MeetingsCommitmentsWidget;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\MeetingActionItem;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MeetingsCommitmentsWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_client_user_can_view_meeting_details_modal_with_meeting_link(): void
    {
        $client = Client::create([
            'name'          => 'Cambro',
            'industry'      => 'Manufacturing',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $clientUser = User::factory()->create([
            'email'    => 'client@cambro.com',
            'is_admin' => false,
        ]);

        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $host = User::factory()->create(['name' => 'Nour Ayman']);

        $meeting = ClientMeeting::create([
            'client_id'         => $client->id,
            'title'             => 'Cambro/Technopath Sync',
            'meeting_start_at'  => now()->addDays(2),
            'internal_owner_id' => $host->id,
            'status'            => MeetingStatus::Detected,
            'metadata'          => [
                'meet_link'       => 'https://meet.google.com/abc-defg-hij',
                'html_link'       => 'https://www.google.com/calendar/event?eid=123456',
                'organizer_email' => 'nour@technopath.co',
            ],
        ]);

        MeetingActionItem::create([
            'client_meeting_id'  => $meeting->id,
            'title'              => 'Review proposed Q4 roadmap',
            'owner_name'         => 'Nour Ayman',
            'due_date'           => now()->addDays(5),
            'status'             => 'open',
            'is_customer_facing' => true,
        ]);

        Livewire::actingAs($clientUser)
            ->test(MeetingsCommitmentsWidget::class)
            ->assertSuccessful()
            ->assertTableActionVisible('join_meeting', $meeting->id)
            ->assertTableActionVisible('view_meeting_details', $meeting->id)
            ->mountTableAction('view_meeting_details', $meeting->id)
            ->assertSee('Video Meeting Link')
            ->assertSee('https://meet.google.com/abc-defg-hij')
            ->assertSee('Join Meeting')
            ->assertSee('Nour Ayman')
            ->assertSee('Review proposed Q4 roadmap');
    }

    public function test_client_user_sees_prep_and_followup_sourced_from_sent_emails(): void
    {
        $client = Client::create([
            'name'          => 'Cambro Test',
            'industry'      => 'Manufacturing',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $clientUser = User::factory()->create([
            'email'    => 'client_prep@cambro.com',
            'is_admin' => false,
        ]);

        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $meeting = ClientMeeting::create([
            'client_id'         => $client->id,
            'title'             => 'Cambro Bi-Weekly Sync',
            'meeting_start_at'  => now()->addDays(1),
            'status'            => MeetingStatus::Detected,
        ]);

        \App\Models\MeetingPrep::create([
            'client_meeting_id'              => $meeting->id,
            'recommended_agenda'             => "1. Welcome and review of completed work – email fix (5 min)\n2. Staging review and sign-off – PDP FAQ (10 min)",
            'internal_summary'               => "PROJECT HEALTH: Overall GREEN/AMBER. Snapshot shows 10 tickets.\n\nCOMPLETED:\n- CMBR2-2224 (Internal only; exclude from customer comms).",
            'edited_status_email_subject'    => 'Status Update Before Our Meeting – Cambro',
            'edited_status_email_body'       => '<p>Hi Cambro team,</p><p>Ahead of our meeting, here is our reviewed status update.</p>',
            'email_sent_at'                  => now()->subHour(),
            'email_to'                       => 'client_prep@cambro.com',
            'ai_provider'                    => 'openai',
            'ai_model'                       => 'gpt-4o',
        ]);

        \App\Models\MeetingFollowUp::create([
            'client_meeting_id'                => $meeting->id,
            'edited_followup_email_subject'    => 'Meeting Summary and Next Steps – Cambro',
            'edited_followup_email_body'       => '<p>Thank you for meeting today. Here are the agreed next steps from our sync.</p>',
            'decisions'                        => ['Approved release for staging fixes', 'Next sync scheduled for next week'],
            'email_sent_at'                    => now()->subMinutes(30),
            'email_to'                         => 'client_prep@cambro.com',
        ]);

        Livewire::actingAs($clientUser)
            ->test(MeetingsCommitmentsWidget::class)
            ->assertSuccessful()
            ->mountTableAction('view_meeting_details', $meeting->id)
            ->assertSee('Meeting Agenda & Schedule')
            ->assertSee('Welcome and review of completed work')
            ->assertSee('5 min')
            ->assertSee('Status Update Before Our Meeting – Cambro')
            ->assertSee('Ahead of our meeting, here is our reviewed status update.')
            ->assertSee('Meeting Summary and Next Steps – Cambro')
            ->assertSee('Here are the agreed next steps from our sync.')
            ->assertSee('Approved release for staging fixes')
            ->assertDontSee('CMBR2-2224')
            ->assertDontSee('PROJECT HEALTH');
    }

    public function test_client_user_sees_in_preparation_placeholder_when_email_not_sent(): void
    {
        $client = Client::create([
            'name'          => 'Cambro Pending',
            'industry'      => 'Manufacturing',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $clientUser = User::factory()->create([
            'email'    => 'client_pending@cambro.com',
            'is_admin' => false,
        ]);

        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $meeting = ClientMeeting::create([
            'client_id'         => $client->id,
            'title'             => 'Cambro Pending Sync',
            'meeting_start_at'  => now()->addDays(1),
            'status'            => MeetingStatus::Detected,
        ]);

        \App\Models\MeetingPrep::create([
            'client_meeting_id'           => $meeting->id,
            'generated_status_email_body' => '<p>Internal unreviewed draft body</p>',
            'email_sent_at'               => null,
        ]);

        Livewire::actingAs($clientUser)
            ->test(MeetingsCommitmentsWidget::class)
            ->assertSuccessful()
            ->mountTableAction('view_meeting_details', $meeting->id)
            ->assertSee('Pre-Meeting Status Update In Preparation')
            ->assertDontSee('Internal unreviewed draft body');
    }

    public function test_admin_user_can_preview_draft_prep_before_sending(): void
    {
        $client = Client::create([
            'name'          => 'Cambro Admin Test',
            'industry'      => 'Manufacturing',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $adminUser = User::factory()->create([
            'email'    => 'admin@technopath.co',
            'is_admin' => true,
        ]);

        $meeting = ClientMeeting::create([
            'client_id'         => $client->id,
            'title'             => 'Admin Draft Test Meeting',
            'meeting_start_at'  => now()->addDays(1),
            'status'            => MeetingStatus::Detected,
        ]);

        \App\Models\MeetingPrep::create([
            'client_meeting_id'           => $meeting->id,
            'generated_status_email_body' => '<p>AI drafted email for internal review</p>',
            'email_sent_at'               => null,
        ]);

        session(['current_client_id' => $client->id]);

        Livewire::actingAs($adminUser)
            ->test(MeetingsCommitmentsWidget::class)
            ->assertSuccessful()
            ->mountTableAction('view_meeting_details', $meeting->id)
            ->assertSee('In Draft / Not Sent to Client Yet')
            ->assertSee('AI drafted email for internal review');
    }
}

