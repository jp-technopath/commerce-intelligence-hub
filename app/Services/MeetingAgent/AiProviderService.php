<?php

namespace App\Services\MeetingAgent;

use App\Traits\SanitisesAiJson;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Multi-provider AI completion service for the Meeting Agent module.
 *
 * Supports OpenRouter, OpenAI, and Gemini (Google Generative AI).
 * Provider selection is determined by config('meeting_agent.ai.provider')
 * and the presence of the corresponding API key.
 */
class AiProviderService
{
    use SanitisesAiJson;

    private string $provider;
    private string $model;
    private string $apiKey;
    private int $maxTokens;

    public function __construct()
    {
        $config = config('meeting_agent.ai');
        $this->maxTokens = (int) ($config['max_tokens'] ?? 4096);

        // Provider selection cascade
        $requestedProvider = $config['provider'] ?? null;

        if ($requestedProvider === 'openrouter' && ! empty($config['openrouter_key'])) {
            $this->provider = 'openrouter';
            $this->apiKey = $config['openrouter_key'];
            $this->model = $config['openrouter_model'] ?? 'anthropic/claude-sonnet-4';
        } elseif ($requestedProvider === 'openai' && ! empty($config['openai_key'])) {
            $this->provider = 'openai';
            $this->apiKey = $config['openai_key'];
            $this->model = $config['openai_model'] ?? 'gpt-4o';
        } elseif (! empty($config['gemini_key'])) {
            $this->provider = 'gemini';
            $this->apiKey = $config['gemini_key'];
            $this->model = $config['gemini_model'] ?? 'gemini-2.5-flash';
        } else {
            throw new RuntimeException('No AI provider configured. Set AI_PROVIDER and corresponding API key in your environment.');
        }
    }

    /**
     * Dynamically override the AI model to use for completion.
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Send a completion request and return the raw text response.
     */
    public function complete(string $systemPrompt, string $userPrompt): string
    {
        return match ($this->provider) {
            'openrouter' => $this->callOpenRouter($systemPrompt, $userPrompt),
            'openai'     => $this->callOpenAI($systemPrompt, $userPrompt),
            'gemini'     => $this->callGemini($systemPrompt, $userPrompt),
        };
    }

    /**
     * Send a completion request and parse the response as JSON.
     * Retries once with a repair prompt if the initial response is invalid JSON.
     */
    public function completeJson(string $systemPrompt, string $userPrompt): array
    {
        $raw = $this->complete($systemPrompt, $userPrompt);
        $parsed = $this->tryParseJson($raw);

        if ($parsed !== null) {
            return $parsed;
        }

        // Retry with repair prompt
        Log::warning('AiProviderService: initial JSON parse failed, retrying with repair prompt', [
            'provider' => $this->provider,
        ]);

        $repairPrompt = "Your previous response was not valid JSON. Please fix the following text and return ONLY valid JSON with no markdown, no code fences, no commentary:\n\n" . $raw;

        $retryRaw = $this->complete($systemPrompt, $repairPrompt);
        $retryParsed = $this->tryParseJson($retryRaw);

        if ($retryParsed !== null) {
            return $retryParsed;
        }

        Log::error('AiProviderService: JSON parse failed after retry', [
            'provider' => $this->provider,
            'model'    => $this->model,
        ]);

        throw new RuntimeException('AI provider returned invalid JSON after retry.');
    }

    public function getProviderName(): string
    {
        return $this->provider;
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Provider-specific API calls
    // ─────────────────────────────────────────────────────────────────────

    private function callOpenRouter(string $systemPrompt, string $userPrompt): string
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => $this->maxTokens,
            ]);

        if (! $response->successful()) {
            Log::error('AiProviderService: OpenRouter API error', [
                'status' => $response->status(),
            ]);
            throw new RuntimeException('OpenRouter API request failed with status ' . $response->status());
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    private function callOpenAI(string $systemPrompt, string $userPrompt): string
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => $this->maxTokens,
            ]);

        if (! $response->successful()) {
            Log::error('AiProviderService: OpenAI API error', [
                'status' => $response->status(),
            ]);
            throw new RuntimeException('OpenAI API request failed with status ' . $response->status());
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    private function callGemini(string $systemPrompt, string $userPrompt): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . $this->apiKey;

        $response = Http::timeout(60)
            ->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $systemPrompt . "\n\n" . $userPrompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature'     => 0.3,
                    'maxOutputTokens' => $this->maxTokens,
                    'thinkingConfig'  => [
                        'thinkingBudget' => 0,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('AiProviderService: Gemini API error', [
                'status' => $response->status(),
            ]);
            throw new RuntimeException('Gemini API request failed with status ' . $response->status());
        }

        return $response->json('candidates.0.content.parts.0.text') ?? '';
    }

    // ─────────────────────────────────────────────────────────────────────
    // JSON parsing helpers
    // ─────────────────────────────────────────────────────────────────────

    private function tryParseJson(string $raw): ?array
    {
        $clean = trim($raw);

        // Strip markdown code fences if present
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/i', '', $clean);
        $clean = trim($clean);

        // Find the JSON object if there's surrounding text
        if (! str_starts_with($clean, '{') && ! str_starts_with($clean, '[')) {
            if (preg_match('/(\{.*\}|\[.*\])/s', $clean, $matches)) {
                $clean = $matches[1];
            }
        }

        $clean = $this->sanitiseJsonString($clean);

        $parsed = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $parsed;
    }
}
