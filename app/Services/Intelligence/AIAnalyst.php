<?php

namespace App\Services\Intelligence;

use App\Models\Finding;
use App\Models\Recommendation;
use App\Services\Intelligence\DataContextGatherer;
use App\Services\MeetingAgent\AiProviderService;
use Illuminate\Support\Facades\Log;

class AIAnalyst
{
    private AiProviderService $aiProvider;

    public function __construct(?AiProviderService $aiProvider = null)
    {
        $this->aiProvider = $aiProvider ?: app(AiProviderService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Analyse a Finding and create a Recommendation record.
     * Gathers live data context from all integrations before analysis.
     */
    public function analyse(Finding $finding, ?string $model = null): ?Recommendation
    {
        try {
            // Don't generate duplicate recommendations
            if ($finding->recommendations()->exists()) {
                return null;
            }

            $gatherer = new DataContextGatherer();
            $context  = $gatherer->gatherContext($finding);

            return $this->analyseWithContext($finding, $context, $model);
        } catch (\Exception $e) {
            Log::error('AIAnalyst: Failed to generate analysis', [
                'finding_id' => $finding->id,
                'message'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Analyse a Finding with a pre-gathered data context array.
     * Builds an enhanced prompt with ALL data sources and calls AI provider.
     */
    public function analyseWithContext(Finding $finding, array $context, ?string $model = null): ?Recommendation
    {
        try {
            if ($model) {
                $this->aiProvider->setModel($model);
            }

            $prompt = $this->buildEnhancedPrompt($finding, $context);
            $systemPrompt = "You are a senior ecommerce data analyst performing a thorough INVESTIGATION of a detected anomaly.";

            Log::info('AIAnalyst: Calling AI provider for analysis', [
                'finding_id' => $finding->id,
                'model'      => $this->aiProvider->getModelName(),
                'provider'   => $this->aiProvider->getProviderName(),
            ]);

            $response = $this->aiProvider->completeJson($systemPrompt, $prompt);

            if (! $response) {
                return null;
            }

            return Recommendation::create([
                'finding_id'           => $finding->id,
                'ai_summary'           => $response['investigation_report'] ?? $response['summary'] ?? '',
                'recommendation_text'  => $response['data_evidence'] ?? $response['actions'] ?? '',
                'confidence_reasoning' => $response['conclusion'] ?? $response['reasoning'] ?? '',
                'model_used'           => $this->aiProvider->getModelName(),
            ]);

        } catch (\Exception $e) {
            Log::error('AIAnalyst: API error', [
                'finding_id' => $finding->id,
                'message'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Prompt construction
    // ─────────────────────────────────────────────────────────────────────────

    private function buildEnhancedPrompt(Finding $finding, array $context): string
    {
        $client          = $finding->client;
        $meta            = $finding->metadata_json ?? [];
        $category        = $finding->finding_category?->label() ?? 'Unknown';
        $severity        = $finding->severity?->value ?? 'medium';
        $industry        = $client->industry ?? 'commercial foodservice/hospitality';
        $platform        = $client->platform_type ?? 'unknown';
        $detectedAt      = $finding->detected_at?->toDateString() ?? 'unknown';
        $metaJson        = json_encode($meta, JSON_PRETTY_PRINT);
        $clientName      = $client->name;
        $businessContext = $client->business_context ?? 'No additional context provided';
        $title           = $finding->title;
        $description     = $finding->description;

        $commerceJson       = json_encode($context['commerce'] ?? [], JSON_PRETTY_PRINT);
        $behavioralJson     = json_encode($context['behavioral'] ?? [], JSON_PRETTY_PRINT);
        $performanceJson    = json_encode($context['performance'] ?? [], JSON_PRETTY_PRINT);
        $emailMarketingJson = json_encode($context['email_marketing'] ?? [], JSON_PRETTY_PRINT);
        $deploymentsJson    = json_encode($context['deployments'] ?? [], JSON_PRETTY_PRINT);
        $dataSources        = json_encode($context['data_sources'] ?? [], JSON_PRETTY_PRINT);
        $serverLogsJson     = json_encode($context['server_logs'] ?? [], JSON_PRETTY_PRINT);

        return <<<PROMPT
You are a senior ecommerce data analyst performing a thorough INVESTIGATION of a detected anomaly.
You are NOT giving recommendations for someone else to investigate — YOU are the investigator.
You have been given real data from multiple platforms. Your job is to ANALYZE this data, find patterns, cross-reference across platforms, and present your findings backed by specific numbers.

CRITICAL RULES:
- You MUST cite specific numbers, dates, and percentages from the data provided.
- You MUST cross-reference data across platforms (GA4 vs Adobe Commerce vs Clarity).
- NEVER say "you should investigate" or "consider checking" — YOU are doing the investigation.
- NEVER fabricate metrics — only reference data actually provided below.
- When referencing behavioral data, say "Clarity data shows" not "I reviewed recordings."
- Present your findings as completed analysis, not suggestions for future work.

FORMATTING RULES — follow these strictly:
- Use human-readable dates: write "June 22" or "June 22 – July 5", NEVER "2026-06-22".
- **Bold** all key metrics, numbers, percentages, and dollar amounts (e.g., **15.6% decrease**, **134 orders**, **31.7% to 26.76%**).
- Structure comparisons, breakdowns, and step-by-day metrics using Markdown Tables (e.g. `| Metric | Prior Period | Anomaly Period | Change |`). Every table must have a header row and separator line.
- Keep tables concise and highly focused. Compare only the overall "Prior Period" vs "Anomaly Period" totals or averages. Do NOT output exhaustive day-by-day rows for every single date in the dataset — instead, summarize daily trends in 2-3 bullet points or highlight only the top 2-3 most critical dates of interest.
- Use unordered list bullet points (`- `) extensively for detailed points, traffic sources, or events instead of dense paragraphs. Keep paragraphs to 1-2 sentences maximum.
- Use blockquotes (`> `) to highlight critical findings or direct correlations.
- Start each section with a one-sentence summary in bold, then provide supporting details using tables, lists, and short sentences.
- Be extremely concise. Avoid repeating the same data points across different sections. Keep the entire response tight and high-density.

CLIENT: {$clientName} | Industry: {$industry} | Platform: {$platform}
Business Context: {$businessContext}

FINDING UNDER INVESTIGATION:
- Title: {$title}
- Category: {$category} | Severity: {$severity}
- Detected: {$detectedAt}
- Description: {$description}

FINDING METADATA:
{$metaJson}

AVAILABLE DATA SOURCES: {$dataSources}

═══════════════════════════════════════════════════════════════════════════════
RAW DATA FROM INTEGRATIONS — Analyze all of this
═══════════════════════════════════════════════════════════════════════════════

── GA4 + ADOBE COMMERCE DATA ──────────────────────────────────────────────────
This contains daily metrics from both GA4 (sessions, users, traffic sources) and Adobe Commerce (orders, revenue, AOV).
Cross-reference these: compare GA4 session trends vs Adobe Commerce order trends day-by-day.
If source_breakdown is available, analyze which traffic channels (organic, paid, direct, email, etc.) changed.
{$commerceJson}

── MICROSOFT CLARITY BEHAVIORAL DATA ──────────────────────────────────────────
Daily behavioral signals: rage clicks, dead clicks, quick backs, script errors, friction scores.
Correlate spikes in friction/errors with the revenue and conversion changes above.
{$behavioralJson}

── NEW RELIC PERFORMANCE DATA ─────────────────────────────────────────────────
Server response times, page load times, TTFB, Core Web Vitals.
Check if performance degradation correlates with the detected anomaly.
{$performanceJson}

── NEW RELIC SERVER LOGS (errors/warnings) ────────────────────────────────────
Application error and warning logs from New Relic. If populated, this is critical evidence:
- Check error_summary for total error/warning counts by severity level.
- Check error_classes for the top error types (e.g., PHP exceptions, JS errors, timeouts).
- Review individual log entries for specific error messages and timestamps.
- Correlate error spikes with the dates where metrics degraded.
- If error counts are high or a new error class appeared around the anomaly date, this is likely a root cause.
{$serverLogsJson}

── KLAVIYO EMAIL MARKETING DATA ───────────────────────────────────────────────
Campaign and flow metrics: opens, clicks, conversions, revenue attribution.
Compare Klaviyo-attributed revenue with Adobe Commerce totals to understand email contribution.
{$emailMarketingJson}

── DEPLOYMENTS ────────────────────────────────────────────────────────────────
Recent code deployments. Check if any deployment timing correlates with metric changes.
{$deploymentsJson}

═══════════════════════════════════════════════════════════════════════════════
YOUR INVESTIGATION OUTPUT
═══════════════════════════════════════════════════════════════════════════════

Respond in this exact JSON format (no markdown fences, just raw JSON):
{
  "investigation_report": "A thorough, highly-structured investigation report. Structure it with clear sections using line breaks and **bold** headers:\\n\\n**What Happened**\\nDescribe the anomaly with exact numbers. You MUST include a concise Markdown Table comparing the key metrics (e.g. Sessions, Orders, Conversion Rate, Revenue) before and after, e.g.:\\n| Metric | Prior Period | Anomaly Period | Change |\\n| --- | --- | --- | --- |\\n\\n**Cross-Platform Evidence**\\nPresent your findings from cross-referencing the data. Compare GA4 sessions vs Adobe Commerce orders day-by-day in a Markdown Table if helpful, and use unordered bullet lists (- ) to break down channel or funnel performance.\\n\\n**Behavioral Analysis**\\nCorrelate Clarity behavioral data. Use a bulleted list to show signals (rage clicks, dead clicks, etc.) and correlate with dates. Highlight friction points or critical warnings using blockquotes (> ).\\n\\n**Root Cause Assessment**\\nState the most likely root cause(s) with supporting evidence in lists. Keep it punchy and clear.",

  "data_evidence": "A structured list of the key data points that support your conclusions. Format as numbered items, each citing a specific metric, date, and source platform:\\n\\n1. **[Platform] Metric**: Value on date — significance\\n2. **GA4 vs Adobe Commerce**: On [date], GA4 showed X sessions but Adobe Commerce recorded only Y orders (Z% conversion), compared to A sessions / B orders (C% conversion) on [prior date]\\n3. **Clarity Correlation**: Friction score increased from X to Y (Z%) on [date], same day revenue dropped correspondingly\\n...",

  "conclusion": "2-3 paragraph conclusion that:\\n1. States the confirmed root cause based on your data analysis\\n2. Quantifies the impact (revenue lost, conversion decline, etc.)\\n3. States what action should be taken based on your findings (not what to investigate — what to FIX based on what you found)"
}
PROMPT;
    }
}
