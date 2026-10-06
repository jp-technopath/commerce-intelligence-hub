<?php

namespace Tests\Unit\SystemPrompt;

use App\Services\SystemPrompt\ResponseContractValidator;
use PHPUnit\Framework\TestCase;

class ResponseContractValidatorTest extends TestCase
{
    private ResponseContractValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ResponseContractValidator();
    }

    public function test_validates_meeting_prep_schema_correctly(): void
    {
        $validData = [
            'internal_summary'       => 'Summary here',
            'customer_email_subject' => 'Subject here',
            'customer_email_body'    => '<p>Body</p>',
            'recommended_agenda'     => '1. Review',
        ];

        $result = $this->validator->validate('meeting_prep', $validData);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        $invalidData = [
            'internal_summary' => 'Only summary',
        ];

        $invalidResult = $this->validator->validate('meeting_prep', $invalidData);
        $this->assertFalse($invalidResult['valid']);
        $this->assertCount(3, $invalidResult['errors']);
    }

    public function test_validates_meeting_followup_schema_correctly(): void
    {
        $validData = [
            'summary'                => 'Executive summary',
            'followup_email_subject' => 'Subject',
            'followup_email_body'    => '<p>Email</p>',
            'decisions'              => 'Decided X',
            'open_questions'         => 'Open Y',
            'suggested_action_items' => [
                [
                    'title'              => 'Implement webhook',
                    'owner_name'         => 'Alice',
                    'due_date'           => '2026-10-15',
                    'is_customer_facing' => false,
                ],
                [
                    'title'              => 'Review agreement',
                    'owner_name'         => null,
                    'due_date'           => null,
                    'is_customer_facing' => true,
                ],
            ],
        ];

        $result = $this->validator->validate('meeting_followup', $validData);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_sanitizes_action_items_converting_placeholders_to_null(): void
    {
        $rawItems = [
            [
                'title'              => 'Task 1',
                'owner_name'         => 'TBD',
                'due_date'           => 'null',
                'is_customer_facing' => 1,
            ],
            [
                'title'              => 'Task 2',
                'owner_name'         => 'Unassigned',
                'due_date'           => '2026-11-01',
                'is_customer_facing' => false,
            ],
            [
                'title'              => 'Task 3',
                'owner_name'         => 'Sarah Jenkins',
                'due_date'           => '2026-10-20',
                'is_customer_facing' => true,
            ],
        ];

        $sanitized = $this->validator->sanitizeActionItems($rawItems);

        $this->assertCount(3, $sanitized);
        $this->assertNull($sanitized[0]['owner_name']);
        $this->assertNull($sanitized[0]['due_date']);
        $this->assertTrue($sanitized[0]['is_customer_facing']);

        $this->assertNull($sanitized[1]['owner_name']);
        $this->assertEquals('2026-11-01', $sanitized[1]['due_date']);
        $this->assertFalse($sanitized[1]['is_customer_facing']);

        $this->assertEquals('Sarah Jenkins', $sanitized[2]['owner_name']);
        $this->assertEquals('2026-10-20', $sanitized[2]['due_date']);
        $this->assertTrue($sanitized[2]['is_customer_facing']);
    }
}
