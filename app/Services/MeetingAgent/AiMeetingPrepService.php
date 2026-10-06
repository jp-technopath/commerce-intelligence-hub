<?php

namespace App\Services\MeetingAgent;

use App\Models\ClientMeeting;
use App\Services\SystemPrompt\PromptManager;
use App\Services\SystemPrompt\ResponseContractValidator;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered meeting preparation service.
 *
 * Generates internal summaries, status update emails, and recommended
 * meeting agendas from Jira project data.
 */
class AiMeetingPrepService
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
     * Generate meeting preparation content from a Jira snapshot.
     *
     * @return array{
     *     internal_summary: string,
     *     generated_status_email_subject: string,
     *     generated_status_email_body: string,
     *     recommended_agenda: string,
     *     ai_provider: string,
     *     ai_model: string,
     * }
     */
    public function generatePrep(ClientMeeting $meeting, array $jiraSnapshot): array
    {
        $variables = $this->contextResolver->buildMeetingPrepVariables($meeting, $jiraSnapshot);
        $resolved = $this->promptManager->resolve('meeting_prep', $variables);

        $result = $this->ai->completeJson($resolved['system_prompt'], $resolved['user_prompt']);

        $validation = $this->validator->validate('meeting_prep', $result);
        if (! $validation['valid']) {
            Log::warning('AiMeetingPrepService: AI response deviated from schema contract', [
                'meeting_id' => $meeting->id,
                'errors'     => $validation['errors'],
            ]);
        }

        $clientName = $variables['client_name'];

        return [
            'internal_summary'               => $result['internal_summary'] ?? '',
            'generated_status_email_subject' => $result['customer_email_subject'] ?? "Status Update Before Our Meeting – {$clientName}",
            'generated_status_email_body'    => $result['customer_email_body'] ?? '',
            'recommended_agenda'              => $result['recommended_agenda'] ?? '',
            'ai_provider'                    => $this->ai->getProviderName(),
            'ai_model'                       => $this->ai->getModelName(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Prompt construction fallbacks
    // ─────────────────────────────────────────────────────────────────────

    public function buildPrepSystemPrompt(): string
    {
        return $this->promptManager->getDefinition('meeting_prep')->getDefaultSystemPrompt();
    }

    public function buildPrepUserPrompt(
        string $clientName,
        string $clientIndustry,
        string $clientPlatform,
        string $meetingTitle,
        string $meetingDate,
        string $meetingTime,
        string $attendees,
        string $jiraData
    ): string {
        $vars = [
            'client_name'         => $clientName,
            'client_contact_name' => '[Not Provided]',
            'client_industry'     => $clientIndustry,
            'client_platform'     => $clientPlatform,
            'meeting_title'       => $meetingTitle,
            'meeting_date'        => $meetingDate,
            'meeting_time'        => $meetingTime,
            'attendees'           => $attendees,
            'jira_data'           => $jiraData,
        ];

        return $this->promptManager->interpolate(
            $this->promptManager->getDefinition('meeting_prep')->getDefaultUserPromptTemplate(),
            $vars
        );
    }

    public function formatAttendeeList(ClientMeeting $meeting): string
    {
        return $this->contextResolver->resolveAttendeesString($meeting);
    }
}
