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

        // 1. Gather full context (auto-fetches missing data from connectors)
        $contextGatherer = new DataContextGatherer();
        $context = $contextGatherer->gatherContext($this->finding);

        Log::info('GenerateAIAnalysis: context gathered', [
            'finding_id'      => $this->finding->id,
            'commerce_sources' => array_keys($context['commerce']),
            'behavioral_days' => count($context['behavioral']),
            'performance_sources' => array_keys($context['performance']),
            'email_types'     => array_keys($context['email_marketing']),
            'deployments'     => count($context['deployments']),
            'server_logs'     => count($context['server_logs']['logs'] ?? []),
            'server_errors'   => $context['server_logs']['total_errors'] ?? 0,
            'data_sources'    => $context['data_sources'],
        ]);

        // 2. Run AI analysis with full context and the requested model
        $analyst = new AIAnalyst();
        $recommendation = $analyst->analyseWithContext($this->finding, $context, $this->model);

        if ($recommendation) {
            // 3. Update finding status to Investigating if it was New
            if ($this->finding->status === FindingStatus::New) {
                $this->finding->update(['status' => FindingStatus::Investigating]);
            }

            Log::info('GenerateAIAnalysis: completed', [
                'finding_id'       => $this->finding->id,
                'recommendation_id' => $recommendation->id,
            ]);
        } else {
            Log::warning('GenerateAIAnalysis: AI analysis returned null', [
                'finding_id' => $this->finding->id,
            ]);
        }
    }
}
