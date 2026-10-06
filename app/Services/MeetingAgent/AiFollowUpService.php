<?php

namespace App\Services\MeetingAgent;

use App\Models\ClientMeeting;
use App\Services\SystemPrompt\PromptManager;
use App\Services\SystemPrompt\ResponseContractValidator;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered meeting follow-up service.
 *
 * Generates post-meeting summaries, follow-up emails, decisions,
 * open questions, and suggested action items from meeting notes
 * and optional transcripts.
 */
class AiFollowUpService
{
    private readonly PromptManager $promptManager;
    private readonly MeetingContextResolver $contextResolver;
    private readonly ResponseContractValidator $validator;

    public function __construct(
        private readonly AiProviderService $ai,
        ?PromptManager $promptManager = null,
        ?MeetingContextResolver $contextResolver = null,
        ?ResponseContractValidator $validator = null,
    ) {
        $this->promptManager = $promptManager ?? app(PromptManager::class);
        $this->contextResolver = $contextResolver ?? app(MeetingContextResolver::class);
        $this->validator = $validator ?? app(ResponseContractValidator::class);
    }

    /**
     * Generate meeting follow-up content from notes and an optional transcript.
     *
     * @return array{
     *     summary: string,
     *     generated_followup_email_subject: string,
     *     generated_followup_email_body: string,
     *     decisions: string,
     *     open_questions: string,
     *     suggested_action_items: array,
     *     ai_provider: string,
     *     ai_model: string,
     * }
     */
    public function generateFollowUp(ClientMeeting $meeting, string $notes, ?string $transcript = null): array
    {
        $variables = $this->contextResolver->buildMeetingFollowUpVariables($meeting, $notes, $transcript);
        $resolved = $this->promptManager->resolve('meeting_followup', $variables);

        $result = $this->ai->completeJson($resolved['system_prompt'], $resolved['user_prompt']);

        $validation = $this->validator->validate('meeting_followup', $result);
        if (! $validation['valid']) {
            Log::warning('AiFollowUpService: AI response deviated from schema contract', [
                'meeting_id' => $meeting->id,
                'errors'     => $validation['errors'],
            ]);
        }

        $sanitizedActionItems = $this->validator->sanitizeActionItems($result['suggested_action_items'] ?? []);
        $clientName = $variables['client_name'];

        return [
            'summary'                         => $result['summary'] ?? '',
            'generated_followup_email_subject' => $result['followup_email_subject'] ?? "Meeting Summary and Next Steps – {$clientName}",
            'generated_followup_email_body'    => $result['followup_email_body'] ?? '',
            'decisions'                        => $result['decisions'] ?? '',
            'open_questions'                   => $result['open_questions'] ?? '',
            'suggested_action_items'           => $sanitizedActionItems,
            'ai_provider'                      => $this->ai->getProviderName(),
            'ai_model'                         => $this->ai->getModelName(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Prompt construction fallbacks
    // ─────────────────────────────────────────────────────────────────────

    public function buildFollowUpSystemPrompt(): string
    {
        return $this->promptManager->getDefinition('meeting_followup')->getDefaultSystemPrompt();
    }

    public function buildFollowUpUserPrompt(
        string $clientName,
        string $meetingTitle,
        string $meetingDate,
        string $attendees,
        string $notes,
        ?string $transcript = null
    ): string {
        $vars = [
            'client_name'         => $clientName,
            'client_contact_name' => '[Not Provided]',
            'meeting_title'       => $meetingTitle,
            'meeting_date'        => $meetingDate,
            'attendees'           => $attendees,
            'notes'               => $notes,
            'transcript'          => $transcript ?? '',
        ];

        return $this->promptManager->interpolate(
            $this->promptManager->getDefinition('meeting_followup')->getDefaultUserPromptTemplate(),
            $vars
        );
    }

    public function formatAttendeeList(ClientMeeting $meeting): string
    {
        return $this->contextResolver->resolveAttendeesString($meeting);
    }
}
