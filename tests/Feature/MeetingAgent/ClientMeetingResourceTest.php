<?php

namespace Tests\Feature\MeetingAgent;

use App\Filament\Resources\ClientMeetingResource;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMeetingResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_non_admin_user_can_only_retrieve_owned_meetings(): void
    {
        $client = Client::create(['name' => 'Acme', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

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

    public function test_can_view_any_returns_false_for_client_user_without_permission(): void
    {
        $client = Client::create(['name' => 'Acme', 'industry' => 'Retail', 'platform_type' => 'Shopify', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(ClientMeetingResource::canViewAny());
        $this->assertFalse(ClientMeetingResource::canCreate());
    }

    public function test_can_view_any_returns_true_for_super_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->assertTrue(ClientMeetingResource::canViewAny());
        $this->assertTrue(ClientMeetingResource::canCreate());
    }

    public function test_generate_prep_action_defaults_to_gpt_5_6_luna_pro_and_disables_other_models(): void
    {
        config([
            'meeting_agent.ai.provider' => 'openrouter',
            'meeting_agent.ai.openrouter_models' => [
                '~anthropic/claude-fable-latest',
                'anthropic/claude-opus-4.8',
                'openai/gpt-5.6-luna-pro',
                '~google/gemini-pro-latest',
            ],
        ]);

        $action = \App\Filament\Resources\ClientMeetingResource\Actions\GeneratePrepAction::make();
        $ref = new \ReflectionProperty($action, 'form');
        $ref->setAccessible(true);
        $fields = value($ref->getValue($action));

        $modelField = collect($fields)->first(fn ($field) => $field->getName() === 'model');
        $this->assertNotNull($modelField);

        $this->assertEquals('openai/gpt-5.6-luna-pro', $modelField->getDefaultState());

        $options = $modelField->getOptions();
        $this->assertArrayHasKey('openai/gpt-5.6-luna-pro', $options);
        $this->assertFalse($modelField->isOptionDisabled('openai/gpt-5.6-luna-pro', $options['openai/gpt-5.6-luna-pro']));

        foreach ($options as $key => $label) {
            if ($key !== 'openai/gpt-5.6-luna-pro') {
                $this->assertTrue($modelField->isOptionDisabled($key, $label), "Option {$key} should be disabled.");
            }
        }
    }

    public function test_generate_follow_up_action_defaults_to_gpt_5_6_luna_pro_and_disables_other_models(): void
    {
        config([
            'meeting_agent.ai.provider' => 'openrouter',
            'meeting_agent.ai.openrouter_models' => [
                '~anthropic/claude-fable-latest',
                'anthropic/claude-opus-4.8',
                'openai/gpt-5.6-luna-pro',
                '~google/gemini-pro-latest',
            ],
        ]);

        $action = \App\Filament\Resources\ClientMeetingResource\Actions\GenerateFollowUpAction::make();
        $ref = new \ReflectionProperty($action, 'form');
        $ref->setAccessible(true);
        $fields = value($ref->getValue($action));

        $modelField = collect($fields)->first(fn ($field) => $field->getName() === 'model');
        $this->assertNotNull($modelField);

        $this->assertEquals('openai/gpt-5.6-luna-pro', $modelField->getDefaultState());

        $options = $modelField->getOptions();
        $this->assertArrayHasKey('openai/gpt-5.6-luna-pro', $options);
        $this->assertFalse($modelField->isOptionDisabled('openai/gpt-5.6-luna-pro', $options['openai/gpt-5.6-luna-pro']));

        foreach ($options as $key => $label) {
            if ($key !== 'openai/gpt-5.6-luna-pro') {
                $this->assertTrue($modelField->isOptionDisabled($key, $label), "Option {$key} should be disabled.");
            }
        }
    }
}
