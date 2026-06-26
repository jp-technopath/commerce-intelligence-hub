<?php

namespace App\Observers;

use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Models\IntelligenceMemory;
use Illuminate\Support\Facades\Log;

/**
 * Observes Finding model events.
 *
 * When a finding is resolved AND has investigation notes with a root cause
 * or fix documented, automatically creates an IntelligenceMemory record
 * to build the institutional knowledge base.
 */
class FindingObserver
{
    public function updated(Finding $finding): void
    {
        // Only fire when status changes to Resolved
        if (! $finding->wasChanged('status')) {
            return;
        }

        if ($finding->status !== FindingStatus::Resolved) {
            return;
        }

        $this->captureToKnowledgeBase($finding);
    }

    private function captureToKnowledgeBase(Finding $finding): void
    {
        // Get the latest investigation note that has substance
        $note = $finding->investigationNotes()
            ->where(function ($q) {
                $q->whereNotNull('root_cause')
                    ->orWhereNotNull('fix_implemented');
            })
            ->latest()
            ->first();

        // Don't create empty knowledge base entries
        if (! $note && ! $finding->recommendations()->exists()) {
            return;
        }

        // Build the pattern description from the finding
        $pattern = $finding->title;
        if ($finding->description) {
            $pattern .= "\n\n" . $finding->description;
        }

        // Build resolution from investigation note or AI recommendation
        $resolution = $note?->fix_implemented;
        if (! $resolution) {
            $rec = $finding->recommendations()->latest()->first();
            $resolution = $rec?->recommendation_text;
        }

        // Avoid duplicates — check if we already have an entry for this finding
        $exists = IntelligenceMemory::where('client_id', $finding->client_id)
            ->where('finding_type', $finding->finding_type)
            ->where('pattern_description', 'LIKE', '%' . substr($finding->title, 0, 50) . '%')
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($exists) {
            return;
        }

        $category = $finding->finding_category;

        IntelligenceMemory::create([
            'client_id'           => $finding->client_id,
            'finding_type'        => $finding->finding_type,
            'finding_category'    => is_object($category) ? $category->value : $category,
            'pattern_description' => $pattern,
            'root_cause'          => $note?->root_cause,
            'resolution'          => $resolution,
            'outcome'             => $note?->outcome,
            'metadata_json'       => [
                'finding_id'       => $finding->id,
                'severity'         => is_object($finding->severity) ? $finding->severity->value : $finding->severity,
                'confidence_score' => $finding->confidence_score,
                'revenue_impact'   => $finding->estimated_revenue_impact,
                'detected_at'      => $finding->detected_at?->toISOString(),
                'resolved_at'      => now()->toISOString(),
            ],
        ]);

        Log::info('FindingObserver: captured to knowledge base', [
            'finding_id'   => $finding->id,
            'finding_type' => $finding->finding_type,
            'has_root_cause' => ! empty($note?->root_cause),
            'has_resolution' => ! empty($resolution),
        ]);
    }
}
