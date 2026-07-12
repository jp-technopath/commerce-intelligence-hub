<?php

namespace App\Services\MeetingAgent;

use App\Models\ClientMeeting;
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
    public function __construct(private readonly AiProviderService $ai)
    {
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
        $client = $meeting->client;
        $clientName = $client?->name ?? 'Unknown Client';

        $meetingTitle = $meeting->title;
        $meetingDate = $meeting->meeting_start_at?->format('l, F j, Y') ?? 'TBD';
        $attendees = $this->formatAttendeeList($meeting);

        $systemPrompt = $this->buildFollowUpSystemPrompt();
        $userPrompt = $this->buildFollowUpUserPrompt(
            $clientName,
            $meetingTitle,
            $meetingDate,
            $attendees,
            $notes,
            $transcript
        );

        $result = $this->ai->completeJson($systemPrompt, $userPrompt);

        return [
            'summary'                           => $result['summary'] ?? '',
            'generated_followup_email_subject'   => $result['followup_email_subject'] ?? "Meeting Summary and Next Steps – {$clientName}",
            'generated_followup_email_body'      => $result['followup_email_body'] ?? '',
            'decisions'                          => $result['decisions'] ?? '',
            'open_questions'                     => $result['open_questions'] ?? '',
            'suggested_action_items'             => $result['suggested_action_items'] ?? [],
            'ai_provider'                        => $this->ai->getProviderName(),
            'ai_model'                           => $this->ai->getModelName(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Prompt construction
    // ─────────────────────────────────────────────────────────────────────

    private function buildFollowUpSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are a senior project manager assistant at a digital agency. Your task is to generate post-meeting follow-up materials from meeting notes and optional transcripts.

CRITICAL GUARDRAILS:
- Do NOT invent or fabricate information not present in the notes/transcript.
- If something is unclear, flag it as an open question rather than guessing.
- Clearly distinguish between decisions made and items still open.
- Action items must include a specific owner and realistic due date when possible.
- Mark items as customer_facing: true if they require the customer's attention or action.
- The follow-up email should be professional, concise, and action-oriented.
- Do not include internal commentary or confidential details in the customer-facing email.

Respond in this exact JSON format (no markdown, no code blocks, just raw JSON):
{
  "summary": "A concise summary of the meeting covering key discussions and outcomes",
  "followup_email_subject": "Meeting Summary and Next Steps – [Client / Project Name]",
  "followup_email_body": "The full customer-facing follow-up email body in HTML format",
  "decisions": "A list of decisions made during the meeting, one per line",
  "open_questions": "A list of unresolved questions or items needing further discussion",
  "suggested_action_items": [
    {
      "title": "Brief description of the action item",
      "owner_name": "Name of the responsible person",
      "due_date": "YYYY-MM-DD or null if not discussed",
      "is_customer_facing": true
    }
  ]
}
SYSTEM;
    }

    private function buildFollowUpUserPrompt(
        string $clientName,
        string $meetingTitle,
        string $meetingDate,
        string $attendees,
        string $notes,
        ?string $transcript
    ): string {
        $prompt = <<<PROMPT
MEETING CONTEXT:
- Client: {$clientName}
- Meeting: {$meetingTitle}
- Date: {$meetingDate}
- Attendees: {$attendees}

MEETING NOTES:
{$notes}
PROMPT;

        if ($transcript) {
            $prompt .= <<<TRANSCRIPT

MEETING TRANSCRIPT:
{$transcript}
TRANSCRIPT;
        }

        $prompt .= <<<INSTRUCTIONS

Please generate:

1. A concise MEETING SUMMARY covering key points discussed and outcomes.

2. A CUSTOMER-FACING FOLLOW-UP EMAIL with:
   Subject: Meeting Summary and Next Steps – {$clientName}
   Body structure:
   - Greeting: "Hi [client contact name],"
   - Opening: "Thank you for taking the time to meet with us on {$meetingDate}. Here's a summary of our discussion and agreed next steps:"
   - Key Points Discussed (bullet points)
   - Decisions Made (bullet points)
   - Action Items (table or list with owner and due date)
   - Open Questions (if any)
   - Next Steps / Next Meeting
   - Closing: "Please don't hesitate to reach out if anything needs clarification."
   - Sign-off: "Best,"

3. A list of DECISIONS made during the meeting.

4. A list of OPEN QUESTIONS that still need resolution.

5. SUGGESTED ACTION ITEMS in the specified JSON format, with owner_name, due_date, and is_customer_facing flag.
INSTRUCTIONS;

        return $prompt;
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
