<?php

namespace Tests\Feature;

use App\Services\PM\Providers\JiraProvider;
use Tests\TestCase;

class JiraAdfParserTest extends TestCase
{
    public function test_parses_basic_text_and_marks(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello '],
                        ['type' => 'text', 'text' => 'World', 'marks' => [['type' => 'strong']]],
                        ['type' => 'text', 'text' => ' and '],
                        ['type' => 'text', 'text' => 'Code', 'marks' => [['type' => 'code']]],
                        ['type' => 'text', 'text' => ' and '],
                        ['type' => 'text', 'text' => 'Strikethrough', 'marks' => [['type' => 'strike']]],
                        ['type' => 'text', 'text' => ' and '],
                        ['type' => 'text', 'text' => 'Link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
                    ],
                ],
            ],
        ];

        $markdown = JiraProvider::parseAdfToMarkdown($adf);

        $this->assertStringContainsString('Hello **World** and `Code` and ~~Strikethrough~~ and [Link](https://example.com)', $markdown);
    }

    public function test_parses_media_single_matching_by_filename(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Here is a screenshot:'],
                    ],
                ],
                [
                    'type' => 'mediaSingle',
                    'content' => [
                        [
                            'type' => 'media',
                            'attrs' => [
                                'id' => 'abc-123-uuid',
                                'type' => 'file',
                                'collection' => '',
                                'alt' => 'screenshot.png',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $attachments = [
            [
                'id' => '79173',
                'filename' => 'screenshot.png',
                'size' => 12345,
                'mime_type' => 'image/png',
            ],
        ];

        $markdown = JiraProvider::parseAdfToMarkdown($adf, $attachments, 'PROJ-101');

        $this->assertStringContainsString('Here is a screenshot', $markdown);
        $this->assertStringContainsString('[![screenshot.png](/pm/attachments/PROJ-101/79173)](/pm/attachments/PROJ-101/79173)', $markdown);
    }

    public function test_parses_media_matching_by_attachment_id(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'mediaSingle',
                    'content' => [
                        [
                            'type' => 'media',
                            'attrs' => [
                                'id' => '79174',
                                'type' => 'file',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $attachments = [
            [
                'id' => '79174',
                'filename' => 'diagram.jpg',
                'size' => 67890,
                'mime_type' => 'image/jpeg',
            ],
        ];

        $markdown = JiraProvider::parseAdfToMarkdown($adf, $attachments, 'PROJ-102');

        $this->assertStringContainsString('[![diagram.jpg](/pm/attachments/PROJ-102/79174)](/pm/attachments/PROJ-102/79174)', $markdown);
    }

    public function test_parses_media_fallback_to_single_image_attachment(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'mediaSingle',
                    'content' => [
                        [
                            'type' => 'media',
                            'attrs' => [
                                'id' => 'unknown-uuid',
                                'type' => 'file',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $attachments = [
            [
                'id' => '88888',
                'filename' => 'only_image.png',
                'size' => 4567,
                'mime_type' => 'image/png',
            ],
        ];

        $markdown = JiraProvider::parseAdfToMarkdown($adf, $attachments, 'PROJ-103');

        $this->assertStringContainsString('[![only_image.png](/pm/attachments/PROJ-103/88888)](/pm/attachments/PROJ-103/88888)', $markdown);
    }

    public function test_parses_inline_card_and_block_card(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'inlineCard',
                            'attrs' => ['url' => 'https://example.com/spec'],
                        ],
                    ],
                ],
                [
                    'type' => 'blockCard',
                    'attrs' => ['url' => 'https://example.com/figma'],
                ],
            ],
        ];

        $markdown = JiraProvider::parseAdfToMarkdown($adf);

        $this->assertStringContainsString('[https://example.com/spec](https://example.com/spec)', $markdown);
        $this->assertStringContainsString('[https://example.com/figma](https://example.com/figma)', $markdown);
    }

    public function test_parses_code_blocks_and_bullet_lists(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'bulletList',
                    'content' => [
                        [
                            'type' => 'listItem',
                            'content' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [['type' => 'text', 'text' => 'Point 1']],
                                ],
                            ],
                        ],
                        [
                            'type' => 'listItem',
                            'content' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [['type' => 'text', 'text' => 'Point 2']],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'codeBlock',
                    'attrs' => ['language' => 'json'],
                    'content' => [
                        ['type' => 'text', 'text' => '{"foo":"bar"}'],
                    ],
                ],
            ],
        ];

        $markdown = JiraProvider::parseAdfToMarkdown($adf);

        $this->assertStringContainsString('• Point 1', $markdown);
        $this->assertStringContainsString('• Point 2', $markdown);
        $this->assertStringContainsString('```', $markdown);
        $this->assertStringContainsString('{"foo":"bar"}', $markdown);
    }
}
