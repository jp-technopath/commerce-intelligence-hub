<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\MeetingStatus;
use App\Jobs\MeetingAgent\GenerateMeetingFollowUp;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingFollowUp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeetingFollowUpFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => 'test-key',
        ]);
    }

    public function test_generate_meeting_followup_creates_followup_record(): void
    {
        $client = Client::create(['name' => 'Test Client']);

        $meeting = ClientMeeting::create([
            'client_id'        => $client->id,
            'title'            => 'Client Review',
            'meeting_start_at' => now()->subHours(2),
            'status'           => MeetingStatus::Completed,
        ]);

        $aiResponse = [
            'summary'                => 'Meeting covered Q3 deliverables and timeline.',
            'followup_email_subject' => 'Meeting Summary – Test Client',
            'followup_email_body'    => '<p>Thank you for the meeting.</p>',
            'decisions'              => 'Agreed to launch on Aug 15.',
            'open_questions'         => 'Budget for Phase 2?',
            'suggested_action_items' => [
                [
                    'title'              => 'Finalize design mockups',
                    'owner_name'         => 'Alice',
                    'due_date'           => '2026-07-15',
                    'is_customer_facing' => false,
                ],
                [
                    'title'              => 'Send updated SOW',
                    'owner_name'         => 'Bob',
                    'due_date'           => '2026-07-10',
                    'is_customer_facing' => true,
                ],
            ],
        ];

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode($aiResponse)]],
                ],
            ], 200),
        ]);

        $notes = "Discussed Q3 timeline. Client approved design direction.";

        GenerateMeetingFollowUp::dispatch($meeting->id, $notes);

        // Verify MeetingFollowUp was created
        $followUp = MeetingFollowUp::where('client_meeting_id', $meeting->id)->first();

        $this->assertNotNull($followUp, 'MeetingFollowUp record should be created');
        $this->assertSame('Meeting covered Q3 deliverables and timeline.', $followUp->summary);
        $this->assertSame('Meeting Summary – Test Client', $followUp->generated_followup_email_subject);
        $this->assertSame($notes, $followUp->raw_notes);
        $this->assertNotNull($followUp->generated_at);
        $this->assertNull($followUp->ai_error);
    }

    public function test_followup_record_contains_suggested_action_items_as_json(): void
    {
        $client = Client::create(['name' => 'Test Client']);

        $meeting = ClientMeeting::create([
            'client_id'        => $client->id,
            'title'            => 'Planning Session',
            'meeting_start_at' => now()->subHour(),
            'status'           => MeetingStatus::Completed,
        ]);

        $actionItems = [
            ['title' => 'Task A', 'owner_name' => 'Alice', 'due_date' => '2026-07-20', 'is_customer_facing' => true],
            ['title' => 'Task B', 'owner_name' => 'Bob', 'due_date' => null, 'is_customer_facing' => false],
        ];

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'summary'                => 'Summary',
                        'followup_email_subject' => 'Subject',
                        'followup_email_body'    => 'Body',
                        'decisions'              => 'Decisions',
                        'open_questions'         => 'Questions',
                        'suggested_action_items' => $actionItems,
                    ])]],
                ],
            ], 200),
        ]);

        GenerateMeetingFollowUp::dispatch($meeting->id, 'Meeting notes here');

        $followUp = MeetingFollowUp::where('client_meeting_id', $meeting->id)->first();

        $this->assertIsArray($followUp->suggested_action_items);
        $this->assertCount(2, $followUp->suggested_action_items);
        $this->assertSame('Task A', $followUp->suggested_action_items[0]['title']);
    }

    public function test_suggested_action_items_not_auto_created_as_meeting_action_items(): void
    {
        $client = Client::create(['name' => 'Test Client']);

        $meeting = ClientMeeting::create([
            'client_id'        => $client->id,
            'title'            => 'Review Meeting',
            'meeting_start_at' => now()->subHour(),
            'status'           => MeetingStatus::Completed,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'summary'                => 'Summary',
                        'followup_email_subject' => 'Subject',
                        'followup_email_body'    => 'Body',
                        'decisions'              => '',
                        'open_questions'         => '',
                        'suggested_action_items' => [
                            ['title' => 'AI Suggested Task', 'owner_name' => 'Alice', 'due_date' => '2026-07-20'],
                        ],
                    ])]],
                ],
            ], 200),
        ]);

        GenerateMeetingFollowUp::dispatch($meeting->id, 'Notes');

        // Suggested action items are stored as JSON on MeetingFollowUp,
        // but should NOT be auto-created as MeetingActionItem records.
        // The user must explicitly accept/create action items.
        $this->assertSame(
            0,
            MeetingActionItem::where('client_meeting_id', $meeting->id)->count(),
            'Action items should NOT be auto-created from AI suggestions'
        );

        // Verify they exist as JSON on the follow-up
        $followUp = MeetingFollowUp::where('client_meeting_id', $meeting->id)->first();
        $this->assertNotEmpty($followUp->suggested_action_items);
    }

    public function test_meeting_status_updated_to_followup_generated(): void
    {
        $client = Client::create(['name' => 'Test Client']);

        $meeting = ClientMeeting::create([
            'client_id'        => $client->id,
            'title'            => 'Status Meeting',
            'meeting_start_at' => now()->subHour(),
            'status'           => MeetingStatus::Completed,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'summary'                => 'Summary',
                        'followup_email_subject' => 'Subject',
                        'followup_email_body'    => 'Body',
                        'decisions'              => '',
                        'open_questions'         => '',
                        'suggested_action_items' => [],
                    ])]],
                ],
            ], 200),
        ]);

        GenerateMeetingFollowUp::dispatch($meeting->id, 'Notes');

        $meeting->refresh();
        $this->assertSame(MeetingStatus::FollowUpGenerated, $meeting->status);
    }

    public function test_ai_error_saved_on_followup_failure(): void
    {
        $client = Client::create(['name' => 'Test Client']);

        $meeting = ClientMeeting::create([
            'client_id'        => $client->id,
            'title'            => 'Failing Follow-Up',
            'meeting_start_at' => now()->subHour(),
            'status'           => MeetingStatus::Completed,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response('Internal Server Error', 500),
        ]);

        try {
            GenerateMeetingFollowUp::dispatchSync($meeting->id, 'Notes');
        } catch (\Exception $e) {
            // Expected — the job rethrows after saving the error
        }

        $followUp = MeetingFollowUp::where('client_meeting_id', $meeting->id)->first();

        $this->assertNotNull($followUp, 'MeetingFollowUp record should exist even on failure');
        $this->assertNotNull($followUp->ai_error);
    }
}
