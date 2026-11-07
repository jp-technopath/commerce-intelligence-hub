<?php

namespace App\Jobs\Intelligence;

use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Services\Intelligence\AIAnalyst;
use App\Services\Intelligence\DataContextGatherer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GenerateAIAnalysis
 *
 * Queued job that:
 * 1. Gathers full data context from all integration sources (GA4, Adobe Commerce, Clarity, etc.)
 * 2. Fills any data gaps by fetching from connectors on-demand
 * 3. Sends everything to AI for comprehensive analysis
 * 4. Creates a Recommendation record with cross-referenced findings
 */
class GenerateAIAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;   // Allow up to 2 minutes for data fetching + AI call
    public int $tries   = 1;     // Don't retry — AI calls are expensive

    public function __construct(
        public Finding $finding,
        public ?string $model = null,
    ) {}

    public function handle(): void
    {
        Log::info('GenerateAIAnalysis: starting', [
            'finding_id' => $this->finding->id,
            'type'       => $this->finding->finding_type,
            'client'     => $this->finding->client?->name,
            'model'      => $this->model,
        ]);

        // Ensure pending status is marked in finding metadata
        $meta = $this->finding->fresh()?->metadata_json ?? $this->finding->metadata_json ?? [];
        $meta['ai_analysis_status'] = 'pending';
        $meta['ai_analysis_started_at'] = $meta['ai_analysis_started_at'] ?? now()->toIso8601String();
        unset($meta['ai_analysis_error']);
        $this->finding->update(['metadata_json' => $meta]);

        // 1. Gather full context (auto-fetches missing data from connectors)
        $contextGatherer = new DataContextGatherer();
        $context = $contextGatherer->gatherContext($this->finding);

        Log::info('GenerateAIAnalysis: context gathered', [
            'finding_id'          => $this->finding->id,
            'commerce_sources'    => array_keys($context['commerce']),
            'behavioral_days'     => count($context['behavioral']),
            'performance_sources' => array_keys($context['performance']),
            'email_types'         => array_keys($context['email_marketing']),
            'deployments'         => count($context['deployments']),
            'server_logs'         => count($context['server_logs']['logs'] ?? []),
            'server_errors'       => $context['server_logs']['total_errors'] ?? 0,
            'data_sources'        => $context['data_sources'],
        ]);

        // 2. Run AI analysis with full context and the requested model
        $analyst = new AIAnalyst();
        $recommendation = $analyst->analyseWithContext($this->finding, $context, $this->model);

        if ($recommendation) {
            $freshMeta = $this->finding->fresh()?->metadata_json ?? [];
            unset($freshMeta['ai_analysis_status'], $freshMeta['ai_analysis_started_at'], $freshMeta['ai_analysis_error']);

            $updates = ['metadata_json' => $freshMeta];
            // 3. Update finding status to Investigating if it was New
            if ($this->finding->status === FindingStatus::New) {
                $updates['status'] = FindingStatus::Investigating;
            }

            $this->finding->update($updates);

            Log::info('GenerateAIAnalysis: completed', [
                'finding_id'        => $this->finding->id,
                'recommendation_id' => $recommendation->id,
            ]);
        } else {
            $freshMeta = $this->finding->fresh()?->metadata_json ?? [];
            $freshMeta['ai_analysis_status'] = 'failed';
            $freshMeta['ai_analysis_error'] = 'AI analysis did not return any recommendations. Please check AI provider configuration or retry.';
            $this->finding->update(['metadata_json' => $freshMeta]);

            Log::warning('GenerateAIAnalysis: AI analysis returned null', [
                'finding_id' => $this->finding->id,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateAIAnalysis: job failed', [
            'finding_id' => $this->finding->id,
            'error'      => $exception->getMessage(),
        ]);

        $meta = $this->finding->fresh()?->metadata_json ?? [];
        $meta['ai_analysis_status'] = 'failed';
        $meta['ai_analysis_error']  = $exception->getMessage();
        $this->finding->update(['metadata_json' => $meta]);
    }
}
