<?php

namespace Tests\Feature\MeetingAgent;

use App\Models\User;
use App\Models\ClientMeeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingFollowUp;
use App\Services\MeetingAgent\JiraService;
use App\Filament\Resources\ClientMeetingResource\Pages\ViewClientMeeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class JiraTaskCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meeting_agent.jira.base_url'  => 'https://test-jira.atlassian.net',
            'meeting_agent.jira.email'     => 'test@technopath.com',
            'meeting_agent.jira.api_token' => 'test-jira-token',
        ]);
    }

    public function test_can_create_jira_task_for_action_item_via_livewire_action(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $meeting = ClientMeeting::create([
            'title'             => 'Follow-up Test Meeting',
            'meeting_start_at'  => now(),
            'internal_owner_id' => $user->id,
            'project_key'       => 'KAN',
        ]);

        $followUp = MeetingFollowUp::create([
            'client_meeting_id' => $meeting->id,
            'summary'           => 'Test summary',
        ]);

        $actionItem = MeetingActionItem::create([
            'client_meeting_id'    => $meeting->id,
            'meeting_follow_up_id' => $followUp->id,
            'title'                => 'Implement Jira integration',
            'description'          => 'Create tasks dynamically',
            'owner_name'           => 'Nick Barretta',
        ]);

        Http::fake([
            'https://test-jira.atlassian.net/rest/api/3/user/search*' => Http::response([
                [
                    'accountId' => 'acc-id-12345',
                    'displayName' => 'Nick Barretta',
                ]
            ], 200),
            'https://test-jira.atlassian.net/rest/api/3/issue' => Http::response([
                'key' => 'KAN-987'
            ], 201),
        ]);

        Livewire::actingAs($user)
            ->test(ViewClientMeeting::class, [
                'record' => $meeting->getKey(),
            ])
            ->call('createJiraTaskForActionItem', $actionItem->id);

        $actionItem->refresh();
        $this->assertEquals('KAN-987', $actionItem->jira_issue_key);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/rest/api/3/issue')) {
                $data = json_decode($request->body(), true);
                return ($data['fields']['project']['key'] ?? null) === 'KAN'
                    && ($data['fields']['summary'] ?? null) === 'Implement Jira integration'
                    && ($data['fields']['assignee']['accountId'] ?? null) === 'acc-id-12345';
            }
            return true;
        });
    }

    public function test_jira_task_description_is_enriched_via_ai_when_context_exists(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $meeting = ClientMeeting::create([
            'title'             => 'Follow-up Test Meeting',
            'meeting_start_at'  => now(),
            'internal_owner_id' => $user->id,
            'project_key'       => 'KAN',
        ]);

        $followUp = MeetingFollowUp::create([
            'client_meeting_id' => $meeting->id,
            'summary'           => 'Important discussion summary',
            'raw_notes'         => 'Raw technical notes',
            'transcript_text'   => 'Meeting transcript text',
        ]);

        $actionItem = MeetingActionItem::create([
            'client_meeting_id'    => $meeting->id,
            'meeting_follow_up_id' => $followUp->id,
            'title'                => 'Implement Jira integration',
            'description'          => 'Create tasks dynamically',
            'owner_name'           => 'Nick Barretta',
        ]);

        // Mock AiProviderService
        $mockAi = $this->mock(\App\Services\MeetingAgent\AiProviderService::class);
        $mockAi->shouldReceive('complete')
            ->once()
            ->andReturn('Enriched AI description text.');

        Http::fake([
            'https://test-jira.atlassian.net/rest/api/3/user/search*' => Http::response([
                [
                    'accountId' => 'acc-id-12345',
                    'displayName' => 'Nick Barretta',
                ]
            ], 200),
            'https://test-jira.atlassian.net/rest/api/3/issue' => Http::response([
                'key' => 'KAN-987'
            ], 201),
        ]);

        Livewire::actingAs($user)
            ->test(ViewClientMeeting::class, [
                'record' => $meeting->getKey(),
            ])
            ->call('createJiraTaskForActionItem', $actionItem->id);

        $actionItem->refresh();
        $this->assertEquals('KAN-987', $actionItem->jira_issue_key);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/rest/api/3/issue')) {
                $data = json_decode($request->body(), true);
                return ($data['fields']['project']['key'] ?? null) === 'KAN'
                    && ($data['fields']['summary'] ?? null) === 'Implement Jira integration'
                    && ($data['fields']['description']['content'][0]['content'][0]['text'] ?? null) === 'Enriched AI description text.';
            }
            return true;
        });
    }
}
