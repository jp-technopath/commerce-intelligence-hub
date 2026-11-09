<?php

namespace App\Services\Intelligence\Contracts;

use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Models\Client;
use App\Services\Intelligence\CustomerPurchaseAnalysisService;
use Illuminate\Support\Collection;

interface OpportunityDetectorInterface
{
    /**
     * Unique detector identifier (e.g. 'opportunity.b2b_account_reactivation').
     */
    public function key(): string;

    /**
     * Human-readable detector name.
     */
    public function name(): string;

    /**
     * Strategic business pillar.
     */
    public function pillar(): FindingPillar;

    /**
     * Finding category.
     */
    public function category(): FindingCategory;

    /**
     * Required data capabilities for this detector.
     *
     * @return array<string>
     */
    public function requiredDataCapabilities(): array;

    /**
     * Verify whether the client meets prerequisites to run this detector.
     */
    public function isSupportedForClient(Client $client): bool;

    /**
     * Execute discovery analysis and return a collection of OpportunityFindingData.
     *
     * @param  array<string, mixed>  $config
     * @return Collection<\App\Services\Intelligence\DTOs\OpportunityFindingData>
     */
    public function detect(Client $client, array $config, CustomerPurchaseAnalysisService $analysisService): Collection;
}
