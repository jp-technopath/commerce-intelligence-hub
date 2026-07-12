<?php

namespace Tests\Unit\MeetingAgent;

use App\Models\MeetingPrep;
use Tests\TestCase;

class MeetingPrepModelTest extends TestCase
{
    // ── effectiveSubject() ──────────────────────────────────────────────

    public function test_effective_subject_returns_edited_when_both_exist(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_subject' => 'Generated Subject',
            'edited_status_email_subject'    => 'Edited Subject',
        ]);

        $this->assertSame('Edited Subject', $prep->effectiveSubject());
    }

    public function test_effective_subject_returns_generated_when_no_edited(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_subject' => 'Generated Subject',
            'edited_status_email_subject'    => null,
        ]);

        $this->assertSame('Generated Subject', $prep->effectiveSubject());
    }

    public function test_effective_subject_returns_null_when_both_null(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_subject' => null,
            'edited_status_email_subject'    => null,
        ]);

        $this->assertNull($prep->effectiveSubject());
    }

    // ── effectiveBody() ────────────────────────────────────────────────

    public function test_effective_body_returns_edited_when_both_exist(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_body' => '<p>Generated body</p>',
            'edited_status_email_body'    => '<p>Edited body</p>',
        ]);

        $this->assertSame('<p>Edited body</p>', $prep->effectiveBody());
    }

    public function test_effective_body_returns_generated_when_no_edited(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_body' => '<p>Generated body</p>',
            'edited_status_email_body'    => null,
        ]);

        $this->assertSame('<p>Generated body</p>', $prep->effectiveBody());
    }

    public function test_effective_body_returns_null_when_both_null(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_body' => null,
            'edited_status_email_body'    => null,
        ]);

        $this->assertNull($prep->effectiveBody());
    }

    // ── Edited value always wins ────────────────────────────────────────

    public function test_edited_value_always_takes_priority_over_generated(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_subject' => 'AI wrote this',
            'edited_status_email_subject'    => 'Human improved this',
            'generated_status_email_body'    => '<p>AI body</p>',
            'edited_status_email_body'       => '<p>Human body</p>',
        ]);

        // This is the safety test: edited content must override generated content
        $this->assertSame('Human improved this', $prep->effectiveSubject());
        $this->assertSame('<p>Human body</p>', $prep->effectiveBody());
        $this->assertNotSame($prep->generated_status_email_subject, $prep->effectiveSubject());
        $this->assertNotSame($prep->generated_status_email_body, $prep->effectiveBody());
    }
}
