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

    public function test_client_user_sees_properly_formatted_meeting_agenda_and_breakdown(): void
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
            'client_meeting_id'  => $meeting->id,
            'recommended_agenda' => "1. Welcome and review of completed work – email fix (5 min)\n2. Staging review and sign-off – PDP FAQ (10 min)",
            'internal_summary'   => "PROJECT HEALTH: Overall GREEN/AMBER. Snapshot shows 10 tickets: 4 Done, 6 In Progress.\n\nCOMPLETED SINCE LAST MEETING (4):\n- CMBR2-2224 (Highest) Emails being sent to spam – Done 30 Sep (Nour).\n\nREADY FOR REVIEW / QA ON STAGING (2):\n- CMBR2-2188 (High) PDP FAQ/Specification vertical layout (Donia).\nAction: request client sign-off on staging.",
            'ai_provider'        => 'openai',
            'ai_model'           => 'gpt-4o',
        ]);

        Livewire::actingAs($clientUser)
            ->test(MeetingsCommitmentsWidget::class)
            ->assertSuccessful()
            ->mountTableAction('view_meeting_details', $meeting->id)
            ->assertSee('Meeting Agenda & Schedule')
            ->assertSee('Welcome and review of completed work')
            ->assertSee('email fix')
            ->assertSee('5 min')
            ->assertSee('Project Health Assessment')
            ->assertSee('GREEN/AMBER')
            ->assertSee('Ticket Breakdown')
            ->assertSee('CMBR2-2224')
            ->assertSee('Emails being sent to spam')
            ->assertSee('CMBR2-2188');
    }
}

