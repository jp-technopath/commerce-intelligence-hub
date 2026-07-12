<?php

namespace App\Services\MeetingAgent;

use App\Models\ClientMeeting;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered meeting preparation service.
 *
 * Generates internal summaries, customer-facing status update emails,
 * and recommended meeting agendas from Jira project data.
 */
class AiMeetingPrepService
{
    public function __construct(private readonly AiProviderService $ai)
    {
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
        $client = $meeting->client;
        $clientName = $client?->name ?? 'Unknown Client';
        $clientIndustry = $client?->industry ?? 'unknown';
        $clientPlatform = $client?->platform_type ?? 'unknown';

        $meetingTitle = $meeting->title;
        $meetingDate = $meeting->meeting_start_at?->format('l, F j, Y') ?? 'TBD';
        $meetingTime = $meeting->meeting_start_at?->format('g:i A') ?? '';

        $attendees = $this->formatAttendeeList($meeting);
        $jiraData = json_encode($jiraSnapshot, JSON_PRETTY_PRINT);

        $systemPrompt = $this->buildPrepSystemPrompt();
        $userPrompt = $this->buildPrepUserPrompt(
            $clientName,
            $clientIndustry,
            $clientPlatform,
            $meetingTitle,
            $meetingDate,
            $meetingTime,
            $attendees,
            $jiraData
        );

        $result = $this->ai->completeJson($systemPrompt, $userPrompt);

        return [
            'internal_summary'                => $result['internal_summary'] ?? '',
            'generated_status_email_subject'   => $result['customer_email_subject'] ?? "Status Update Before Our Meeting – {$clientName}",
            'generated_status_email_body'      => $result['customer_email_body'] ?? '',
            'recommended_agenda'               => $result['recommended_agenda'] ?? '',
            'ai_provider'                      => $this->ai->getProviderName(),
            'ai_model'                         => $this->ai->getModelName(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Prompt construction
    // ─────────────────────────────────────────────────────────────────────

    private function buildPrepSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are a senior project manager assistant at a digital agency specialising in ecommerce and technology solutions for commercial foodservice and hospitality clients.

Your task is to generate meeting preparation materials from Jira project data.

CRITICAL GUARDRAILS:
- Do NOT invent or fabricate any data. Only use information provided in the Jira snapshot.
- If information is missing or incomplete, clearly mark it as "[Information Not Available]".
- Clearly separate internal-only content from customer-facing content.
- Keep the customer-facing email professional, concise, and positive in tone.
- Do not include internal commentary, ticket IDs, or technical jargon in the customer email.
- The customer email should focus on progress, blockers requiring their attention, and upcoming items.

Respond in this exact JSON format (no markdown, no code blocks, just raw JSON):
{
  "internal_summary": "A detailed internal-only summary covering all status categories, risks, and talking points for the team",
  "customer_email_subject": "Status Update Before Our Meeting – [Client / Project Name]",
  "customer_email_body": "The full customer-facing email body in HTML format",
  "recommended_agenda": "A numbered list of recommended meeting agenda items"
}
SYSTEM;
    }

    private function buildPrepUserPrompt(
        string $clientName,
        string $clientIndustry,
        string $clientPlatform,
        string $meetingTitle,
        string $meetingDate,
        string $meetingTime,
        string $attendees,
        string $jiraData
    ): string {
        return <<<PROMPT
CLIENT CONTEXT:
- Client: {$clientName}
- Industry: {$clientIndustry}
- Platform: {$clientPlatform}

MEETING CONTEXT:
- Title: {$meetingTitle}
- Date: {$meetingDate} {$meetingTime}
- Attendees: {$attendees}

JIRA PROJECT SNAPSHOT:
{$jiraData}

Please generate:

1. An INTERNAL SUMMARY for the team covering:
   - Overall project health assessment
   - Completed items since last meeting
   - Items currently in progress
   - Blockers and risks
   - Items needing customer input/decisions
   - Key talking points and recommendations

2. A CUSTOMER-FACING STATUS UPDATE EMAIL with:
   Subject: Status Update Before Our Meeting – {$clientName}
   Body structure:
   - Greeting: "Hi [client contact name],"
   - Opening: "Ahead of our meeting on {$meetingDate}, here's a quick summary of where things stand:"
   - Completed Since Last Meeting (bullet points, plain language)
   - Currently In Progress (bullet points, plain language)
   - Blockers / Items Needing Your Input (if any — flag things that require customer decision/action)
   - Items Ready for Review (if any)
   - Proposed Agenda for our meeting
   - Closing: "Please let us know if you'd like to add anything to the agenda. Looking forward to speaking with you."
   - Sign-off: "Best,"

3. A RECOMMENDED AGENDA for the meeting (numbered list, 15-30 min slots).
PROMPT;
    }

    private function formatAttendeeList(ClientMeeting $meeting): string
    {
        $parts = [];

        $internal = $meeting->internal_attendees ?? [];
        foreach ($internal as $attendee) {
            $parts[] = ($attendee['name'] ?? $attendee['email'] ?? 'Unknown') . ' (internal)';
        }

        $external = $meeting->external_attendees ?? [];
        foreach ($external as $attendee) {
            $parts[] = ($attendee['name'] ?? $attendee['email'] ?? 'Unknown') . ' (external)';
        }

        return implode(', ', $parts) ?: 'No attendees listed';
    }
}
