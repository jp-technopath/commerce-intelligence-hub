<?php

namespace Tests\Unit;

use App\Services\MeetingAgent\MeetingContentPresenter;
use Tests\TestCase;

class MeetingContentPresenterTest extends TestCase
{
    public function test_parses_agenda_with_numbers_and_durations(): void
    {
        $rawAgenda = <<<'AGENDA'
1. Welcome and review of completed work – email deliverability fix, configurable defaults update (5 min)
2. Staging review and sign-off – PDP FAQ layout, button alignment (10 min)
3. Actions, owners and next steps (5 min)
AGENDA;

        $parsed = MeetingContentPresenter::parseAgenda($rawAgenda);

        $this->assertEquals(3, $parsed['count']);
        $this->assertEquals('20 min', $parsed['total_duration']);

        $item1 = $parsed['items'][0];
        $this->assertEquals(1, $item1['number']);
        $this->assertEquals('Welcome and review of completed work', $item1['title']);
        $this->assertEquals('email deliverability fix, configurable defaults update', $item1['detail']);
        $this->assertEquals('5 min', $item1['duration']);

        $item2 = $parsed['items'][1];
        $this->assertEquals(2, $item2['number']);
        $this->assertEquals('Staging review and sign-off', $item2['title']);
        $this->assertEquals('PDP FAQ layout, button alignment', $item2['detail']);
        $this->assertEquals('10 min', $item2['duration']);
    }

    public function test_parses_empty_agenda_gracefully(): void
    {
        $parsed = MeetingContentPresenter::parseAgenda(null);
        $this->assertEquals(0, $parsed['count']);
        $this->assertNull($parsed['total_duration']);
        $this->assertEmpty($parsed['items']);
    }

    public function test_parses_internal_summary_health_and_sections(): void
    {
        $rawSummary = <<<'SUMMARY'
PROJECT HEALTH: Overall GREEN/AMBER. Snapshot (2026-09-30 15:40 UTC) shows 19 tickets: 6 Done, 6 In Progress, 4 in QA on Staging. Main watch items are the on hold tickets.

COMPLETED SINCE LAST MEETING (6):
- CMBR2-2224 (Highest) Emails being sent to spam – Done 30 Sep (Nour). Big customer-visible win.
- CMBR2-2191 (High) Mass Update Configurable Defaults – Done 28 Sep (Nour).

READY FOR REVIEW / QA ON STAGING (4):
- CMBR2-2188 (High) PDP FAQ/Specification vertical layout (Donia).
Action: request client sign-off on staging.

ITEMS NEEDING CUSTOMER INPUT:
- Approval of staging items
- Feedback on swatch guidance
SUMMARY;

        $parsed = MeetingContentPresenter::parseInternalSummary($rawSummary);

        $this->assertTrue($parsed['has_content']);
        $this->assertNotNull($parsed['health']);
        $this->assertEquals('GREEN/AMBER', $parsed['health']['status']);
        $this->assertEquals('warning', $parsed['health']['color']);
        $this->assertEquals(19, $parsed['health']['total_tickets']);
        $this->assertCount(3, $parsed['health']['ticket_breakdown']);

        $sections = $parsed['sections'];
        $this->assertCount(3, $sections);

        // Completed section
        $completed = $sections[0];
        $this->assertEquals('completed', $completed['key']);
        $this->assertEquals('emerald', $completed['color']);
        $this->assertCount(2, $completed['items']);
        $this->assertEquals('CMBR2-2224', $completed['items'][0]['ticket']);
        $this->assertEquals('Highest', $completed['items'][0]['priority']);
        $this->assertEquals('rose', $completed['items'][0]['priority_color']);
        $this->assertEquals('Nour', $completed['items'][0]['owner']);

        // QA section
        $qa = $sections[1];
        $this->assertEquals('qa', $qa['key']);
        $this->assertCount(1, $qa['action_notes']);
        $this->assertStringContainsString('request client sign-off', $qa['action_notes'][0]);

        // Customer input section
        $input = $sections[2];
        $this->assertEquals('customer_input', $input['key']);
        $this->assertCount(2, $input['items']);
    }

    public function test_parses_decisions_from_various_formats(): void
    {
        $stringDecisions = "- Approved release for staging fixes\n- Next sync scheduled for next Wednesday";
        $parsed1 = MeetingContentPresenter::parseDecisions($stringDecisions);
        $this->assertCount(2, $parsed1);
        $this->assertEquals('Approved release for staging fixes', $parsed1[0]);
        $this->assertEquals('Next sync scheduled for next Wednesday', $parsed1[1]);

        $arrayDecisions = [
            '1. Deploy cookie banner immediately',
            '2. Hold off on catalog changes until SEO sign-off',
        ];
        $parsed2 = MeetingContentPresenter::parseDecisions($arrayDecisions);
        $this->assertCount(2, $parsed2);
        $this->assertEquals('Deploy cookie banner immediately', $parsed2[0]);
    }

    public function test_format_email_body_cleans_html_and_styles(): void
    {
        $dirtyHtml = '<html><head><title>Test</title></head><body><p style="--tw-border-spacing-y: 0; color: #fff;">Hello <strong>World</strong></p></body></html>';
        $cleaned = MeetingContentPresenter::formatEmailBody($dirtyHtml);

        $this->assertStringNotContainsString('<html>', $cleaned);
        $this->assertStringNotContainsString('<head>', $cleaned);
        $this->assertStringNotContainsString('--tw-border-spacing-y', $cleaned);
        $this->assertStringContainsString('Hello <strong>World</strong>', $cleaned);

        $plain = "Line 1\nLine 2";
        $formattedPlain = MeetingContentPresenter::formatEmailBody($plain);
        $this->assertEquals("Line 1<br />\nLine 2", $formattedPlain);

        $this->assertEquals('', MeetingContentPresenter::formatEmailBody(null));
    }
}
