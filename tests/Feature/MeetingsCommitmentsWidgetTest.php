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
}
