<?php

namespace Tests\Unit\Intelligence;

use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\Intelligence\FindingTypeRegistry;
use App\Services\Intelligence\InitiativeAggregationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingTypeRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private User $admin;
    private InitiativeAggregationService $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'is_admin' => true,
        ]);
        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->first();
        UserRoleAssignment::create([
            'user_id'   => $this->admin->id,
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->client = Client::create([
            'name'          => 'Registry Verification Corp',
            'industry'      => 'Packaging',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
        ]);

        $this->aggregator = app(InitiativeAggregationService::class);
    }

    public function test_registry_classifies_all_three_pillars_with_independent_strategic_goals(): void
    {
        $winback = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'opportunity',
            'fingerprint'      => hash('sha256', 'reg_winback'),
            'source_type'      => 'detector',
            'source_id'        => 'opportunity.long_term_winback',
            'title'            => 'Long-Term Winback: Acme',
            'finding_category' => FindingCategory::Revenue,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
        ]);

        $cvrDrop = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'gk_ga4_conversion_rate_decline_30d',
            'fingerprint'      => hash('sha256', 'reg_cvr'),
            'source_type'      => 'rule',
            'title'            => 'Conversion rate decline',
            'finding_category' => FindingCategory::Conversion,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
        ]);

        $jsErr = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'technical.script_error',
            'fingerprint'      => hash('sha256', 'reg_js'),
            'source_type'      => 'detector',
            'source_id'        => 'storefront_checkout',
            'title'            => 'JS Script Error',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
        ]);

        $attrVar = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'attribution.platform_variance',
            'fingerprint'      => hash('sha256', 'reg_attr'),
            'source_type'      => 'detector',
            'source_id'        => 'ga4_vs_commerce',
            'title'            => 'GA4 vs Commerce variance',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::New,
        ]);

        $trafficSurge = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'traffic.volume_surge',
            'fingerprint'      => hash('sha256', 'reg_surge'),
            'source_type'      => 'detector',
            'source_id'        => 'direct',
            'title'            => 'Direct traffic surge',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::New,
        ]);

        $cWin = FindingTypeRegistry::classify($winback);
        $cCvr = FindingTypeRegistry::classify($cvrDrop);
        $cJs = FindingTypeRegistry::classify($jsErr);
        $cAttr = FindingTypeRegistry::classify($attrVar);
        $cSurge = FindingTypeRegistry::classify($trafficSurge);

        $this->assertSame(FindingPillar::GrowthOpportunity, $cWin['pillar']);
        $this->assertSame('Enterprise Winback Campaign', $cWin['strategic_goal']);

        $this->assertSame(FindingPillar::BusinessRisk, $cCvr['pillar']);
        $this->assertSame('Checkout Conversion Optimization', $cCvr['strategic_goal']);

        $this->assertSame(FindingPillar::TechnicalHealth, $cJs['pillar']);
        $this->assertSame('Storefront & Script Health', $cJs['strategic_goal']);

        $this->assertSame(FindingPillar::TechnicalHealth, $cAttr['pillar']);
        $this->assertSame('Revenue & Channel Attribution', $cAttr['strategic_goal']);

        $this->assertSame(FindingPillar::TechnicalHealth, $cSurge['pillar']);
        $this->assertSame('Direct Traffic & Referral Audit', $cSurge['strategic_goal']);
    }

    public function test_unknown_finding_type_never_defaults_to_growth_opportunity(): void
    {
        $unknown = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'exotic_unmapped_signal_v9',
            'fingerprint'      => hash('sha256', 'reg_unknown'),
            'source_type'      => 'detector',
            'source_id'        => 'exotic_unmapped_signal_v9',
            'title'            => 'Unknown Detector Output',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::New,
        ]);

        $classification = FindingTypeRegistry::classify($unknown);

        $this->assertNull($classification['pillar']);
        $this->assertNull($classification['pillar_value']);
        $this->assertFalse($classification['is_known']);
        $this->assertTrue($classification['requires_classification']);
        $this->assertSame('Unclassified Intelligence Review', $classification['strategic_goal']);

        // Must NOT appear when filtering GrowthOpportunity!
        $growthInits = $this->aggregator->getInitiatives($this->client, FindingPillar::GrowthOpportunity->value, $this->admin);
        $this->assertCount(0, $growthInits);

        // Appears in All Pillars explicitly flagged under Unclassified Intelligence Review
        $allInits = $this->aggregator->getInitiatives($this->client, null, $this->admin);
        $this->assertCount(1, $allInits);
        $this->assertSame('Unclassified Intelligence Review', $allInits->first()['strategic_goal']);

        // Appears under explicit 'unclassified' filter and triggers Classification Review tab in ListFindings
        $unclassifiedInits = $this->aggregator->getInitiatives($this->client, 'unclassified', $this->admin);
        $this->assertCount(1, $unclassifiedInits);

        \Livewire\Livewire::test(\App\Filament\Resources\FindingResource\Pages\ListFindings::class)
            ->assertSee('Classification Review');
    }

    public function test_constituent_level_pillar_counts_reconcile_across_all_pillars(): void
    {
        Finding::create([
            'client_id'                => $this->client->id,
            'finding_type'             => 'opportunity',
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'fingerprint'              => hash('sha256', 'c_g1'),
            'title'                    => 'Winback 1',
            'finding_pillar'           => FindingPillar::GrowthOpportunity,
            'finding_category'         => FindingCategory::Revenue,
            'severity'                 => FindingSeverity::High,
            'status'                   => FindingStatus::New,
            'estimated_revenue_impact' => 5000,
        ]);
        Finding::create([
            'client_id'                => $this->client->id,
            'finding_type'             => 'opportunity',
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'fingerprint'              => hash('sha256', 'c_g2'),
            'title'                    => 'Winback 2',
            'finding_pillar'           => FindingPillar::GrowthOpportunity,
            'finding_category'         => FindingCategory::Revenue,
            'severity'                 => FindingSeverity::Medium,
            'status'                   => FindingStatus::New,
            'estimated_revenue_impact' => 3000,
        ]);
        Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'gk_ga4_conversion_rate_decline_30d',
            'source_type'      => 'rule',
            'fingerprint'      => hash('sha256', 'c_br1'),
            'title'            => 'CVR Decline',
            'finding_pillar'   => null,
            'finding_category' => FindingCategory::Conversion,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
        ]);
        Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'technical.script_error',
            'source_type'      => 'detector',
            'source_id'        => 'sitewide',
            'fingerprint'      => hash('sha256', 'c_th1'),
            'title'            => 'JS Errors',
            'finding_pillar'   => null,
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
        ]);
        Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'attribution.platform_variance',
            'source_type'      => 'detector',
            'source_id'        => 'ga4_vs_commerce',
            'fingerprint'      => hash('sha256', 'c_th2'),
            'title'            => 'Platform Variance',
            'finding_pillar'   => null,
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::New,
        ]);
        Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'traffic.volume_surge',
            'source_type'      => 'detector',
            'source_id'        => 'direct',
            'fingerprint'      => hash('sha256', 'c_th3'),
            'title'            => 'Traffic Surge',
            'finding_pillar'   => null,
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::New,
        ]);

        $countFindings = fn ($inits) => $inits->sum('qualifying_accounts_count');

        $allCount = $countFindings($this->aggregator->getInitiatives($this->client, null, $this->admin));
        $growthCount = $countFindings($this->aggregator->getInitiatives($this->client, FindingPillar::GrowthOpportunity->value, $this->admin));
        $riskCount = $countFindings($this->aggregator->getInitiatives($this->client, FindingPillar::BusinessRisk->value, $this->admin));
        $techCount = $countFindings($this->aggregator->getInitiatives($this->client, FindingPillar::TechnicalHealth->value, $this->admin));

        $this->assertSame(2, $growthCount);
        $this->assertSame(1, $riskCount);
        $this->assertSame(3, $techCount);
        $this->assertSame($allCount, $growthCount + $riskCount + $techCount);
    }
}
