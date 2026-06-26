<?php

namespace App\Services\Intelligence;

use App\Models\Finding;
use App\Models\Recommendation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIAnalyst
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private ?string $apiKey;
    private string  $model;

    public function __construct()
    {
        $this->apiKey = config('intelligence.ai.gemini_api_key') ?: env('GEMINI_API_KEY');
        $this->model  = config('intelligence.ai.model', 'gemini-2.5-flash');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Analyse a Finding and create a Recommendation record.
     * Silently skips if no API key is configured.
     */
    public function analyse(Finding $finding): ?Recommendation
    {
        if (! $this->apiKey) {
            Log::info('AIAnalyst: skipping — GEMINI_API_KEY not configured', [
                'finding_id' => $finding->id,
            ]);
            return null;
        }

        // Don't generate duplicate recommendations
        if ($finding->recommendations()->exists()) {
            return null;
        }

        try {
            $prompt   = $this->buildPrompt($finding);
            $response = $this->callGemini($prompt);

            if (! $response) {
                return null;
            }

            return Recommendation::create([
                'finding_id'           => $finding->id,
                'recommendation_text'  => $response['actions']  ?? '',
                'ai_summary'           => $response['summary']   ?? '',
                'confidence_reasoning' => $response['reasoning'] ?? '',
                'model_used'           => $this->model,
            ]);

        } catch (\Exception $e) {
            Log::error('AIAnalyst: Gemini API error', [
                'finding_id' => $finding->id,
                'message'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Prompt construction
    // ─────────────────────────────────────────────────────────────────────────

    private function buildPrompt(Finding $finding): string
    {
        $client       = $finding->client;
        $meta         = $finding->metadata_json ?? [];
        $category     = $finding->finding_category?->label() ?? 'Unknown';
        $severity     = $finding->severity?->value ?? 'medium';
        $industry     = $client->industry ?? 'commercial foodservice/hospitality';
        $platform     = $client->platform_type ?? 'unknown';
        $detectedAt   = $finding->detected_at?->toDateString() ?? 'unknown';
        $metaJson     = json_encode($meta, JSON_PRETTY_PRINT);
        $clientName   = $client->name;
        $businessContext = $client->business_context ?? 'No additional context provided';
        $title        = $finding->title;
        $description  = $finding->description;

        return <<<PROMPT
You are a senior ecommerce performance analyst at a digital agency specialising in commercial foodservice and hospitality.
Your role is to explain metric changes and recommend concrete actions for account managers to investigate and resolve.

CRITICAL GUARDRAILS — you MUST follow these:
- You have access ONLY to aggregated metric data. You have NOT watched session recordings.
- When referencing behavioral data, use this exact phrase: "Clarity behavioral signals indicate"
- NEVER say "I reviewed session recordings", "I watched user sessions", or similar phrases.
- NEVER fabricate metrics or invent data not provided.
- Keep recommendations specific, actionable, and prioritised.

CLIENT CONTEXT:
- Client: {$clientName}
- Industry: {$industry}
- Platform: {$platform}
- Business Context: {$businessContext}

FINDING DETAILS:
- Title: {$title}
- Category: {$category}
- Severity: {$severity}
- Detected: {$detectedAt}
- Description: {$description}

SUPPORTING DATA:
{$metaJson}

Respond in this exact JSON format (no markdown, no code blocks, just raw JSON):
{
  "summary": "2-3 sentence executive summary of what changed and why it matters commercially",
  "causes": ["likely cause 1", "likely cause 2", "likely cause 3"],
  "actions": "Numbered list of 3-5 specific, prioritised actions for the account team to investigate and resolve this finding",
  "reasoning": "1-2 sentences explaining your confidence level and what additional data would increase certainty"
}
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Gemini API call
    // ─────────────────────────────────────────────────────────────────────────

    private function callGemini(string $prompt): ?array
    {
        $url = self::API_BASE . '/models/' . $this->model . ':generateContent?key=' . $this->apiKey;

        $response = Http::timeout(45)
            ->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature'     => 0.3,
                    'maxOutputTokens' => 4096,
                    'thinkingConfig'  => [
                        'thinkingBudget' => 0,  // Disable thinking — frees all tokens for output
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('AIAnalyst: Gemini non-200 response', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 800),
            ]);
            return null;
        }

        $text = $response->json('candidates.0.content.parts.0.text') ?? '';

        if (empty($text)) {
            Log::warning('AIAnalyst: empty text from Gemini', [
                'body' => substr($response->body(), 0, 800),
            ]);
            return null;
        }

        // Strip markdown code fences if present
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/i', '', $clean);
        $clean = trim($clean);

        // Find the JSON object (handle any leading/trailing text)
        if (! str_starts_with($clean, '{')) {
            preg_match('/\{.*\}/s', $clean, $matches);
            $clean = $matches[0] ?? $clean;
        }

        // Gemini sometimes returns literal newlines/tabs inside JSON string values.
        // Replace control characters (except \n \r \t which are valid in non-string context)
        // by encoding them properly inside JSON strings.
        $clean = $this->sanitiseJsonString($clean);

        $parsed = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('AIAnalyst: could not parse Gemini JSON response', [
                'json_error' => json_last_error_msg(),
                'raw'        => substr($clean, 0, 1000),
            ]);
            return null;
        }

        return $parsed;
    }

    /**
     * Clean control characters from a raw JSON string that Gemini may emit.
     * Replaces bare newlines/tabs inside string values with their escaped equivalents.
     */
    private function sanitiseJsonString(string $raw): string
    {
        // Remove NULL bytes and other problematic control chars (keep \t \n \r)
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);

        // Replace literal newlines inside JSON strings with \n
        // We toggle "inside string" by tracking unescaped quotes
        $result    = '';
        $inString  = false;
        $i         = 0;
        $len       = strlen($raw);

        while ($i < $len) {
            $ch = $raw[$i];

            if ($ch === '\\' && $inString) {
                // Skip escape sequence
                $result .= $ch . ($raw[$i + 1] ?? '');
                $i += 2;
                continue;
            }

            if ($ch === '"') {
                $inString = ! $inString;
            }

            if ($inString && $ch === "\n") {
                $result .= '\n';
                $i++;
                continue;
            }

            if ($inString && $ch === "\r") {
                $i++;
                continue;
            }

            if ($inString && $ch === "\t") {
                $result .= '\t';
                $i++;
                continue;
            }

            $result .= $ch;
            $i++;
        }

        return $result;
    }
}


