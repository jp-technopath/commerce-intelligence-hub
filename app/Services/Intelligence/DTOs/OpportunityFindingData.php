<?php

namespace App\Services\Intelligence\DTOs;

use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;

class OpportunityFindingData
{
    public function __construct(
        public string $detectorKey,
        public string $title,
        public string $description,
        public FindingPillar $pillar,
        public FindingCategory $category,
        public FindingSeverity $severity,
        public float $confidenceScore,
        public float $priorityScore,
        public string $targetRole,
        public ?string $accountIdentityHash,
        public ?string $accountLabel,
        public string $identityConfidence, // 'verified_account', 'stable_hash', 'provisional'
        public ?float $estimatedRevenueImpact,
        public string $impactNature, // 'verified_financial', 'modeled_opportunity', 'directional_opportunity'
        public array $impactAssumptions,
        public array $evidence,
        public array $metadata,
        public string $recommendedAction,
        public ?string $fingerprint = null,
    ) {}

    public function toArray(): array
    {
        return [
            'detector_key'             => $this->detectorKey,
            'title'                    => $this->title,
            'description'              => $this->description,
            'finding_pillar'           => $this->pillar->value,
            'finding_category'         => $this->category->value,
            'severity'                 => $this->severity->value,
            'confidence_score'         => $this->confidenceScore,
            'priority_score'           => $this->priorityScore,
            'target_role'              => $this->targetRole,
            'account_identity_hash'    => $this->accountIdentityHash,
            'account_label'            => $this->accountLabel,
            'identity_confidence'      => $this->identityConfidence,
            'estimated_revenue_impact' => $this->estimatedRevenueImpact,
            'impact_nature'            => $this->impactNature,
            'impact_assumptions'       => $this->impactAssumptions,
            'evidence'                 => $this->evidence,
            'metadata'                 => $this->metadata,
            'recommended_action'       => $this->recommendedAction,
            'fingerprint'              => $this->fingerprint,
        ];
    }
}
