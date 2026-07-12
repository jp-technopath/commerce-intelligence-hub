<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\MeetingStatus;
use App\Jobs\MeetingAgent\GenerateMeetingPrep;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\MeetingPrep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeetingPrepFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => 'test-key',
            'meeting_agent.jira.base_url'     => 'https://test.atlassian.net',
            'meeting_agent.jira.email'        => 'test@technopath.com',
            'meeting_agent.jira.api_token'    => 'test-token',
            'meeting_agent.jira.status_mappings' => [
                'completed'   => ['Done', 'Closed'],
                'in_progress' => ['In Progress'],
                'blocked'     => ['Blocked'],
            ],
        ]);
    }

    public function test_generate_meeting_prep_creates_prep_record_with_correct_data(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Test Client']);

        $meeting = ClientMeeting::create([
            'client_id'         => $client->id,
            'title'             => 'Weekly Sync',
            'meeting_start_at'  => now()->addDays(2),
            'internal_owner_id' => $user->id,
            'status'            => MeetingStatus::Detected,
        ]);

        $aiResponse = [
            'internal_summary'       => 'Project is on track with 5 items completed.',
            'customer_email_subject' => 'Status Update – Test Client',
            'customer_email_body'    => '<p>Hi, here is your status update.</p>',
            'recommended_agenda'     => '1. Review progress\n2. Discuss blockers',
        ];

        Http::fake([
            // Jira search API
            'test.atlassian.net/*' => Http::response([
                'issues' => [
                    [
                        'key'    => 'TEST-1',
                        'fields' => [
                            'summary'  => 'Done task',
                            'status'   => ['name' => 'Done'],
                            'priority' => ['name' => 'Medium'],
                            'assignee' => ['displayName' => 'Alice'],
                            'updated'  => now()->toISOString(),
                        ],
                    ],
                ],
                'total' => 1,
            ], 200),

            // AI completion API
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode($aiResponse)]],
                ],
            ], 200),
        ]);

        // Dispatch job synchronously (queue connection is sync in testing)
        GenerateMeetingPrep::dispatch($meeting->id, 'TEST');

        // Verify MeetingPrep was created
        $prep = MeetingPrep::where('client_meeting_id', $meeting->id)->first();

        $this->assertNotNull($prep, 'MeetingPrep record should be created');
        $this->assertSame('TEST', $prep->jira_project_key);
        $this->assertSame('Project is on track with 5 items completed.', $prep->internal_summary);
        $this->assertSame('Status Update – Test Client', $prep->generated_status_email_subject);
        $this->assertNotNull($prep->generated_at);
        $this->assertNull($prep->ai_error);
        $this->assertSame('openrouter', $prep->ai_provider);
    }

    public function test_meeting_status_updated_to_prep_generated(): void
    {
        $client = Client::create(['name' => 'Test Client']);

        $meeting = ClientMeeting::create([
            'client_id'        => $client->id,
            'title'            => 'Status Review',
            'meeting_start_at' => now()->addDays(3),
            'status'           => MeetingStatus::Detected,
        ]);

        $aiResponse = [
            'internal_summary'       => 'Summary',
            'customer_email_subject' => 'Subject',
            'customer_email_body'    => 'Body',
            'recommended_agenda'     => 'Agenda',
        ];

        Http::fake([
            'test.atlassian.net/*' => Http::response([
                'issues' => [],
                'total'  => 0,
            ], 200),
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode($aiResponse)]],
                ],
            ], 200),
        ]);

        GenerateMeetingPrep::dispatch($meeting->id, 'TEST');

        $meeting->refresh();
        $this->assertSame(MeetingStatus::PrepGenerated, $meeting->status);
    }

    public function test_ai_error_saved_on_failure(): void
    {
        $client = Client::create(['name' => 'Test Client']);

        $meeting = ClientMeeting::create([
            'client_id'        => $client->id,
            'title'            => 'Failing Prep',
            'meeting_start_at' => now()->addDays(2),
            'status'           => MeetingStatus::Detected,
        ]);

        Http::fake([
            'test.atlassian.net/*' => Http::response([
                'issues' => [],
                'total'  => 0,
            ], 200),
            'openrouter.ai/*' => Http::response('Internal Server Error', 500),
        ]);

        try {
            GenerateMeetingPrep::dispatchSync($meeting->id, 'TEST');
        } catch (\Exception $e) {
            // Expected — the job rethrows after saving the error
        }

        $prep = MeetingPrep::where('client_meeting_id', $meeting->id)->first();

        $this->assertNotNull($prep, 'MeetingPrep record should exist even on failure');
        $this->assertNotNull($prep->ai_error, 'AI error should be saved');
        $this->assertStringContainsString('failed with status 500', $prep->ai_error);
    }
}
