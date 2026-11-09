<?php

namespace App\Services\Intelligence;

use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Services\Intelligence\Contracts\OpportunityDetectorInterface;
use App\Services\Intelligence\DataQualification\IntegrationFreshnessTracker;
use App\Services\Intelligence\Detectors\AccountContractionDetector;
use App\Services\Intelligence\Detectors\B2bAccountReactivationDetector;
use App\Services\Intelligence\Detectors\LongTermWinbackDetector;
use App\Services\Intelligence\Detectors\ReplenishmentCadenceDetector;
use App\Services\Intelligence\DTOs\OpportunityFindingData;
use App\Services\VisibilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BusinessOpportunityEngine
{
    /**
     * @var array<OpportunityDetectorInterface>
     */
    protected array $detectors;

    public function __construct(
        protected CustomerPurchaseAnalysisService $analysisService,
        protected OpportunityCoordinator $coordinator,
        protected FindingConfigurationService $configService,
        protected IntegrationFreshnessTracker $freshnessTracker,
        ?array $detectors = null
    ) {
        $this->detectors = $detectors ?? [
            new B2bAccountReactivationDetector(),
            new ReplenishmentCadenceDetector(),
            new AccountContractionDetector(),
            new LongTermWinbackDetector(),
        ];
    }

    /**
     * Evaluate business opportunities for a client.
     *
     * @param  Client  $client
     * @param  bool    $dryRun
     * @return array
     */
    public function evaluateClient(Client $client, bool $dryRun = false): array
    {
        $summary = [
            'client_id'         => $client->id,
            'client_name'       => $client->name,
            'status'            => 'completed',
            'raw_count'         => 0,
            'coordinated_count' => 0,
            'created_count'     => 0,
            'suppressed_count'  => 0,
            'freshness'         => [],
            'findings'          => [],
        ];

        // 1. Data Readiness & Freshness Safeguards
        $freshness = $this->freshnessTracker->evaluateFreshness($client);
        $summary['freshness'] = [
            'target_date'              => $freshness['target_date'] ?? null,
            'is_business_day_complete' => $freshness['is_business_day_complete'] ?? true,
            'timezone'                 => $freshness['client_timezone'] ?? $client->timezone,
            'delayed_integrations'     => $freshness['delayed_integrations'] ?? [],
        ];

        $baselines = $this->analysisService->getDerivedClientBaselines($client);
        if (! $baselines['has_data'] || $baselines['total_customers'] < 20) {
            $summary['status'] = 'skipped_insufficient_data';
            $summary['message'] = 'Client lacks minimum transaction history (>= 20 customer facts) for purchasing analysis.';
            return $summary;
        }

        // 2. Resolve effective customer configuration
        $effectiveConfig = $this->configService->getEffectiveOpportunityConfiguration($client);

        // 3. Execute all active detectors
        $rawFindings = collect();

        foreach ($this->detectors as $detector) {
            $detectorConfig = $effectiveConfig[$detector->key()] ?? [
                'enabled' => true,
            ];

            if (! ($detectorConfig['enabled'] ?? true)) {
                continue;
            }

            if (! $detector->isSupportedForClient($client)) {
                continue;
            }

            try {
                $detectorResults = $detector->detect($client, $detectorConfig, $this->analysisService);
                $rawFindings = $rawFindings->concat($detectorResults);
            } catch (\Throwable $e) {
                Log::error("Error executing opportunity detector [{$detector->key()}] for client [{$client->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }

        $summary['raw_count'] = $rawFindings->count();

        // 4. Coordinate and de-conflict opportunities
        $coordinatedFindings = $this->coordinator->coordinate($client, $rawFindings);
        $summary['coordinated_count'] = $coordinatedFindings->count();

        // 5. Deduplicate against existing open episodes and persist
        foreach ($coordinatedFindings as $data) {
            /** @var OpportunityFindingData $data */
            $isDuplicate = $this->coordinator->isDuplicateOrActive($client->id, $data->fingerprint);

            if ($isDuplicate) {
                $summary['suppressed_count']++;
                continue;
            }

            $createdFinding = null;
            if (! $dryRun) {
                $createdFinding = Finding::create([
                    'client_id'                 => $client->id,
                    'finding_type'              => 'opportunity',
                    'finding_pillar'            => $data->pillar,
                    'finding_category'          => $data->category,
                    'source_type'               => 'detector',
                    'source_id'                 => $data->detectorKey,
                    'fingerprint'               => $data->fingerprint,
                    'account_identity_hash'     => $data->accountIdentityHash,
                    'title'                     => $data->title,
                    'description'               => $data->description,
                    'severity'                  => $data->severity,
                    'confidence_score'          => $data->confidenceScore,
                    'priority_score'            => $data->priorityScore,
                    'target_role'               => $data->targetRole,
                    'estimated_revenue_impact'  => $data->estimatedRevenueImpact,
                    'impact_nature'             => $data->impactNature,
                    'impact_assumptions_json'   => $data->impactAssumptions,
                    'evidence_json'             => $data->evidence,
                    'metadata_json'             => array_merge($data->metadata, [
                        'recommended_action'  => $data->recommendedAction,
                        'account_label'       => $data->accountLabel,
                        'identity_confidence' => $data->identityConfidence,
                    ]),
                    'status'                    => FindingStatus::New,
                    'detected_at'               => Carbon::now(),
                    'is_customer_visible'       => true,
                    'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
                ]);
            }

            $summary['created_count']++;
            $summary['findings'][] = array_merge($data->toArray(), [
                'created_id' => $createdFinding?->id,
            ]);
        }

        return $summary;
    }
}
