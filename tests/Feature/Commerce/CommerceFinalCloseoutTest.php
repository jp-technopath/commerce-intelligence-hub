<?php

namespace Tests\Feature\Commerce;

use App\Enums\FindingCategory;
use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingStatus;
use App\Filament\Pages\BusinessDashboard;
use App\Jobs\RunNightlyAnalysis;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\FindingStatusTransition;
use App\Models\Integration;
use App\Models\User;
use App\Services\Intelligence\BusinessOpportunityEngine;
use App\Services\Intelligence\ChangeDetectionEngine;
use App\Services\Intelligence\FindingRuleEvaluator;
use App\Services\Intelligence\InitiativeAggregationService;
use App\Services\Intelligence\OutcomeMeasurementService;
use App\Services\Intelligence\WorkPrioritizationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CommerceFinalCloseoutTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create([
            'is_admin' => true,
        ]);

        return $user;
    }

    private function makeClient(int $id, array $attrs): Client
    {
        $client = Client::find($id) ?? new Client();
        $client->fill($attrs);
        $client->id = $id;
        $client->save();

        return $client->fresh();
    }

    private function makeFinding(int $id, array $attrs): Finding
    {
        $finding = new Finding($attrs);
        $finding->id = $id;
        $finding->save();

        return $finding->fresh();
    }

    public function test_synthetic_and_internal_tenants_cannot_generate_intelligence_or_appear_in_portfolio_or_outcomes(): void
    {
        $admin = $this->createSuperAdmin();

        $external = $this->makeClient(10, [
            'name'          => 'Valid External Client',
            'platform_type' => 'adobe_commerce',
            'status'        => 'active',
            'is_internal'   => false,
        ]);

        $synthetic = $this->makeClient(638, [
            'name'              => 'Tinker Test',
            'platform_type'     => 'adobe_commerce',
            'status'            => 'active',
            'is_internal'       => false,
            'monitoring_config' => ['is_synthetic_fixture' => true],
        ]);

        for ($d = 0; $d < 14; $d++) {
            CommerceMetric::create([
                'client_id' => $synthetic->id,
                'date'      => now()->subDays(10 + $d)->toDateString(),
                'source'    => 'adobe_commerce',
                'revenue'   => 1428.57,
                'orders'    => 10,
                'sessions'  => 500,
            ]);
        }

        // 1. Intelligence generators must refuse internal/synthetic tenants
        $this->assertTrue($synthetic->isInternal());
        $this->assertSame(0, app(ChangeDetectionEngine::class)->run($synthetic));
        $this->assertSame(0, app(FindingRuleEvaluator::class)->evaluate($synthetic));
        $oppResult = app(BusinessOpportunityEngine::class)->evaluateClient($synthetic);
        $this->assertSame('skipped_internal_client', $oppResult['status']);

        (new RunNightlyAnalysis())->handle();
        $this->assertSame(0, Finding::where('client_id', $synthetic->id)->count());

        // 2. Even if a historical finding row exists for an internal/synthetic tenant, portfolio UI, prioritization & outcomes exclude/block it
        $syntheticFinding = Finding::create([
            'client_id'           => $synthetic->id,
            'finding_type'        => 'attribution.platform_variance',
            'finding_pillar'      => FindingPillar::TechnicalHealth->value,
            'finding_category'    => FindingCategory::Revenue->value,
            'title'               => 'Synthetic Variance',
            'description'         => 'Synthetic fixture row.',
            'status'              => FindingStatus::New->value,
            'execution_status'    => FindingExecutionStatus::ActionCompleted->value,
            'action_completed_at' => now()->subDay(),
            'is_customer_visible' => true,
        ]);

        $externalFinding = Finding::create([
            'client_id'           => $external->id,
            'finding_type'        => 'attribution.platform_variance',
            'finding_pillar'      => FindingPillar::TechnicalHealth->value,
            'finding_category'    => FindingCategory::Revenue->value,
            'title'               => 'External Variance',
            'description'         => 'Real tenant variance.',
            'status'              => FindingStatus::New->value,
            'is_customer_visible' => true,
        ]);

        $initsAll = app(InitiativeAggregationService::class)->getInitiatives(null, null, $admin);
        $this->assertFalse($initsAll->contains('client_id', $synthetic->id));
        $this->assertTrue($initsAll->contains('client_id', $external->id));
        $this->assertCount(0, app(InitiativeAggregationService::class)->getInitiatives($synthetic, null, $admin));

        $prioritization = app(WorkPrioritizationEngine::class)->getManagerOverview(null, $admin);
        $marketingNeeds = collect($prioritization['roles']['marketing']['needs_attention'] ?? []);
        $this->assertFalse($marketingNeeds->contains('client_id', $synthetic->id));

        $this->actingAs($admin);
        $dashboard = new BusinessDashboard();
        $dashboard->selectedClientId = $synthetic->id;
        $this->assertNull($dashboard->getSelectedClientProperty());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('internal or synthetic tenant');
        app(OutcomeMeasurementService::class)->evaluateFindingOutcome($syntheticFinding, $admin);
    }

    public function test_data_correction_dismissals_cannot_be_measured_as_recovered_revenue(): void
    {
        $admin = $this->createSuperAdmin();
        $client = Client::create([
            'name'          => 'External Client',
            'platform_type' => 'adobe_commerce',
            'status'        => 'active',
            'is_internal'   => false,
        ]);

        $finding = Finding::create([
            'client_id'           => $client->id,
            'finding_type'        => 'attribution.platform_variance',
            'finding_pillar'      => FindingPillar::TechnicalHealth->value,
            'finding_category'    => FindingCategory::Revenue->value,
            'title'               => 'GA4 vs Commerce variance',
            'description'         => 'Corrected source variance.',
            'status'              => FindingStatus::Dismissed->value,
            'execution_status'    => FindingExecutionStatus::Dismissed->value,
            'outcome_status'      => FindingOutcomeStatus::Dismissed->value,
            'action_completed_at' => now()->subDay(),
            'metadata_json'       => [
                'disposition_type' => 'remediated_by_source_data_correction',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-actionable or dismissed');
        app(OutcomeMeasurementService::class)->evaluateFindingOutcome($finding, $admin);
    }

    public function test_goldkamp_191_traffic_dilution_renders_analytics_audit_without_checkout_or_bot_claims(): void
    {
        $admin = $this->createSuperAdmin();
        $goldkamp = $this->makeClient(5, [
            'name'          => 'GoldKamp',
            'platform_type' => 'adobe_commerce',
            'status'        => 'active',
            'is_internal'   => false,
        ]);

        $this->makeFinding(191, [
            'client_id'        => $goldkamp->id,
            'finding_type'     => 'gk_ga4_conversion_rate_decline_30d',
            'finding_pillar'   => FindingPillar::BusinessRisk->value,
            'finding_category' => FindingCategory::Conversion->value,
            'title'            => 'Conversion rate deteriorating (30 days) (-70%)',
            'description'      => 'GA4 CVR diluted by Direct traffic.',
            'status'           => FindingStatus::New->value,
            'evidence_json'    => [
                'source_window_start'    => '2026-10-09',
                'source_window_end'      => '2026-11-07',
                'conversion_diagnostics' => [
                    'diagnosis_type' => 'traffic_denominator_dilution',
                    'current'        => [
                        'ga4_overall_cvr'     => 0.194,
                        'ga4_non_direct_cvr'  => 2.526,
                        'ga4_direct_share_pct'=> 93.5,
                        'magento_valid_orders'=> 74,
                        'magento_valid_net_revenue' => 68468.15,
                    ],
                    'previous'       => [
                        'ga4_overall_cvr'     => 0.705,
                        'ga4_non_direct_cvr'  => 2.116,
                        'ga4_direct_share_pct'=> 74.9,
                        'magento_valid_orders'=> 69,
                        'magento_valid_net_revenue' => 60727.53,
                    ],
                ],
            ],
            'metadata_json'    => [
                'difference_pct'      => -70.0,
                'period_days'         => 30,
                'metric_a'            => ['key' => 'ga4.conversion_rate', 'value' => 0.21],
                'source_window_start' => '2026-10-09',
                'source_window_end'   => '2026-11-07',
            ],
        ]);

        $inits = app(InitiativeAggregationService::class)->getInitiatives($goldkamp, null, $admin);
        $auditCard = $inits->firstWhere('strategic_goal', 'Checkout Conversion Optimization');

        $this->assertNotNull($auditCard);
        $this->assertSame('Conversion Rate & Traffic Dilution Audit', $auditCard['display_title']);
        $this->assertStringContainsString('rather than modifying checkout flows', $auditCard['card_next_step']);
        $this->assertStringContainsString('does not indicate checkout deterioration or confirm automated bot activity', $auditCard['business_rationale']);
        $this->assertStringContainsString('rather than modifying checkout flows', $auditCard['recommended_action']);
    }

    public function test_closeout_command_quarantines_synthetic_metrics_and_reconciles_findings_idempotently(): void
    {
        $cambro = $this->makeClient(1, ['name' => 'Cambro', 'platform_type' => 'adobe_commerce', 'status' => 'active']);
        $transpart = $this->makeClient(2, ['name' => 'Transpart', 'platform_type' => 'adobe_commerce', 'status' => 'active']);
        $goldkamp = $this->makeClient(5, ['name' => 'GoldKamp', 'platform_type' => 'adobe_commerce', 'status' => 'active']);
        $tinker = $this->makeClient(99638, ['name' => 'Tinker Test', 'platform_type' => 'adobe_commerce', 'status' => 'active', 'is_internal' => false]);

        $integ1 = Integration::create(['client_id' => 1, 'integration_type' => 'adobe_commerce', 'status' => 'active']);
        $integ2 = Integration::create(['client_id' => 2, 'integration_type' => 'adobe_commerce', 'status' => 'active']);
        $integ5 = Integration::create(['client_id' => 5, 'integration_type' => 'adobe_commerce', 'status' => 'active']);

        // Synthetic metrics for #99638
        CommerceMetric::create([
            'client_id' => 99638,
            'date'      => '2026-10-25',
            'source'    => 'adobe_commerce',
            'revenue'   => 1428.57,
            'orders'    => 10,
            'sessions'  => 500,
        ]);

        // Seed 30d GA4 & Commerce metrics for Transpart (#187) & Goldkamp (#280) with < 20% variance
        for ($i = 0; $i < 30; $i++) {
            $d = now()->subDays(30 - $i)->toDateString();
            CommerceMetric::create(['client_id' => 2, 'date' => $d, 'source' => 'ga4', 'revenue' => 6000, 'orders' => 20, 'sessions' => 500, 'active_users' => 450]);
            CommerceMetric::create(['client_id' => 2, 'date' => $d, 'source' => 'adobe_commerce', 'revenue' => 6200, 'orders' => 21]);
            CommerceOrder::create([
                'client_id' => 2, 'integration_id' => $integ2->id, 'source' => 'adobe_commerce',
                'source_order_id' => "T-$i", 'order_status' => 'complete', 'order_date' => "{$d} 12:00:00",
                'gross_revenue' => 6200, 'net_revenue' => 6200, 'is_valid' => true,
            ]);

            CommerceMetric::create([
                'client_id' => 5, 'date' => $d, 'source' => 'ga4', 'revenue' => 2500, 'orders' => 3, 'sessions' => 5000, 'active_users' => 4900,
                'source_breakdown_json' => ['Direct' => ['sessions' => 4800, 'orders' => 0]],
                'metadata_json' => ['funnel' => ['view_item' => 400, 'add_to_cart' => 10, 'begin_checkout' => 8, 'purchase' => 3]],
            ]);
            CommerceMetric::create(['client_id' => 5, 'date' => $d, 'source' => 'adobe_commerce', 'revenue' => 2350, 'orders' => 3]);
            CommerceOrder::create([
                'client_id' => 5, 'integration_id' => $integ5->id, 'source' => 'adobe_commerce',
                'source_order_id' => "G-$i", 'order_status' => 'complete', 'order_date' => "{$d} 12:00:00",
                'gross_revenue' => 2350, 'net_revenue' => 2350, 'is_valid' => true,
            ]);
        }

        // Seed Cambro prior vs current 30d + prior vs current 14d (improving CVR & funnel completion)
        for ($i = 1; $i <= 60; $i++) {
            $d = now()->subDays($i)->toDateString();
            $isRecent14 = $i <= 14;
            $isPrior14 = $i > 14 && $i <= 28;
            $isRecent30 = $i <= 30;
            if ($isRecent14 || $isPrior14) {
                CommerceMetric::create([
                    'client_id' => 1, 'date' => $d, 'source' => 'ga4',
                    'revenue' => $isRecent14 ? 700 : 200,
                    'orders' => $isRecent14 ? 4 : 1,
                    'sessions' => 100,
                    'active_users' => 90,
                    'metadata_json' => ['funnel' => ['begin_checkout' => 6, 'purchase' => $isRecent14 ? 4 : 1]],
                ]);
            }
            $orderRev = $isRecent30 ? 750 : 500;
            $ordersToday = $isRecent14 ? 2 : 1;
            for ($k = 1; $k <= $ordersToday; $k++) {
                CommerceOrder::create([
                    'client_id' => 1, 'integration_id' => $integ1->id, 'source' => 'adobe_commerce',
                    'source_order_id' => "C-{$i}-{$k}", 'order_status' => 'complete', 'order_date' => "{$d} 12:00:00",
                    'gross_revenue' => $orderRev, 'net_revenue' => $orderRev, 'is_valid' => true,
                ]);
            }
        }

        $refDate = now()->subDay()->toDateString();

        $f283 = $this->makeFinding(283, ['client_id' => 99638, 'finding_type' => 'attribution.platform_variance', 'finding_pillar' => 'technical_health', 'finding_category' => 'Revenue', 'title' => 'Synthetic', 'description' => 'Synthetic', 'status' => 'new', 'is_customer_visible' => true]);
        $f187 = $this->makeFinding(187, ['client_id' => 2, 'finding_type' => 'attribution.platform_variance', 'finding_pillar' => 'technical_health', 'finding_category' => 'Revenue', 'title' => 'T Var', 'description' => 'T Var', 'status' => 'new', 'evidence_json' => ['source_window_start' => '2026-10-28', 'source_window_end' => '2026-11-26']]);
        $f280 = $this->makeFinding(280, ['client_id' => 5, 'finding_type' => 'attribution.platform_variance', 'finding_pillar' => 'technical_health', 'finding_category' => 'Revenue', 'title' => 'G Var', 'description' => 'G Var', 'status' => 'new', 'evidence_json' => ['source_window_start' => '2026-10-28', 'source_window_end' => '2026-11-26']]);
        $f281 = $this->makeFinding(281, ['client_id' => 1, 'finding_type' => 'adobe_commerce_revenue_drop', 'finding_pillar' => 'business_risk', 'finding_category' => 'Revenue', 'title' => 'C Drop', 'description' => 'C Drop', 'status' => 'new', 'evidence_json' => ['source_window_start' => '2026-10-13', 'source_window_end' => '2026-11-11']]);
        $f186 = $this->makeFinding(186, ['client_id' => 1, 'finding_type' => 'conversion_rate_drop', 'finding_pillar' => 'business_risk', 'finding_category' => 'Conversion', 'title' => 'C CVR', 'description' => 'C CVR', 'status' => 'new', 'evidence_json' => ['source_window_start' => '2026-10-25', 'source_window_end' => '2026-11-07']]);
        $f191 = $this->makeFinding(191, ['client_id' => 5, 'finding_type' => 'gk_ga4_conversion_rate_decline_30d', 'finding_pillar' => 'business_risk', 'finding_category' => 'Conversion', 'title' => 'GK CVR', 'description' => 'GK CVR', 'status' => 'new', 'evidence_json' => ['source_window_start' => '2026-10-09', 'source_window_end' => '2026-11-07']]);

        $testQuarantineDir = storage_path('app/private/security-quarantine/synthetic-client-99638');
        try {
            // Dry-run mutates nothing
            $exitDry = Artisan::call('forge:closeout-commerce-foundation', ['--dry-run' => true, '--synthetic-client' => 99638, '--as-of' => $refDate]);
            $this->assertSame(0, $exitDry);
            $this->assertFalse((bool) Client::find(99638)->is_internal);
            $this->assertSame(1, CommerceMetric::where('client_id', 99638)->count());
            $this->assertSame(FindingStatus::New, Finding::find(187)->status);

            // Apply mutates safely and preserves audit records
            $exitApply = Artisan::call('forge:closeout-commerce-foundation', ['--apply' => true, '--synthetic-client' => 99638, '--as-of' => $refDate]);
            $this->assertSame(0, $exitApply);

            $this->assertTrue((bool) Client::find(99638)->is_internal);
            $this->assertTrue((bool) (Client::find(99638)->monitoring_config['is_synthetic_fixture'] ?? false));
            $this->assertSame(0, CommerceMetric::where('client_id', 99638)->count());

            // Preserved rows count = 6, no rows deleted
            $this->assertSame(6, Finding::count());
            foreach ([283, 187, 280, 281, 186] as $closedId) {
                $f = Finding::find($closedId);
                $this->assertSame(FindingStatus::Dismissed, $f->status);
                $this->assertSame(FindingOutcomeStatus::Dismissed, $f->outcome_status);
                $this->assertNotEmpty($f->metadata_json['integrity_reconciliation_audit'] ?? []);
                $this->assertGreaterThanOrEqual(1, FindingStatusTransition::where('finding_id', $closedId)->count());
            }

            // #283 hidden from customer visibility; #186 & #281 preserve original observation windows
            $this->assertFalse((bool) Finding::find(283)->is_customer_visible);
            $this->assertSame('2026-10-13', Finding::find(281)->evidence_json['source_window_start']);
            $this->assertSame('2026-10-25', Finding::find(186)->evidence_json['source_window_start']);

            // #191 stays open, routed to analytics without checkout/bot overclaims
            $f191After = Finding::find(191);
            $this->assertSame(FindingStatus::New, $f191After->status);
            $this->assertSame('analytics', $f191After->target_role);
            $this->assertStringContainsString('does not support checkout remediation', $f191After->description);

            // Idempotent second run reports 0 findings planned
            Artisan::call('forge:closeout-commerce-foundation', ['--dry-run' => true, '--synthetic-client' => 99638, '--as-of' => $refDate]);
            $output = Artisan::output();
            $this->assertStringContainsString('"findings_planned": 0', $output);
        } finally {
            if (is_dir($testQuarantineDir)) {
                array_map('unlink', glob("{$testQuarantineDir}/*") ?: []);
                @rmdir($testQuarantineDir);
            }
        }
    }
}
