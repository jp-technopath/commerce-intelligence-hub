<?php

namespace Tests\Unit\MeetingAgent;

use App\Models\MeetingFollowUp;
use Tests\TestCase;

class MeetingFollowUpModelTest extends TestCase
{
    // ── effectiveSubject() ──────────────────────────────────────────────

    public function test_effective_subject_returns_edited_when_both_exist(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_subject' => 'Generated Follow-Up Subject',
            'edited_followup_email_subject'    => 'Edited Follow-Up Subject',
        ]);

        $this->assertSame('Edited Follow-Up Subject', $followUp->effectiveSubject());
    }

    public function test_effective_subject_returns_generated_when_no_edited(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_subject' => 'Generated Follow-Up Subject',
            'edited_followup_email_subject'    => null,
        ]);

        $this->assertSame('Generated Follow-Up Subject', $followUp->effectiveSubject());
    }

    public function test_effective_subject_returns_null_when_both_null(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_subject' => null,
            'edited_followup_email_subject'    => null,
        ]);

        $this->assertNull($followUp->effectiveSubject());
    }

    // ── effectiveBody() ────────────────────────────────────────────────

    public function test_effective_body_returns_edited_when_both_exist(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_body' => '<p>Generated follow-up body</p>',
            'edited_followup_email_body'    => '<p>Edited follow-up body</p>',
        ]);

        $this->assertSame('<p>Edited follow-up body</p>', $followUp->effectiveBody());
    }

    public function test_effective_body_returns_generated_when_no_edited(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_body' => '<p>Generated follow-up body</p>',
            'edited_followup_email_body'    => null,
        ]);

        $this->assertSame('<p>Generated follow-up body</p>', $followUp->effectiveBody());
    }

    public function test_effective_body_returns_null_when_both_null(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_body' => null,
            'edited_followup_email_body'    => null,
        ]);

        $this->assertNull($followUp->effectiveBody());
    }

    // ── suggested_action_items is cast as array ────────────────────────

    public function test_suggested_action_items_is_cast_as_array(): void
    {
        $items = [
            ['title' => 'Implement feature X', 'owner_name' => 'John', 'due_date' => '2026-07-15'],
            ['title' => 'Review design', 'owner_name' => 'Jane', 'due_date' => null],
        ];

        $followUp = new MeetingFollowUp([
            'suggested_action_items' => $items,
        ]);

        $this->assertIsArray($followUp->suggested_action_items);
        $this->assertCount(2, $followUp->suggested_action_items);
        $this->assertSame('Implement feature X', $followUp->suggested_action_items[0]['title']);
    }

    // ── Edited value always wins ────────────────────────────────────────

    public function test_edited_value_always_takes_priority_over_generated(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_subject' => 'AI wrote this',
            'edited_followup_email_subject'    => 'Human improved this',
            'generated_followup_email_body'    => '<p>AI body</p>',
            'edited_followup_email_body'       => '<p>Human body</p>',
        ]);

        $this->assertSame('Human improved this', $followUp->effectiveSubject());
        $this->assertSame('<p>Human body</p>', $followUp->effectiveBody());
    }
}
