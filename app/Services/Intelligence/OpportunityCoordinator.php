<?php

namespace App\Services\Intelligence;

use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Services\Intelligence\Detectors\AccountContractionDetector;
use App\Services\Intelligence\Detectors\B2bAccountReactivationDetector;
use App\Services\Intelligence\Detectors\LongTermWinbackDetector;
use App\Services\Intelligence\Detectors\ReplenishmentCadenceDetector;
use App\Services\Intelligence\DTOs\OpportunityFindingData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class OpportunityCoordinator
{
    public const EPISODE_DORMANCY_DAYS = 14;

    /**
     * Coordinate and de-conflict raw findings across detectors for a client.
     * Prevents redundant actions, double-counted revenue, and competing recommendations.
     *
     * @param  Collection<OpportunityFindingData>  $rawFindings
     * @return Collection<OpportunityFindingData>
     */
    public function coordinate(Client $client, Collection $rawFindings): Collection
    {
        if ($rawFindings->isEmpty()) {
            return collect();
        }

        // 1. Group findings by account identity hash
        $grouped = $rawFindings->groupBy(fn (OpportunityFindingData $f) => $f->accountIdentityHash ?? 'cohort_general');

        $coordinated = collect();

        foreach ($grouped as $accountHash => $findingsForAccount) {
            if ($accountHash === 'cohort_general' || $findingsForAccount->count() === 1) {
                // No conflict, push directly
                foreach ($findingsForAccount as $item) {
                    $item->fingerprint = $this->generateFingerprint($client->id, $item->detectorKey, $item->accountIdentityHash);
                    $coordinated->push($item);
                }
                continue;
            }

            // Conflict resolution for accounts with multiple detector hits
            $resolved = $this->resolveAccountConflicts($client, $accountHash, $findingsForAccount);
            foreach ($resolved as $item) {
                $item->fingerprint = $this->generateFingerprint($client->id, $item->detectorKey, $item->accountIdentityHash);
                $coordinated->push($item);
            }
        }

        return $coordinated;
    }

    /**
     * Resolve cross-detector conflicts for a single account.
     *
     * @param  Collection<OpportunityFindingData>  $findings
     * @return Collection<OpportunityFindingData>
     */
    protected function resolveAccountConflicts(Client $client, string $accountHash, Collection $findings): Collection
    {
        $hasReactivation = $findings->firstWhere('detectorKey', B2bAccountReactivationDetector::KEY);
        $hasWinback = $findings->firstWhere('detectorKey', LongTermWinbackDetector::KEY);
        $hasReplenishment = $findings->firstWhere('detectorKey', ReplenishmentCadenceDetector::KEY);
        $hasContraction = $findings->firstWhere('detectorKey', AccountContractionDetector::KEY);

        // Case 0: Long-Term Winback takes precedence over contraction for lapsed accounts
        if ($hasWinback && $hasContraction) {
            $hasWinback->description .= " Note: Account experienced an observed quarterly spending drop of -\${$hasContraction->estimatedRevenueImpact} before lapsing.";
            $hasWinback->evidence['prior_contraction_drop'] = $hasContraction->estimatedRevenueImpact;
            return collect([$hasWinback]);
        }

        // Case 1: Account qualifies for both Reactivation and Contraction
        if ($hasReactivation && $hasContraction) {
            $daysSinceLastOrder = $hasReactivation->evidence['days_since_last_order'] ?? 0;
            $medianInterval = $hasReactivation->evidence['median_interval_days'] ?? 30;

            // If account is heavily dormant (> 60 days and > 1.5x median), primary issue is Reactivation
            if ($daysSinceLastOrder >= max(60, $medianInterval * 1.5)) {
                // Enrich Reactivation finding with verified contraction drop context
                $hasReactivation->description .= " Note: Account experienced a verified revenue drop of -\${$hasContraction->estimatedRevenueImpact} prior to becoming dormant.";
                $hasReactivation->evidence['prior_contraction_drop'] = $hasContraction->estimatedRevenueImpact;
                $hasReactivation->evidence['prior_contraction_pct'] = $hasContraction->evidence['contraction_pct'] ?? null;
                $hasReactivation->priorityScore = min(100.0, $hasReactivation->priorityScore + 10.0);

                // Reactivation takes precedence; suppress redundant contraction finding to avoid duplicate outreach
                return collect([$hasReactivation]);
            } else {
                // Account is still relatively recent, primary issue is Contraction
                $hasContraction->description .= " Note: Account is also overdue past its regular order interval ({$daysSinceLastOrder}d vs {$medianInterval}d median).";
                $hasContraction->priorityScore = min(100.0, $hasContraction->priorityScore + 8.0);
                return collect([$hasContraction]);
            }
        }

        // Case 2: Account qualifies for both Reactivation and Replenishment
        if ($hasReactivation && $hasReplenishment) {
            // Dormancy past the replenishment window takes precedence
            return collect([$hasReactivation]);
        }

        // Case 3: Account qualifies for both Replenishment and Contraction
        if ($hasReplenishment && $hasContraction) {
            // Unify recommendation: Account is due for replenishment, but has contracted
            $hasReplenishment->title = "Replenishment Outreach (Contraction Risk): {$hasReplenishment->accountLabel}";
            $hasReplenishment->description .= " Account has contracted by {$hasContraction->evidence['contraction_pct']}% (-\${$hasContraction->estimatedRevenueImpact}). Use this upcoming replenishment window to address reduced basket size.";
            $hasReplenishment->evidence['contraction_context'] = [
                'revenue_drop'    => $hasContraction->estimatedRevenueImpact,
                'contraction_pct' => $hasContraction->evidence['contraction_pct'] ?? null,
            ];
            $hasReplenishment->priorityScore = min(100.0, max($hasReplenishment->priorityScore, $hasContraction->priorityScore) + 5.0);

            return collect([$hasReplenishment]);
        }

        // Fallback: return highest priority finding
        return collect([$findings->sortByDesc('priorityScore')->first()]);
    }

    /**
     * Check whether an active or recent finding already exists for this fingerprint.
     */
    public function isDuplicateOrActive(int $clientId, string $fingerprint): bool
    {
        $dormancyCutoff = Carbon::now()->subDays(self::EPISODE_DORMANCY_DAYS);

        return Finding::where('client_id', $clientId)
            ->where('fingerprint', $fingerprint)
            ->where(function ($query) use ($dormancyCutoff) {
                $query->whereIn('status', [FindingStatus::New->value, FindingStatus::Acknowledged->value, FindingStatus::Snoozed->value, 'open', 'in_progress'])
                    ->orWhere('detected_at', '>=', $dormancyCutoff);
            })
            ->exists();
    }

    /**
     * Generate deterministic fingerprint for opportunity deduplication.
     */
    public function generateFingerprint(int $clientId, string $detectorKey, ?string $accountHash): string
    {
        $hashPart = $accountHash ? substr($accountHash, 0, 32) : 'general';
        return hash('sha256', "client:{$clientId}:opp:{$detectorKey}:{$hashPart}");
    }
}
