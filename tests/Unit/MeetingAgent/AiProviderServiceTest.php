<?php

namespace Tests\Unit\MeetingAgent;

use App\Services\MeetingAgent\AiProviderService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiProviderServiceTest extends TestCase
{
    // ── Provider selection ──────────────────────────────────────────────

    public function test_openrouter_selected_when_ai_provider_is_openrouter_with_key(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => 'test-openrouter-key',
        ]);

        $service = new AiProviderService();

        $this->assertSame('openrouter', $service->getProviderName());
    }

    public function test_openai_selected_when_ai_provider_is_openai_with_key(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openai',
            'meeting_agent.ai.openrouter_key' => null,
            'meeting_agent.ai.openai_key'     => 'test-openai-key',
        ]);

        $service = new AiProviderService();

        $this->assertSame('openai', $service->getProviderName());
    }

    public function test_gemini_selected_as_fallback_when_only_gemini_key_set(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'gemini',
            'meeting_agent.ai.openrouter_key' => null,
            'meeting_agent.ai.openai_key'     => null,
            'meeting_agent.ai.gemini_key'     => 'test-gemini-key',
        ]);

        $service = new AiProviderService();

        $this->assertSame('gemini', $service->getProviderName());
    }

    public function test_gemini_used_as_fallback_even_when_provider_is_openrouter_without_key(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => null,
            'meeting_agent.ai.openai_key'     => null,
            'meeting_agent.ai.gemini_key'     => 'test-gemini-key',
        ]);

        $service = new AiProviderService();

        $this->assertSame('gemini', $service->getProviderName());
    }

    public function test_throws_runtime_exception_when_no_keys_configured(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => null,
            'meeting_agent.ai.openai_key'     => null,
            'meeting_agent.ai.gemini_key'     => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No AI provider configured');

        new AiProviderService();
    }

    // ── completeJson() ─────────────────────────────────────────────────

    public function test_complete_json_parses_valid_json_response(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => 'test-key',
        ]);

        $expectedJson = [
            'internal_summary'       => 'Project is on track.',
            'customer_email_subject' => 'Status Update',
            'customer_email_body'    => '<p>Hello</p>',
            'recommended_agenda'     => '1. Review progress',
        ];

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode($expectedJson),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new AiProviderService();
        $result = $service->completeJson('System prompt', 'User prompt');

        $this->assertIsArray($result);
        $this->assertSame('Project is on track.', $result['internal_summary']);
        $this->assertSame('Status Update', $result['customer_email_subject']);
    }

    public function test_complete_json_retries_with_repair_prompt_on_invalid_json(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => 'test-key',
        ]);

        $validJson = ['internal_summary' => 'Fixed response'];

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push([
                    'choices' => [
                        ['message' => ['content' => 'This is not valid JSON {broken']],
                    ],
                ], 200)
                ->push([
                    'choices' => [
                        ['message' => ['content' => json_encode($validJson)]],
                    ],
                ], 200),
        ]);

        $service = new AiProviderService();
        $result = $service->completeJson('System prompt', 'User prompt');

        $this->assertIsArray($result);
        $this->assertSame('Fixed response', $result['internal_summary']);

        // Verify two requests were made (original + retry)
        Http::assertSentCount(2);
    }

    public function test_complete_json_throws_after_retry_fails(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => 'test-key',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push([
                    'choices' => [
                        ['message' => ['content' => 'not json']],
                    ],
                ], 200)
                ->push([
                    'choices' => [
                        ['message' => ['content' => 'still not json']],
                    ],
                ], 200),
        ]);

        $service = new AiProviderService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI provider returned invalid JSON after retry');

        $service->completeJson('System prompt', 'User prompt');
    }

    // ── complete() with OpenAI provider ─────────────────────────────────

    public function test_complete_calls_openai_api_when_provider_is_openai(): void
    {
        config([
            'meeting_agent.ai.provider'   => 'openai',
            'meeting_agent.ai.openai_key' => 'test-openai-key',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'OpenAI response']],
                ],
            ], 200),
        ]);

        $service = new AiProviderService();
        $result = $service->complete('System prompt', 'User prompt');

        $this->assertSame('OpenAI response', $result);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    // ── complete() with Gemini provider ────────────────────────────────

    public function test_complete_calls_gemini_api_when_provider_is_gemini(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'gemini',
            'meeting_agent.ai.openrouter_key' => null,
            'meeting_agent.ai.openai_key'     => null,
            'meeting_agent.ai.gemini_key'     => 'test-gemini-key',
            'meeting_agent.ai.gemini_model'   => 'gemini-2.5-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Gemini response']]]],
                ],
            ], 200),
        ]);

        $service = new AiProviderService();
        $result = $service->complete('System prompt', 'User prompt');

        $this->assertSame('Gemini response', $result);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    // ── API error handling ─────────────────────────────────────────────

    public function test_complete_throws_on_api_error(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => 'test-key',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response('Server Error', 500),
        ]);

        $service = new AiProviderService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter API request failed with status 500');

        $service->complete('System', 'User');
    }

    public function test_model_can_be_dynamically_overridden(): void
    {
        config([
            'meeting_agent.ai.provider'       => 'openrouter',
            'meeting_agent.ai.openrouter_key' => 'test-key',
            'meeting_agent.ai.openrouter_model' => 'original-model',
        ]);

        $service = new AiProviderService();
        $this->assertSame('original-model', $service->getModelName());

        $service->setModel('new-dynamic-model');
        $this->assertSame('new-dynamic-model', $service->getModelName());
    }
}
