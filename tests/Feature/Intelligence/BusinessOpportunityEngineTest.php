<?php

namespace Tests\Feature\Intelligence;

use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\ClientFindingConfiguration;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\Integration;
use App\Services\Intelligence\BusinessOpportunityEngine;
use App\Services\Intelligence\CustomerPurchaseAnalysisService;
use App\Services\Intelligence\Detectors\AccountContractionDetector;
use App\Services\Intelligence\Detectors\B2bAccountReactivationDetector;
use App\Services\Intelligence\Detectors\ReplenishmentCadenceDetector;
use App\Services\Intelligence\OpportunityCoordinator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessOpportunityEngineTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Integration $integration;
    private CustomerPurchaseAnalysisService $analysisService;
    private OpportunityCoordinator $coordinator;
    private BusinessOpportunityEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name'          => 'Goldkamp Wholesale Supply',
            'industry'      => 'packaging_and_industrial',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
            'timezone'      => 'America/New_York',
        ]);

        $this->integration = Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => 'adobe_commerce',
            'status'           => 'active',
            'config_json'      => [],
        ]);

        $this->analysisService = app(CustomerPurchaseAnalysisService::class);
        $this->analysisService->clearCache();
        $this->coordinator = app(OpportunityCoordinator::class);
        $this->engine = app(BusinessOpportunityEngine::class);
    }

    private function createOrder(string $customerHash, Carbon $orderDate, float $netRevenue, string $orderId = 'ord_1'): CommerceOrder
    {
        return CommerceOrder::create([
            'client_id'              => $this->client->id,
            'integration_id'         => $this->integration->id,
            'source'                 => 'magento',
            'source_order_id'        => $orderId,
            'customer_identity_hash' => $customerHash,
            'order_date'             => $orderDate,
            'net_revenue'            => $netRevenue,
            'is_valid'               => true,
        ]);
    }

    /**
     * Seed baseline filler customers so the client passes minimum transaction history gates.
     */
    private function seedBaselineCustomers(int $count = 25): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $hash = hash('sha256', "filler_customer_{$i}@goldkamp.test");
            CommerceCustomerPurchaseFact::create([
                'client_id'                  => $this->client->id,
                'customer_identity_hash'     => $hash,
                'customer_id'                => "filler_{$i}",
                'first_valid_order_at'       => Carbon::now()->subDays(300),
                'latest_valid_order_at'      => Carbon::now()->subDays(20),
                'lifetime_valid_order_count' => 5,
                'lifetime_net_revenue'       => 1000.00,
                'is_registered_customer'     => true,
            ]);

            // Add orders
            for ($j = 5; $j >= 1; $j--) {
                $this->createOrder($hash, Carbon::now()->subDays($j * 50), 200.00, "ord_filler_{$i}_{$j}");
            }
        }
    }

    public function test_detects_b2b_account_reactivation_with_sufficient_history_and_dormancy(): void
    {
        $this->seedBaselineCustomers(25);

        $targetHash = hash('sha256', 'dormant_vip@testcorp.com');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $targetHash,
            'customer_id'                => 'vip_1001',
            'first_valid_order_at'       => Carbon::now()->subDays(300),
            'latest_valid_order_at'      => Carbon::now()->subDays(120),
            'lifetime_valid_order_count' => 4,
            'lifetime_net_revenue'       => 8000.00,
            'is_registered_customer'     => true,
        ]);

        // Historical regular orders every ~30 days, then stopped 120 days ago
        $orderDates = [300, 270, 240, 120];
        foreach ($orderDates as $idx => $daysAgo) {
            $this->createOrder($targetHash, Carbon::now()->subDays($daysAgo), 2000.00, "ord_vip_{$idx}");
        }

        $detector = new B2bAccountReactivationDetector();
        $findings = $detector->detect($this->client, ['enabled' => true], $this->analysisService);

        $this->assertNotEmpty($findings);
        $finding = $findings->firstWhere('accountIdentityHash', $targetHash);

        $this->assertNotNull($finding);
        $this->assertEquals(B2bAccountReactivationDetector::KEY, $finding->detectorKey);
        $this->assertEquals(FindingPillar::GrowthOpportunity, $finding->pillar);
        $this->assertEquals('historical_reorder_value', $finding->impactNature);
        $this->assertEquals(2000.00, $finding->estimatedRevenueImpact);
        $this->assertEquals('verified_account', $finding->identityConfidence);
        $this->assertFalse($finding->impactAssumptions['is_guaranteed']);
        $this->assertFalse($finding->impactAssumptions['is_incremental_pipeline']);
    }

    public function test_reactivation_suppressed_for_historically_off_season_accounts(): void
    {
        $this->seedBaselineCustomers(25);

        $seasonalHash = hash('sha256', 'seasonal_buyer@summerresort.com');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $seasonalHash,
            'customer_id'                => 'season_201',
            'first_valid_order_at'       => Carbon::now()->subDays(400),
            'latest_valid_order_at'      => Carbon::now()->subDays(120),
            'lifetime_valid_order_count' => 4,
            'lifetime_net_revenue'       => 6000.00,
            'is_registered_customer'     => true,
        ]);

        // Place orders across 2024 and 2025 in months different from current month
        $currentMonth = (int) Carbon::now()->format('n');
        $offMonth1 = ($currentMonth + 3) % 12 ?: 12;
        $offMonth2 = ($currentMonth + 4) % 12 ?: 12;

        $this->createOrder($seasonalHash, Carbon::create(2024, $offMonth1, 15, 12, 0, 0), 1500.00, 'ord_season_0');
        $this->createOrder($seasonalHash, Carbon::create(2024, $offMonth2, 15, 12, 0, 0), 1500.00, 'ord_season_1');
        $this->createOrder($seasonalHash, Carbon::create(2025, $offMonth1, 15, 12, 0, 0), 1500.00, 'ord_season_2');
        $this->createOrder($seasonalHash, Carbon::create(2025, $offMonth2, 15, 12, 0, 0), 1500.00, 'ord_season_3');

        $detector = new B2bAccountReactivationDetector();
        $findings = $detector->detect($this->client, ['enabled' => true], $this->analysisService);

        $seasonFinding = $findings->firstWhere('accountIdentityHash', $seasonalHash);
        $this->assertNull($seasonFinding, 'Historically off-season customer should be suppressed from reactivation');
    }

    public function test_two_order_account_marked_provisional_and_uses_cohort_median(): void
    {
        $this->seedBaselineCustomers(25);

        $twoOrderHash = hash('sha256', 'new_vip@start.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $twoOrderHash,
            'customer_id'                => 'two_order_1',
            'first_valid_order_at'       => Carbon::now()->subDays(200),
            'latest_valid_order_at'      => Carbon::now()->subDays(110),
            'lifetime_valid_order_count' => 2,
            'lifetime_net_revenue'       => 5000.00,
            'is_registered_customer'     => true,
        ]);

        $this->createOrder($twoOrderHash, Carbon::now()->subDays(200), 2500.00, 'ord_two_1');
        $this->createOrder($twoOrderHash, Carbon::now()->subDays(110), 2500.00, 'ord_two_2');

        $profile = $this->analysisService->getCustomerAccountProfile($this->client, $twoOrderHash);

        $this->assertNotNull($profile);
        $this->assertEquals('limited_two_orders', $profile['cadence']['evidence_level']);
        $this->assertEquals('provisional', $profile['cadence']['confidence']);
        $this->assertTrue($profile['cadence']['cohort_benchmark_used']);
        $this->assertNull($profile['cadence']['std_dev_days']);
    }

    public function test_replenishment_cadence_detects_regular_consumable_buyers(): void
    {
        $this->seedBaselineCustomers(25);

        $repHash = hash('sha256', 'boxes_monthly@warehouse.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $repHash,
            'customer_id'                => 'rep_buyer_01',
            'first_valid_order_at'       => Carbon::now()->subDays(120),
            'latest_valid_order_at'      => Carbon::now()->subDays(31),
            'lifetime_valid_order_count' => 4,
            'lifetime_net_revenue'       => 2800.00,
            'is_registered_customer'     => true,
        ]);

        // Very regular ~30 day interval (30, 29, 30 days)
        $orderDates = [120, 90, 61, 31];
        foreach ($orderDates as $idx => $daysAgo) {
            $this->createOrder($repHash, Carbon::now()->subDays($daysAgo), 700.00, "ord_rep_{$idx}");
        }

        $detector = new ReplenishmentCadenceDetector();
        $findings = $detector->detect($this->client, ['enabled' => true], $this->analysisService);

        $this->assertNotEmpty($findings);
        $finding = $findings->firstWhere('accountIdentityHash', $repHash);

        $this->assertNotNull($finding);
        $this->assertEquals(ReplenishmentCadenceDetector::KEY, $finding->detectorKey);
        $this->assertEquals(700.00, $finding->estimatedRevenueImpact);
        $this->assertEquals('growth_marketer', $finding->targetRole);
    }

    public function test_replenishment_ignores_irregular_or_project_based_buyers(): void
    {
        $this->seedBaselineCustomers(25);

        $irregHash = hash('sha256', 'project_buyer@contractor.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $irregHash,
            'customer_id'                => 'irreg_buyer_01',
            'first_valid_order_at'       => Carbon::now()->subDays(250),
            'latest_valid_order_at'      => Carbon::now()->subDays(30),
            'lifetime_valid_order_count' => 4,
            'lifetime_net_revenue'       => 4000.00,
            'is_registered_customer'     => true,
        ]);

        // Irregular intervals: 120 days, 10 days, 90 days (high cv > 0.8)
        $orderDates = [250, 130, 120, 30];
        foreach ($orderDates as $idx => $daysAgo) {
            $this->createOrder($irregHash, Carbon::now()->subDays($daysAgo), 1000.00, "ord_irreg_{$idx}");
        }

        $detector = new ReplenishmentCadenceDetector();
        $findings = $detector->detect($this->client, ['enabled' => true], $this->analysisService);

        $finding = $findings->firstWhere('accountIdentityHash', $irregHash);
        $this->assertNull($finding, 'Irregular / project-based buyer should not trigger replenishment cadence detector');
    }

    public function test_account_contraction_identifies_verified_revenue_drop(): void
    {
        $this->seedBaselineCustomers(25);

        $contractingHash = hash('sha256', 'shrinking_client@distributor.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $contractingHash,
            'customer_id'                => 'contracting_01',
            'first_valid_order_at'       => Carbon::now()->subDays(170),
            'latest_valid_order_at'      => Carbon::now()->subDays(10),
            'lifetime_valid_order_count' => 5,
            'lifetime_net_revenue'       => 6500.00,
            'is_registered_customer'     => true,
        ]);

        // Baseline (days 91-180): $5,000 spend across 3 orders
        $this->createOrder($contractingHash, Carbon::now()->subDays(160), 1500.00, 'ord_base_1');
        $this->createOrder($contractingHash, Carbon::now()->subDays(130), 1500.00, 'ord_base_2');
        $this->createOrder($contractingHash, Carbon::now()->subDays(100), 2000.00, 'ord_base_3');

        // Evaluation period (trailing 90 days): Only $500 spend
        $this->createOrder($contractingHash, Carbon::now()->subDays(40), 250.00, 'ord_eval_1');
        $this->createOrder($contractingHash, Carbon::now()->subDays(10), 250.00, 'ord_eval_2');

        $detector = new AccountContractionDetector();
        $findings = $detector->detect($this->client, ['enabled' => true], $this->analysisService);

        $this->assertNotEmpty($findings);
        $finding = $findings->firstWhere('accountIdentityHash', $contractingHash);

        $this->assertNotNull($finding);
        $this->assertEquals(AccountContractionDetector::KEY, $finding->detectorKey);
        $this->assertEquals(FindingPillar::BusinessRisk, $finding->pillar);
        $this->assertEquals('observed_spending_decline', $finding->impactNature);
        $this->assertEquals(4500.00, $finding->estimatedRevenueImpact); // $5000 baseline - $500 eval
        $this->assertEquals(90.0, $finding->impactAssumptions['contraction_percentage']);
    }

    public function test_opportunity_coordinator_deconflicts_dormancy_and_contraction(): void
    {
        $this->seedBaselineCustomers(25);

        $dualHash = hash('sha256', 'dual_risk@corporate.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $dualHash,
            'customer_id'                => 'dual_01',
            'first_valid_order_at'       => Carbon::now()->subDays(200),
            'latest_valid_order_at'      => Carbon::now()->subDays(120),
            'lifetime_valid_order_count' => 4,
            'lifetime_net_revenue'       => 8000.00,
            'is_registered_customer'     => true,
        ]);

        // Prior orders: 200d, 160d, 130d, 120d ago. No orders in last 120 days.
        $orderDates = [200, 160, 130, 120];
        foreach ($orderDates as $idx => $daysAgo) {
            $this->createOrder($dualHash, Carbon::now()->subDays($daysAgo), 2000.00, "ord_dual_{$idx}");
        }

        // Run both detectors
        $reactivationDetector = new B2bAccountReactivationDetector();
        $contractionDetector = new AccountContractionDetector();

        $raw = collect();
        $raw = $raw->concat($reactivationDetector->detect($this->client, ['enabled' => true], $this->analysisService));
        $raw = $raw->concat($contractionDetector->detect($this->client, ['enabled' => true], $this->analysisService));

        // Coordinate
        $coordinated = $this->coordinator->coordinate($this->client, $raw);

        $accountFindings = $coordinated->where('accountIdentityHash', $dualHash);
        $this->assertCount(1, $accountFindings, 'Coordinator should produce exactly one primary finding for this account');

        $primary = $accountFindings->first();
        $this->assertEquals(B2bAccountReactivationDetector::KEY, $primary->detectorKey);
        $this->assertArrayHasKey('prior_contraction_drop', $primary->evidence);
    }

    public function test_opportunity_coordinator_deduplicates_active_episodes_within_dormancy_window(): void
    {
        $fingerprint = hash('sha256', "client:{$this->client->id}:opp:opportunity.b2b_account_reactivation:test_hash");

        // Existing open finding
        Finding::create([
            'client_id'                 => $this->client->id,
            'finding_type'              => 'opportunity',
            'finding_pillar'            => FindingPillar::GrowthOpportunity,
            'finding_category'          => FindingCategory::Customer,
            'source_type'               => 'detector',
            'source_id'                 => B2bAccountReactivationDetector::KEY,
            'fingerprint'               => $fingerprint,
            'title'                     => 'High-Value Account Dormancy',
            'description'               => 'Previous finding',
            'severity'                  => FindingSeverity::High,
            'confidence_score'          => 0.90,
            'priority_score'            => 85.0,
            'status'                    => FindingStatus::New,
            'detected_at'               => Carbon::now()->subDays(3),
            'is_customer_visible'       => true,
            'visibility_classification' => 'customer_visible',
        ]);

        $this->assertTrue($this->coordinator->isDuplicateOrActive($this->client->id, $fingerprint));
    }

    public function test_client_finding_configuration_schema_v2_overrides_thresholds(): void
    {
        $this->seedBaselineCustomers(25);

        // Save schema v2 configuration for client
        ClientFindingConfiguration::create([
            'client_id'      => $this->client->id,
            'version'        => 1,
            'schema_version'     => '2.0',
            'configuration_json' => [
                'schema_version'        => '2.0',
                'client'                => ['id' => $this->client->id, 'name' => $this->client->name],
                'opportunity_detectors' => [
                    [
                        'key'        => B2bAccountReactivationDetector::KEY,
                        'enabled'    => false, // Disabled by client override!
                        'thresholds' => ['dormancy_multiplier' => 3.0],
                    ],
                ],
                'rules' => [],
            ],
            'is_active' => true,
        ]);

        $targetHash = hash('sha256', 'dormant_vip@testcorp.com');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $targetHash,
            'customer_id'                => 'vip_1001',
            'first_valid_order_at'       => Carbon::now()->subDays(300),
            'latest_valid_order_at'      => Carbon::now()->subDays(120),
            'lifetime_valid_order_count' => 4,
            'lifetime_net_revenue'       => 8000.00,
            'is_registered_customer'     => true,
        ]);

        for ($k = 1; $k <= 4; $k++) {
            $this->createOrder($targetHash, Carbon::now()->subDays($k * 40), 2000.00, "ord_v2_{$k}");
        }

        $result = $this->engine->evaluateClient($this->client, true);

        // Since reactivation detector was disabled via client config, no reactivation findings should exist
        $reactivationFindings = collect($result['findings'])->where('detector_key', B2bAccountReactivationDetector::KEY);
        $this->assertEmpty($reactivationFindings, 'Disabled detector in client config v2 should not generate findings');
    }

    public function test_business_opportunity_engine_skips_clients_with_insufficient_data(): void
    {
        $emptyClient = Client::create([
            'name'          => 'Brand New Client',
            'industry'      => 'retail',
            'platform_type' => 'Shopify',
            'status'        => ClientStatus::Active,
        ]);

        $result = $this->engine->evaluateClient($emptyClient, true);

        $this->assertEquals('skipped_insufficient_data', $result['status']);
        $this->assertEquals(0, $result['created_count']);
    }

    public function test_contraction_suppressed_for_in_cycle_accounts_with_long_cadence(): void
    {
        $this->seedBaselineCustomers(25);

        // Account orders once every ~180 days. Last ordered 110 days ago.
        // In trailing 90 days, spend is $0. Under fixed 90-day window, this would be a false alarm!
        $semiAnnualHash = hash('sha256', 'semi_annual@packaging.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $semiAnnualHash,
            'customer_id'                => 'semi_01',
            'first_valid_order_at'       => Carbon::now()->subDays(470),
            'latest_valid_order_at'      => Carbon::now()->subDays(110),
            'lifetime_valid_order_count' => 3,
            'lifetime_net_revenue'       => 6000.00,
            'is_registered_customer'     => true,
        ]);

        $this->createOrder($semiAnnualHash, Carbon::now()->subDays(470), 2000.00, 'ord_semi_1');
        $this->createOrder($semiAnnualHash, Carbon::now()->subDays(290), 2000.00, 'ord_semi_2');
        $this->createOrder($semiAnnualHash, Carbon::now()->subDays(110), 2000.00, 'ord_semi_3');

        $detector = new AccountContractionDetector();
        $findings = $detector->detect($this->client, ['enabled' => true], $this->analysisService);

        $finding = $findings->firstWhere('accountIdentityHash', $semiAnnualHash);
        $this->assertNull($finding, 'Account in normal 180-day cycle should be suppressed from contraction despite 0 spend in trailing 90 days');
    }

    public function test_long_term_winback_detector_qualifies_proven_recurring_and_excludes_split_orders(): void
    {
        $this->seedBaselineCustomers(25);

        // Account A: Split/project purchase (2 orders placed only 5 days apart, 250 days ago) -> MUST BE EXCLUDED
        $splitHash = hash('sha256', 'split_buyer@project.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $splitHash,
            'customer_id'                => 'split_01',
            'first_valid_order_at'       => Carbon::now()->subDays(255),
            'latest_valid_order_at'      => Carbon::now()->subDays(250),
            'lifetime_valid_order_count' => 2,
            'lifetime_net_revenue'       => 4000.00,
            'is_registered_customer'     => true,
        ]);
        $this->createOrder($splitHash, Carbon::now()->subDays(255), 2000.00, 'ord_split_1');
        $this->createOrder($splitHash, Carbon::now()->subDays(250), 2000.00, 'ord_split_2');

        // Account B: Proven recurring enterprise customer (4 orders, 240 days dormant) -> MUST QUALIFY
        $provenHash = hash('sha256', 'proven_buyer@enterprise.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $provenHash,
            'customer_id'                => 'proven_01',
            'first_valid_order_at'       => Carbon::now()->subDays(450),
            'latest_valid_order_at'      => Carbon::now()->subDays(240),
            'lifetime_valid_order_count' => 4,
            'lifetime_net_revenue'       => 12000.00,
            'is_registered_customer'     => true,
        ]);
        $this->createOrder($provenHash, Carbon::now()->subDays(450), 3000.00, 'ord_proven_1');
        $this->createOrder($provenHash, Carbon::now()->subDays(380), 3000.00, 'ord_proven_2');
        $this->createOrder($provenHash, Carbon::now()->subDays(310), 3000.00, 'ord_proven_3');
        $this->createOrder($provenHash, Carbon::now()->subDays(240), 3000.00, 'ord_proven_4');

        $detector = new \App\Services\Intelligence\Detectors\LongTermWinbackDetector();
        $findings = $detector->detect($this->client, ['enabled' => true], $this->analysisService);

        $splitFinding = $findings->firstWhere('accountIdentityHash', $splitHash);
        $this->assertNull($splitFinding, 'Split/project purchase with span <= 14 days must be excluded from long-term winback');

        $provenFinding = $findings->firstWhere('accountIdentityHash', $provenHash);
        $this->assertNotNull($provenFinding, 'Proven recurring enterprise account must qualify for long-term winback');
        $this->assertEquals('historical_reorder_value', $provenFinding->impactNature);
        $this->assertEquals(3000.00, $provenFinding->estimatedRevenueImpact);
        $this->assertEquals('proven_recurring', $provenFinding->impactAssumptions['relationship_qualification']);
        $this->assertEquals('executive_sales', $provenFinding->targetRole);
    }

    public function test_goldkamp_clustered_summer_orders_account_4251db54_is_normalized_and_suppressed_from_false_contraction(): void
    {
        $this->seedBaselineCustomers(25);

        // Account simulating Goldkamp #4251db54:
        // Historical irregular purchasing (intervals ~54-106 days, mean ~58d, std ~47d).
        $accountHash = hash('sha256', 'account_4251db54@goldkamp.test');
        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'     => $accountHash,
            'customer_id'                => '4251db54',
            'first_valid_order_at'       => Carbon::now()->subDays(500),
            'latest_valid_order_at'      => Carbon::now()->subDays(80),
            'lifetime_valid_order_count' => 6,
            'lifetime_net_revenue'       => 9505.49,
            'is_registered_customer'     => true,
        ]);

        $this->createOrder($accountHash, Carbon::now()->subDays(500), 1600.00, 'ord_gk_0');
        $this->createOrder($accountHash, Carbon::now()->subDays(280), 1700.00, 'ord_gk_1');
        $this->createOrder($accountHash, Carbon::now()->subDays(210), 1800.00, 'ord_gk_2');
        $this->createOrder($accountHash, Carbon::now()->subDays(140), 2000.00, 'ord_gk_3'); // baseline cluster
        $this->createOrder($accountHash, Carbon::now()->subDays(130), 2400.00, 'ord_gk_4'); // baseline cluster (10d apart)
        $this->createOrder($accountHash, Carbon::now()->subDays(80), 405.49, 'ord_gk_5');   // eval window

        // 1. Verify CustomerPurchaseAnalysisService profile calculations:
        // Baseline spend must be normalized from raw $4,400 to expected quarterly run-rate
        $profile = $this->analysisService->getCustomerAccountProfile($this->client, $accountHash);
        $this->assertNotNull($profile);
        $this->assertEquals('irregular_or_project_based', $profile['cadence']['regularity']);
        $this->assertTrue($profile['contraction']['has_outlier_normalization']);
        $this->assertEquals(4400.00, $profile['contraction']['raw_baseline_spend']);
        $this->assertLessThan(3000.00, $profile['contraction']['baseline_spend'], 'Baseline spend should be normalized to expected run rate');
        $this->assertTrue($profile['contraction']['is_in_cycle'], 'Account with order 80 days ago is within natural cadence variance and should be in-cycle');
        $this->assertFalse($profile['contraction']['is_contracting']);

        // 2. Verify detector output: Account must NOT trigger a false contraction finding
        $detector = new AccountContractionDetector();
        $findings = $detector->detect($this->client, ['enabled' => true], $this->analysisService);
        $finding = $findings->firstWhere('accountIdentityHash', $accountHash);
        $this->assertNull($finding, 'Account with summer cluster in baseline but within natural irregular cadence should not trigger contraction finding');
    }

    public function test_transparts_insufficient_history_suppresses_dormancy_and_contraction_detectors(): void
    {
        // Create a client with only 87 days of transaction history (like Transparts)
        $shortClient = Client::create([
            'name'          => 'Transparts Short History Client',
            'industry'      => 'commercial_fleet_parts',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
            'timezone'      => 'America/Chicago',
        ]);

        $shortIntegration = Integration::create([
            'client_id'        => $shortClient->id,
            'integration_type' => 'adobe_commerce',
            'status'           => 'active',
            'config_json'      => [],
        ]);

        // Seed 25 accounts whose earliest order is 87 days ago
        for ($i = 1; $i <= 25; $i++) {
            $hash = hash('sha256', "transparts_{$i}@fleet.test");
            CommerceCustomerPurchaseFact::create([
                'client_id'                  => $shortClient->id,
                'customer_identity_hash'     => $hash,
                'customer_id'                => "tp_{$i}",
                'first_valid_order_at'       => Carbon::now()->subDays(87),
                'latest_valid_order_at'      => Carbon::now()->subDays(70),
                'lifetime_valid_order_count' => 3,
                'lifetime_net_revenue'       => 1500.00,
                'is_registered_customer'     => true,
            ]);

            CommerceOrder::create([
                'client_id'              => $shortClient->id,
                'integration_id'         => $shortIntegration->id,
                'source'                 => 'magento',
                'source_order_id'        => "tp_ord_{$i}_1",
                'customer_identity_hash' => $hash,
                'order_date'             => Carbon::now()->subDays(87),
                'net_revenue'            => 500.00,
                'is_valid'               => true,
            ]);
            CommerceOrder::create([
                'client_id'              => $shortClient->id,
                'integration_id'         => $shortIntegration->id,
                'source'                 => 'magento',
                'source_order_id'        => "tp_ord_{$i}_2",
                'customer_identity_hash' => $hash,
                'order_date'             => Carbon::now()->subDays(80),
                'net_revenue'            => 500.00,
                'is_valid'               => true,
            ]);
            CommerceOrder::create([
                'client_id'              => $shortClient->id,
                'integration_id'         => $shortIntegration->id,
                'source'                 => 'magento',
                'source_order_id'        => "tp_ord_{$i}_3",
                'customer_identity_hash' => $hash,
                'order_date'             => Carbon::now()->subDays(70),
                'net_revenue'            => 500.00,
                'is_valid'               => true,
            ]);
        }

        $reactivationDetector = new B2bAccountReactivationDetector();
        $contractionDetector = new AccountContractionDetector();
        $winbackDetector = new \App\Services\Intelligence\Detectors\LongTermWinbackDetector();

        $this->assertEmpty($reactivationDetector->detect($shortClient, ['enabled' => true], $this->analysisService), 'Reactivation detector requires >= 180 days of client history');
        $this->assertEmpty($contractionDetector->detect($shortClient, ['enabled' => true], $this->analysisService), 'Contraction detector requires >= 180 days of client history');
        $this->assertEmpty($winbackDetector->detect($shortClient, ['enabled' => true], $this->analysisService), 'Winback detector requires >= 365 days of client history');
    }

    public function test_finding_lifecycle_transitions_record_audit_trail_and_attribution(): void
    {
        $admin = \App\Models\User::factory()->create(['name' => 'Admin Reviewer']);
        $lead = \App\Models\User::factory()->create(['name' => 'Account Executive']);

        $finding = Finding::create([
            'client_id'                  => $this->client->id,
            'finding_type'               => 'opportunity',
            'fingerprint'                => hash('sha256', 'lifecycle_test_fingerprint'),
            'detector_key'               => B2bAccountReactivationDetector::KEY,
            'title'                      => 'Dormant Account Lifecycle Test',
            'severity'                   => FindingSeverity::High,
            'finding_pillar'             => FindingPillar::GrowthOpportunity,
            'finding_category'           => FindingCategory::Revenue,
            'status'                     => FindingStatus::New,
            'impact_nature'              => 'historical_reorder_value',
            'estimated_revenue_impact'   => 5000.00,
            'evidence_json'              => ['account_hash' => 'test_account'],
            'impact_assumptions_json'    => ['test' => true],
            'confidence_score'           => 90.0,
            'target_role'                => 'account_executive',
        ]);

        $this->assertEquals(FindingStatus::New, $finding->status);
        $this->assertTrue($finding->isOpen());

        // 1. Move to Under Review
        $finding->transitionTo(FindingStatus::UnderReview, $admin, 'Reviewing account order history');
        $finding->refresh();
        $this->assertEquals(FindingStatus::UnderReview, $finding->status);
        $this->assertNotNull($finding->under_review_at);
        $this->assertTrue($finding->isOpen());

        // 2. Approve
        $finding->transitionTo(FindingStatus::Approved, $admin, 'Approved for sales engagement');
        $finding->refresh();
        $this->assertEquals(FindingStatus::Approved, $finding->status);
        $this->assertNotNull($finding->approved_at);
        $this->assertEquals($admin->id, $finding->approved_by_user_id);
        $this->assertTrue($finding->isOpen());

        // 3. Assign
        $finding->transitionTo(FindingStatus::Assigned, $admin, 'Assigned to Lead AE', $lead);
        $finding->refresh();
        $this->assertEquals(FindingStatus::Assigned, $finding->status);
        $this->assertNotNull($finding->assigned_at);
        $this->assertEquals($lead->id, $finding->responsible_user_id);
        $this->assertTrue($finding->isOpen());

        // 4. Resolve
        $finding->transitionTo(FindingStatus::Resolved, $lead, 'Client placed $6,200 reorder via phone');
        $finding->refresh();
        $this->assertEquals(FindingStatus::Resolved, $finding->status);
        $this->assertNotNull($finding->resolved_at);
        $this->assertEquals('Client placed $6,200 reorder via phone', $finding->resolution_notes);
        $this->assertFalse($finding->isOpen());

        // 5. Verify status history audit trail
        $history = $finding->status_history_json;
        $this->assertIsArray($history);
        $this->assertCount(4, $history);
        $this->assertEquals('new', $history[0]['from_status']);
        $this->assertEquals('under_review', $history[0]['to_status']);
        $this->assertEquals($admin->id, $history[0]['changed_by_user_id']);
        $this->assertEquals('under_review', $history[1]['from_status']);
        $this->assertEquals('approved', $history[1]['to_status']);
        $this->assertEquals('approved', $history[2]['from_status']);
        $this->assertEquals('assigned', $history[2]['to_status']);
        $this->assertEquals('assigned', $history[3]['from_status']);
        $this->assertEquals('resolved', $history[3]['to_status']);
        $this->assertEquals('Client placed $6,200 reorder via phone', $history[3]['notes']);
    }
}
