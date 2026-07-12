<?php

namespace Tests\Feature\MeetingAgent;

use App\Filament\Resources\ClientMeetingResource;
use App\Models\ClientMeeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMeetingResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_user_can_only_retrieve_owned_meetings(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);

        // Create owned meeting
        $ownedMeeting = ClientMeeting::create([
            'title'             => 'My Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $user->id,
        ]);

        // Create other user meeting
        $otherMeeting = ClientMeeting::create([
            'title'             => 'Other Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $otherUser->id,
        ]);

        // Create unassigned meeting
        $unassignedMeeting = ClientMeeting::create([
            'title'             => 'Unassigned Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => null,
        ]);

        $this->actingAs($user);

        $results = ClientMeetingResource::getEloquentQuery()->get();

        $this->assertTrue($results->contains($ownedMeeting));
        $this->assertFalse($results->contains($otherMeeting));
        $this->assertFalse($results->contains($unassignedMeeting));
    }

    public function test_admin_user_can_retrieve_all_meetings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $otherUser = User::factory()->create(['is_admin' => false]);

        // Create owned meeting
        $ownedMeeting = ClientMeeting::create([
            'title'             => 'My Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $admin->id,
        ]);

        // Create other user meeting
        $otherMeeting = ClientMeeting::create([
            'title'             => 'Other Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => $otherUser->id,
        ]);

        // Create unassigned meeting
        $unassignedMeeting = ClientMeeting::create([
            'title'             => 'Unassigned Meeting',
            'meeting_start_at'  => now()->addDay(),
            'internal_owner_id' => null,
        ]);

        $this->actingAs($admin);

        $results = ClientMeetingResource::getEloquentQuery()->get();

        $this->assertTrue($results->contains($ownedMeeting));
        $this->assertTrue($results->contains($otherMeeting));
        $this->assertTrue($results->contains($unassignedMeeting));
    }
}
