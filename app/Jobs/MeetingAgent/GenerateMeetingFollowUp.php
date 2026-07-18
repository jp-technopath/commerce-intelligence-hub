<?php

namespace App\Jobs\MeetingAgent;

use App\Enums\MeetingStatus;
use App\Models\ClientMeeting;
use App\Models\MeetingFollowUp;
use App\Services\MeetingAgent\AiFollowUpService;
use App\Services\MeetingAgent\AiProviderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate AI-powered meeting follow-up from notes and optional transcript.
 * Creates or updates a MeetingFollowUp record with email, decisions,
 * open questions, and suggested action items.
 */
class GenerateMeetingFollowUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public readonly int $clientMeetingId,
        public readonly string $notes,
        public readonly ?string $transcript = null,
        public readonly ?string $model = null,
    ) {
        $this->onQueue('meetings');
    }

    public function handle(): void
    {
        $meeting = ClientMeeting::with('client')->findOrFail($this->clientMeetingId);

        Log::info('GenerateMeetingFollowUp: starting', [
            'meeting_id' => $this->clientMeetingId,
            'model'      => $this->model,
        ]);

        try {
            $aiProvider = app(AiProviderService::class);
            if ($this->model) {
                $aiProvider->setModel($this->model);
            }
            $followUpService = new AiFollowUpService($aiProvider);
            $followUpData = $followUpService->generateFollowUp($meeting, $this->notes, $this->transcript);

            // Create or update MeetingFollowUp record
            MeetingFollowUp::updateOrCreate(
                ['client_meeting_id' => $this->clientMeetingId],
                [
                    'raw_notes'                          => $this->notes,
                    'transcript_text'                    => $this->transcript,
                    'summary'                            => $followUpData['summary'],
                    'generated_followup_email_subject'    => $followUpData['generated_followup_email_subject'],
                    'generated_followup_email_body'       => $followUpData['generated_followup_email_body'],
                    'decisions'                          => $followUpData['decisions'],
                    'open_questions'                     => $followUpData['open_questions'],
                    'suggested_action_items'             => $followUpData['suggested_action_items'],
                    'ai_provider'                        => $followUpData['ai_provider'],
                    'ai_model'                           => $followUpData['ai_model'],
                    'ai_error'                           => null,
                    'generated_at'                       => now(),
                ]
            );

            // Update meeting status
            $meeting->update(['status' => MeetingStatus::FollowUpGenerated]);

            Log::info('GenerateMeetingFollowUp: completed', [
                'meeting_id' => $this->clientMeetingId,
            ]);

        } catch (\Exception $e) {
            Log::error('GenerateMeetingFollowUp: failed', [
                'meeting_id' => $this->clientMeetingId,
                'error'      => $e->getMessage(),
            ]);

            // Save the error to the follow-up record
            MeetingFollowUp::updateOrCreate(
                ['client_meeting_id' => $this->clientMeetingId],
                [
                    'raw_notes'  => $this->notes,
                    'ai_error'   => $e->getMessage(),
                ]
            );

            // Reset meeting status to PrepGenerated so it doesn't get stuck in FollowUpPending
            $meeting->update(['status' => MeetingStatus::PrepGenerated]);

            throw $e;
        }
    }
}
