<?php

namespace Tests\Feature\Intelligence;

use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\ClientFindingConfiguration;
use App\Models\CommerceMetric;
use App\Models\EmailMarketingMetric;
use App\Models\Finding;
use App\Models\Integration;
use App\Services\Intelligence\AIAnalyst;
use App\Services\Intelligence\ChangeDetectionEngine;
use App\Services\Intelligence\FindingConfigurationService;
use App\Services\Intelligence\FindingConfigurationValidator;
use App\Services\Intelligence\FindingMetricRegistry;
use App\Services\Intelligence\FindingRuleEvaluator;
use App\Services\MeetingAgent\AiProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientFindingRulesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name'          => 'Test Retailer',
            'industry'      => 'apparel',
            'platform_type' => 'Shopify',
            'status'        => ClientStatus::Active,
        ]);

        // Connect GA4 and Klaviyo integrations
        Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => 'ga4',
            'status'           => 'active',
            'config_json'      => ['property_id' => '123456'],
        ]);

        Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => 'klaviyo',
            'status'           => 'active',
            'config_json'      => ['api_key' => 'pk_test_123'],
        ]);
    }

    private function createKlaviyoMetric(float $revenue, int $daysAgo = 5): EmailMarketingMetric
    {
        return EmailMarketingMetric::create([
            'client_id' => $this->client->id,
            'source'    => 'klaviyo',
            'type'      => 'campaign',
            'channel'   => 'email',
            'date'      => now()->subDays($daysAgo)->toDateString(),
            'revenue'   => $revenue,
        ]);
    }

    private function createGa4Metric(float $totalRevenue, float $emailRevenue, int $daysAgo = 5): CommerceMetric
    {
        return CommerceMetric::create([
            'client_id'             => $this->client->id,
            'source'                => 'ga4',
            'date'                  => now()->subDays($daysAgo)->toDateString(),
            'revenue'               => $totalRevenue,
            'source_breakdown_json' => [
                'email' => ['revenue' => $emailRevenue],
            ],
        ]);
    }

    /** 1. Valid JSON configuration uploads and validates successfully */
    public function test_valid_json_configuration_uploads_and_validates_successfully(): void
    {
        $service = new FindingConfigurationService();
        $template = $service->generateTemplate($this->client);

        $validator = new FindingConfigurationValidator();
        $result = $validator->validate($this->client, $template);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
        $this->assertNotNull($result['validated_data']);
        $this->assertSame(1, count($result['validated_data']['rules']));
    }

    /** 2. Upload rejects unknown metric identifiers */
    public function test_upload_rejects_unknown_metric_identifiers(): void
    {
        $service = new FindingConfigurationService();
        $template = $service->generateTemplate($this->client);
        $template['rules'][0]['metric_b'] = 'mailchimp.revenue'; // Unknown metric

        $validator = new FindingConfigurationValidator();
        $result = $validator->validate($this->client, $template);

        $this->assertFalse($result['is_valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('mailchimp.revenue', $result['errors'][0]);
    }

    /** 3. Upload rejects unknown operators */
    public function test_upload_rejects_unknown_operators(): void
    {
        $service = new FindingConfigurationService();
        $template = $service->generateTemplate($this->client);
        $template['rules'][0]['operator'] = 'equals_approximately';

        $validator = new FindingConfigurationValidator();
        $result = $validator->validate($this->client, $template);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('operator "equals_approximately" is invalid', $result['errors'][0]);
    }

    /** 4. Upload rejects invalid thresholds or periods */
    public function test_upload_rejects_invalid_thresholds_or_periods(): void
    {
        $service = new FindingConfigurationService();
        $template = $service->generateTemplate($this->client);
        $template['rules'][0]['period_days'] = 45; // Invalid period (only 7, 14, 30, 60, 90 allowed)
        $template['rules'][0]['threshold']   = 'not_a_number';

        $validator = new FindingConfigurationValidator();
        $result = $validator->validate($this->client, $template);

        $this->assertFalse($result['is_valid']);
        $errorStr = implode(' ', $result['errors']);
        $this->assertStringContainsString('period_days "45" is invalid', $errorStr);
        $this->assertStringContainsString('"threshold" must be a numeric value', $errorStr);
    }

    /** 5. Upload rejects references to integrations the client does not have */
    public function test_upload_rejects_references_to_integrations_the_client_does_not_have(): void
    {
        // Client without Adobe Commerce integration or platform
        $otherClient = Client::create([
            'name'          => 'Client Without Adobe',
            'industry'      => 'beauty',
            'platform_type' => 'Custom',
            'status'        => ClientStatus::Active,
        ]);

        $template = [
            'schema_version' => '1.0',
            'client'         => ['id' => $otherClient->id, 'name' => $otherClient->name],
            'rules'          => [
                [
                    'key'         => 'adobe_drop',
                    'name'        => 'Adobe Revenue Drop',
                    'enabled'     => true,
                    'metric_a'    => 'adobe_commerce.revenue',
                    'operator'    => 'percentage_change',
                    'threshold'   => -20,
                    'period_days' => 30,
                    'severity'    => 'high',
                ],
            ],
        ];

        $validator = new FindingConfigurationValidator();
        $result = $validator->validate($otherClient, $template);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('adobe_commerce.revenue" is not available for this client', $result['errors'][0]);
    }

    /** 6. GA4 vs Klaviyo rule triggers finding when variance exceeds threshold */
    public function test_ga4_vs_klaviyo_rule_triggers_finding_when_variance_exceeds_threshold(): void
    {
        // Populate Klaviyo revenue: $100,000
        $this->createKlaviyoMetric(100000.00, 5);

        // Populate GA4 email revenue: $85,000 (Variance = 15%, Threshold = 10%)
        $this->createGa4Metric(200000.00, 85000.00, 5);

        // Activate config with 10% threshold
        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => (new FindingConfigurationService())->generateTemplate($this->client),
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $evaluator = new FindingRuleEvaluator();
        $count = $evaluator->evaluate($this->client);

        $this->assertSame(1, $count);

        $finding = Finding::where('client_id', $this->client->id)->first();
        $this->assertNotNull($finding);
        $this->assertSame('ga4_klaviyo_attribution_variance', $finding->finding_type);
        $this->assertSame('high', is_object($finding->severity) ? $finding->severity->value : $finding->severity);

        // Verify structured evidence
        $evidence = $finding->metadata_json;
        $this->assertIsArray($evidence);
        $this->assertSame('ga4_klaviyo_attribution_variance', $evidence['rule_key']);
        $this->assertSame(85000.0, (float) $evidence['metric_a']['value']);
        $this->assertSame(100000.0, (float) $evidence['metric_b']['value']);
        $this->assertSame(15000.0, (float) $evidence['difference_amount']);
        $this->assertSame(15.0, (float) $evidence['difference_pct']);
        $this->assertSame(10, $evidence['threshold']);
        $this->assertSame(30, $evidence['period_days']);
        $this->assertTrue($evidence['quality_gates_passed']);
    }

    /** 7. GA4 vs Klaviyo rule does NOT trigger when variance is within threshold */
    public function test_ga4_vs_klaviyo_rule_does_not_trigger_when_variance_is_within_threshold(): void
    {
        // Klaviyo: $100,000
        $this->createKlaviyoMetric(100000.00, 5);

        // GA4 Email: $95,000 (Variance = 5%, Threshold = 10%)
        $this->createGa4Metric(150000.00, 95000.00, 5);

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => (new FindingConfigurationService())->generateTemplate($this->client),
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $evaluator = new FindingRuleEvaluator();
        $count = $evaluator->evaluate($this->client);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('findings', ['client_id' => $this->client->id]);
    }

    /** 8. Custom threshold values are respected */
    public function test_custom_threshold_values_are_respected(): void
    {
        // Klaviyo: $100,000, GA4 Email: $85,000 -> 15% variance
        $this->createKlaviyoMetric(100000.00, 5);
        $this->createGa4Metric(150000.00, 85000.00, 5);

        $template = (new FindingConfigurationService())->generateTemplate($this->client);
        $template['rules'][0]['threshold'] = 20; // 20% custom threshold

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => $template,
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $evaluator = new FindingRuleEvaluator();

        // 15% variance does NOT trigger a 20% threshold
        $count = $evaluator->evaluate($this->client);
        $this->assertSame(0, $count);

        // Lower threshold to 12%: now it triggers!
        $template['rules'][0]['threshold'] = 12;
        $active = ClientFindingConfiguration::where('client_id', $this->client->id)->first();
        $active->update(['configuration_json' => $template]);

        $count = $evaluator->evaluate($this->client);
        $this->assertSame(1, $count);
    }

    /** 9. Minimum revenue quality gate prevents false alarms */
    public function test_minimum_revenue_quality_gate_prevents_false_alarms(): void
    {
        // Total revenue is only $420 (below $1,000 gate), even though variance is 50%
        $this->createKlaviyoMetric(400.00, 3);
        $this->createGa4Metric(420.00, 200.00, 3);

        $template = (new FindingConfigurationService())->generateTemplate($this->client);
        $template['rules'][0]['quality_gates'] = ['minimum_revenue' => 1000];

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => $template,
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $evaluator = new FindingRuleEvaluator();
        $count = $evaluator->evaluate($this->client);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('findings', ['client_id' => $this->client->id]);
    }

    /** 10. Minimum revenue gate allows evaluation when threshold met */
    public function test_minimum_revenue_gate_allows_evaluation_when_threshold_met(): void
    {
        // Revenue is $5,000 (exceeds $1,000 gate)
        $this->createKlaviyoMetric(5000.00, 3);
        $this->createGa4Metric(5000.00, 2500.00, 3);

        $template = (new FindingConfigurationService())->generateTemplate($this->client);
        $template['rules'][0]['quality_gates'] = ['minimum_revenue' => 1000];

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => $template,
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $evaluator = new FindingRuleEvaluator();
        $count = $evaluator->evaluate($this->client);

        $this->assertSame(1, $count);
    }

    /** 11. Disabled rules do not evaluate */
    public function test_disabled_rules_do_not_evaluate(): void
    {
        $this->createKlaviyoMetric(10000.00, 3);
        $this->createGa4Metric(10000.00, 1000.00, 3);

        $template = (new FindingConfigurationService())->generateTemplate($this->client);
        $template['rules'][0]['enabled'] = false; // Disabled rule

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => $template,
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $evaluator = new FindingRuleEvaluator();
        $count = $evaluator->evaluate($this->client);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('findings', ['client_id' => $this->client->id]);
    }

    /** 12. Finding configuration versions increment properly */
    public function test_finding_configuration_versions_increment_properly(): void
    {
        $v1 = ClientFindingConfiguration::nextVersionForClient($this->client->id);
        $this->assertSame(1, $v1);

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => $v1,
            'configuration_json' => ['rules' => []],
            'rules_count'        => 0,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
        ]);

        $v2 = ClientFindingConfiguration::nextVersionForClient($this->client->id);
        $this->assertSame(2, $v2);

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => $v2,
            'configuration_json' => ['rules' => []],
            'rules_count'        => 0,
            'status'             => ClientFindingConfiguration::STATUS_DRAFT,
        ]);

        $v3 = ClientFindingConfiguration::nextVersionForClient($this->client->id);
        $this->assertSame(3, $v3);
    }

    /** 13. Finding configuration rollback restores previous version */
    public function test_finding_configuration_rollback_restores_previous_version(): void
    {
        $v1 = ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => ['rules' => [['key' => 'rule_v1']]],
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now()->subDays(2),
        ]);

        $v2 = ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 2,
            'configuration_json' => ['rules' => [['key' => 'rule_v2']]],
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_DRAFT,
        ]);

        // Activate v2 -> v1 should become archived
        $v2->activate();
        $this->assertSame(ClientFindingConfiguration::STATUS_ACTIVE, $v2->fresh()->status);
        $this->assertSame(ClientFindingConfiguration::STATUS_ARCHIVED, $v1->fresh()->status);

        // Rollback from v2 -> v2 archived, v1 reactivated
        $rolledBackTo = $v2->rollback();
        $this->assertNotNull($rolledBackTo);
        $this->assertSame(1, $rolledBackTo->version);
        $this->assertSame(ClientFindingConfiguration::STATUS_ACTIVE, $v1->fresh()->status);
        $this->assertSame(ClientFindingConfiguration::STATUS_ARCHIVED, $v2->fresh()->status);
    }

    /** 14. Finding deduplication works */
    public function test_finding_deduplication_works(): void
    {
        $this->createKlaviyoMetric(100000.00, 2);
        $this->createGa4Metric(100000.00, 50000.00, 2);

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => (new FindingConfigurationService())->generateTemplate($this->client),
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $evaluator = new FindingRuleEvaluator();

        // First run creates 1 finding
        $firstRun = $evaluator->evaluate($this->client);
        $this->assertSame(1, $firstRun);
        $this->assertSame(1, Finding::where('client_id', $this->client->id)->count());

        // Second run with open finding should NOT duplicate
        $secondRun = $evaluator->evaluate($this->client);
        $this->assertSame(0, $secondRun);
        $this->assertSame(1, Finding::where('client_id', $this->client->id)->count());
    }

    /** 15. ChangeDetectionEngine orchestration evaluates custom rules */
    public function test_change_detection_engine_orchestrates_custom_rules(): void
    {
        $this->createKlaviyoMetric(100000.00, 2);
        $this->createGa4Metric(100000.00, 50000.00, 2);

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => (new FindingConfigurationService())->generateTemplate($this->client),
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $engine = new ChangeDetectionEngine();
        $totalCreated = $engine->run($this->client);

        $this->assertGreaterThanOrEqual(1, $totalCreated);
        $this->assertTrue(Finding::where('client_id', $this->client->id)->where('finding_type', 'ga4_klaviyo_attribution_variance')->exists());
    }

    /** 16. AIAnalyst receives structured evidence when interpreting finding */
    public function test_aianalyst_receives_structured_evidence_when_interpreting_finding(): void
    {
        $evidence = [
            'rule_key'             => 'ga4_klaviyo_attribution_variance',
            'metric_a'             => ['key' => 'ga4.email_revenue', 'value' => 85000],
            'metric_b'             => ['key' => 'klaviyo.attributed_revenue', 'value' => 100000],
            'difference_amount'    => 15000,
            'difference_pct'       => 15,
            'threshold'            => 10,
            'period_days'          => 30,
            'quality_gates_passed' => true,
        ];

        $finding = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'ga4_klaviyo_attribution_variance',
            'finding_category' => FindingCategory::Revenue,
            'title'            => 'GA4 vs Klaviyo Attribution Variance (15% Discrepancy)',
            'description'      => 'Attribution variance between GA4 and Klaviyo exceeded threshold.',
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
            'metadata_json'    => $evidence,
            'detected_at'      => now(),
        ]);

        $mockAiProvider = $this->createMock(AiProviderService::class);
        $mockAiProvider->expects($this->once())
            ->method('completeJson')
            ->with($this->anything(), $this->callback(function (string $prompt) {
                // Assert prompt contains the structured evidence
                return str_contains($prompt, 'ga4_klaviyo_attribution_variance')
                    && str_contains($prompt, '85000')
                    && str_contains($prompt, '100000')
                    && str_contains($prompt, 'difference_pct')
                    && str_contains($prompt, 'OBSERVED FACTS');
            }))
            ->willReturn([
                'investigation_report' => 'Observed attribution discrepancy of 15% between Klaviyo ($100k) and GA4 ($85k).',
                'data_evidence'        => '1. Klaviyo attributed $100k, GA4 recorded $85k.',
                'conclusion'           => 'Align attribution windows or review UTM tracking parameters.',
            ]);

        $mockAiProvider->expects($this->any())
            ->method('getModelName')
            ->willReturn('openai/gpt-4o');

        $analyst = new AIAnalyst($mockAiProvider);
        $recommendation = $analyst->analyseWithContext($finding, [
            'commerce'        => [],
            'behavioral'      => [],
            'performance'     => [],
            'email_marketing' => [],
            'deployments'     => [],
            'data_sources'    => [],
            'server_logs'     => [],
        ]);

        $this->assertNotNull($recommendation);
        $this->assertSame($finding->id, $recommendation->finding_id);
    }

    /** 17. Disabling built-in detectors skips built-in checks and only evaluates custom rules */
    public function test_disabling_builtin_detectors_only_runs_custom_finding_rules(): void
    {
        // Disable built-in detectors for client
        $this->client->update([
            'monitoring_config' => [
                'disable_builtin_detectors' => true,
            ],
        ]);

        $this->assertFalse($this->client->builtinDetectorsEnabled());

        // Create data that would normally trigger a severe built-in revenue drop:
        // Prior 7 days: $50,000/day
        for ($i = 8; $i <= 14; $i++) {
            CommerceMetric::create([
                'client_id' => $this->client->id,
                'source'    => 'ga4',
                'date'      => now()->subDays($i)->toDateString(),
                'revenue'   => 50000.00,
            ]);
        }
        // Current 7 days: $1,000/day (a 98% drop)
        for ($i = 1; $i <= 7; $i++) {
            CommerceMetric::create([
                'client_id' => $this->client->id,
                'source'    => 'ga4',
                'date'      => now()->subDays($i)->toDateString(),
                'revenue'   => 1000.00,
                'source_breakdown_json' => [
                    'email' => ['revenue' => 500.00],
                ],
            ]);
        }

        // Also create Klaviyo metric that triggers the custom attribution variance rule ($10,000 vs $500)
        $this->createKlaviyoMetric(10000.00, 3);

        // Activate custom finding config
        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => (new FindingConfigurationService())->generateTemplate($this->client),
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $engine = new ChangeDetectionEngine();
        $engine->run($this->client);

        // Assert that NO built-in revenue_decrease finding was generated
        $this->assertDatabaseMissing('findings', [
            'client_id'    => $this->client->id,
            'finding_type' => 'revenue_decrease',
        ]);

        // Assert that the custom attribution rule STILL triggered
        $this->assertDatabaseHas('findings', [
            'client_id'    => $this->client->id,
            'finding_type' => 'ga4_klaviyo_attribution_variance',
        ]);
    }

    /** 18. Built-in detectors are enabled by default */
    public function test_builtin_detectors_are_enabled_by_default(): void
    {
        $this->assertTrue($this->client->builtinDetectorsEnabled());

        $this->client->update(['monitoring_config' => []]);
        $this->assertTrue($this->client->fresh()->builtinDetectorsEnabled());
    }

    /** 19. Run analysis queues GenerateAIAnalysis job to background */
    public function test_run_analysis_queues_generate_ai_analysis_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->createKlaviyoMetric(100000.00, 2);
        $this->createGa4Metric(100000.00, 50000.00, 2);

        ClientFindingConfiguration::create([
            'client_id'          => $this->client->id,
            'version'            => 1,
            'configuration_json' => (new FindingConfigurationService())->generateTemplate($this->client),
            'rules_count'        => 1,
            'status'             => ClientFindingConfiguration::STATUS_ACTIVE,
            'activated_at'       => now(),
        ]);

        $engine = new ChangeDetectionEngine();
        $engine->run($this->client);

        $pendingFindings = $this->client->findings()
            ->whereDoesntHave('recommendations')
            ->where('detected_at', '>=', now()->subHours(1))
            ->get();

        $this->assertTrue($pendingFindings->isNotEmpty());

        $pendingFindings->each(fn ($finding) => \App\Jobs\Intelligence\GenerateAIAnalysis::dispatch($finding, 'openai/gpt-4o'));

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Intelligence\GenerateAIAnalysis::class);
    }
}

