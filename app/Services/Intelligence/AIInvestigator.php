<?php

namespace App\Services\Intelligence;

use App\Models\Finding;
use App\Services\Intelligence\DataContextGatherer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AIInvestigator
 *
 * Powers the "Dig Deeper" feature. Gathers rich context from all data sources
 * (GA4, Adobe Commerce, Clarity, deployments, past knowledge) and sends it
 * to Gemini with the user's specific investigation instructions.
 *
 * Returns a structured investigation result that is stored as an
 * InvestigationNote on the finding.
 */
class AIInvestigator
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $config       = config('intelligence.ai');
        $this->apiKey = $config['gemini_api_key'] ?? '';
        $this->model  = $config['model'] ?? 'gemini-2.5-flash';
    }

    /**
     * Run a deep investigation on a finding with user-provided instructions.
     *
     * @param  Finding  $finding  The finding to investigate
     * @param  string   $instructions  User's investigation prompt
     * @return array{analysis: string, data_points: array, correlations: array, next_steps: array}|null
     */
    public function investigate(Finding $finding, string $instructions): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('AIInvestigator: no Gemini API key configured');
            return null;
        }

        $contextGatherer = new DataContextGatherer();
        $context = $contextGatherer->gatherContext($finding);
        $prompt  = $this->buildPrompt($finding, $instructions, $context);

        try {
            $response = Http::timeout(60)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}",
                    [
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'maxOutputTokens' => 8192,
                            'temperature'     => 0.3,
                        ],
                    ]
                );

            if (! $response->successful()) {
                Log::error('AIInvestigator: API error', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $text = $response->json('candidates.0.content.parts.0.text', '');

            if (empty($text)) {
                return null;
            }

            return $this->parseResponse($text, $context);

        } catch (\Exception $e) {
            Log::error('AIInvestigator: exception', [
                'finding_id' => $finding->id,
                'message'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Prompt building
    // ─────────────────────────────────────────────────────────────────────────

    private function buildPrompt(Finding $finding, string $instructions, array $context): string
    {
        $severity = is_object($finding->severity) ? $finding->severity->value : $finding->severity;
        $category = is_object($finding->finding_category) ? $finding->finding_category->value : $finding->finding_category;
        $status   = is_object($finding->status) ? $finding->status->value : $finding->status;

        $prompt = <<<PROMPT
You are a senior commerce intelligence analyst investigating a data anomaly for an ecommerce business.

## FINDING UNDER INVESTIGATION
- **Title**: {$finding->title}
- **Category**: {$category}
- **Severity**: {$severity}
- **Status**: {$status}
- **Detected**: {$finding->detected_at?->format('Y-m-d H:i')}
- **Confidence**: {$finding->confidence_score}
- **Est. Revenue Impact**: \${$finding->estimated_revenue_impact}

### Description
{$finding->description}

### Finding Metadata
PROMPT;

        $prompt .= "\n```json\n" . json_encode($finding->metadata_json ?? [], JSON_PRETTY_PRINT) . "\n```\n";

        // Commerce data
        if (! empty($context['commerce'])) {
            $prompt .= "\n## COMMERCE DATA (±14 days around detection)\n";
            $prompt .= "Each row includes `source_breakdown` with per-channel data: `{channel: {sessions, new_users, revenue, transactions}}`. Channels include: organic, paid, direct, referral, social, email. Use these breakdowns to answer channel-specific questions (e.g., which channel lost the most new users, which channel's revenue dropped the most). Also may include `device_breakdown` (desktop, mobile, tablet).\n";
            foreach ($context['commerce'] as $source => $rows) {
                $prompt .= "\n### Source: {$source}\n";
                $prompt .= "```json\n" . json_encode($rows, JSON_PRETTY_PRINT) . "\n```\n";
            }
        }

        // Behavioral data
        if (! empty($context['behavioral'])) {
            $prompt .= "\n## BEHAVIORAL DATA (Microsoft Clarity)\n";
            $prompt .= "```json\n" . json_encode($context['behavioral'], JSON_PRETTY_PRINT) . "\n```\n";
        }

        // Performance data (New Relic)
        if (! empty($context['performance'])) {
            $prompt .= "\n## PERFORMANCE DATA (New Relic)\n";
            $prompt .= "Page load times and server response times in milliseconds. `metadata` contains `throughput` (requests/min), `error_count`, `error_rate`, and `apdex` score (0-1, where 1 is perfect). Look for performance degradation that correlates with traffic or revenue drops.\n";
            foreach ($context['performance'] as $source => $rows) {
                $prompt .= "\n### Source: {$source}\n";
                $prompt .= "```json\n" . json_encode($rows, JSON_PRETTY_PRINT) . "\n```\n";
            }
        }

        // Email marketing data (Klaviyo)
        if (! empty($context['email_marketing'])) {
            $prompt .= "\n## EMAIL MARKETING DATA (Klaviyo)\n";
            $prompt .= "Campaigns and flows with open/click/conversion/revenue metrics. `type` is 'campaign' or 'flow'. Flows include welcome series, abandoned cart, post-purchase, etc. Check if email-driven revenue or engagement changed around the detection date.\n";
            foreach ($context['email_marketing'] as $type => $rows) {
                $prompt .= "\n### Type: {$type}\n";
                $prompt .= "```json\n" . json_encode($rows, JSON_PRETTY_PRINT) . "\n```\n";
            }
        }

        // Deployments
        if (! empty($context['deployments'])) {
            $prompt .= "\n## DEPLOYMENTS NEAR DETECTION DATE\n";
            $prompt .= "```json\n" . json_encode($context['deployments'], JSON_PRETTY_PRINT) . "\n```\n";
        } else {
            $prompt .= "\n## DEPLOYMENTS\nNo deployments recorded near the detection date.\n";
        }

        // Existing recommendations
        if (! empty($context['recommendations'])) {
            $prompt .= "\n## EXISTING AI RECOMMENDATIONS\n";
            foreach ($context['recommendations'] as $i => $rec) {
                $n = $i + 1;
                $implemented = $rec['implemented'] ? '✅ Implemented' : '⏳ Not implemented';
                $prompt .= "\n### Recommendation {$n} ({$implemented})\n";
                $prompt .= "**Summary**: {$rec['summary']}\n";
                $prompt .= "**Actions**: {$rec['actions']}\n";
                if ($rec['impact']) {
                    $prompt .= "**Actual Impact**: \${$rec['impact']}\n";
                }
            }
        }

        // Investigation notes
        if (! empty($context['notes'])) {
            $prompt .= "\n## INVESTIGATION NOTES FROM TEAM\n";
            foreach ($context['notes'] as $note) {
                $prompt .= "\n**{$note['author']}** ({$note['date']}):\n";
                if ($note['root_cause']) $prompt .= "- Root cause: {$note['root_cause']}\n";
                if ($note['fix'])        $prompt .= "- Fix: {$note['fix']}\n";
                if ($note['outcome'])    $prompt .= "- Outcome: {$note['outcome']}\n";
            }
        }

        // Similar past patterns
        if (! empty($context['similar_past'])) {
            $prompt .= "\n## SIMILAR PAST PATTERNS (Knowledge Base)\n";
            foreach ($context['similar_past'] as $past) {
                $prompt .= "\n- **Pattern**: {$past['pattern']}\n";
                if ($past['root_cause']) $prompt .= "  - Root Cause: {$past['root_cause']}\n";
                if ($past['resolution']) $prompt .= "  - Resolution: {$past['resolution']}\n";
                if ($past['outcome'])    $prompt .= "  - Outcome: {$past['outcome']}\n";
            }
        }

        // User's investigation instructions
        $prompt .= <<<INSTRUCTIONS

## USER'S INVESTIGATION REQUEST
{$instructions}

## YOUR TASK
Based on ALL the data above, provide a thorough investigation analysis. You have real data from GA4, Adobe Commerce, Clarity, and deployments — USE IT to answer the user's question directly.

IMPORTANT RULES:
1. The `source_breakdown` contains per-channel metrics. Each channel (organic, paid, direct, referral, etc.) has `sessions`, `new_users`, `revenue`, and `transactions`. To answer "which channel dropped the most", sum each channel's metric over the current period dates vs the previous period dates and calculate the change.
2. If `source_breakdown` values are plain numbers (not objects), treat them as session counts per channel.
3. If `device_breakdown` data is available, compare device types across periods.
4. Always perform the calculations — do NOT say "it is not possible to answer" when the data to answer is present. Show your work with specific numbers.
5. Reference specific numbers, dates, and percentages from the data. Build comparison tables when useful.
6. If specific data is genuinely missing (e.g., no source_breakdown in any row), then suggest what additional data collection would help.

Structure your response as:

### Analysis
A detailed analysis addressing the user's investigation request. Reference specific data points, dates, and metrics. Identify correlations between commerce performance and behavioral signals. If deployments occurred near the anomaly, assess their potential impact.

### Key Data Points
List the most important metrics and values that support your analysis.

### Correlations Found
Identify any cross-dataset correlations (e.g., "Revenue dropped 49% on the same day Clarity detected a 3x increase in script errors").

### Recommended Next Steps
Specific, actionable recommendations based on your investigation. Be concrete — mention specific pages, metrics, or actions to take.
INSTRUCTIONS;

        return $prompt;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Response parsing
    // ─────────────────────────────────────────────────────────────────────────

    private function parseResponse(string $text, array $context): array
    {
        // Extract sections from the markdown response
        $analysis     = $this->extractSection($text, 'Analysis', 'Key Data Points') ?: $text;
        $dataPoints   = $this->extractSection($text, 'Key Data Points', 'Correlations Found')
                      ?: $this->extractSection($text, 'Key Data Points', 'Recommended Next Steps');
        $correlations = $this->extractSection($text, 'Correlations Found', 'Recommended Next Steps')
                      ?: $this->extractSection($text, 'Correlations', 'Recommended Next Steps')
                      ?: $this->extractSection($text, 'Correlations', null);
        $nextSteps    = $this->extractSection($text, 'Recommended Next Steps', null)
                      ?: $this->extractSection($text, 'Next Steps', null)
                      ?: $this->extractSection($text, 'Recommendations', null);

        // Fallback: if we only got the raw text, try to parse list items from the whole thing
        $dpItems   = $this->parseListItems($dataPoints);
        $corrItems = $this->parseListItems($correlations);
        $nsItems   = $this->parseListItems($nextSteps);

        return [
            'analysis'     => trim($analysis),
            'data_points'  => $dpItems,
            'correlations' => $corrItems,
            'next_steps'   => $nsItems,
            'full_text'    => $text,
            'context_summary' => [
                'commerce_days'      => count($context['commerce']['ga4'] ?? $context['commerce']['adobe_commerce'] ?? []),
                'behavioral_days'    => count($context['behavioral']),
                'deployments_found'  => count($context['deployments']),
                'past_patterns'      => count($context['similar_past']),
                'existing_notes'     => count($context['notes']),
            ],
        ];
    }

    private function extractSection(string $text, string $startHeading, ?string $endHeading): string
    {
        $start = preg_quote($startHeading, '~');

        if ($endHeading) {
            $end     = preg_quote($endHeading, '~');
            $pattern = '~###\s*' . $start . '\s*\n(.*?)(?=###\s*' . $end . ')~s';
        } else {
            $pattern = '~###\s*' . $start . '\s*\n(.*)$~s';
        }

        if (preg_match($pattern, $text, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function parseListItems(string $text): array
    {
        if (empty($text)) return [];

        $items = [];
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[-*•]\s+(.+)/', $line, $m)) {
                $items[] = trim($m[1]);
            } elseif (preg_match('/^\d+\.\s+(.+)/', $line, $m)) {
                $items[] = trim($m[1]);
            }
        }
        return $items;
    }
}
