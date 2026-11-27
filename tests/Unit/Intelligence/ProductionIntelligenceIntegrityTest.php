<?php

namespace Tests\Unit\Intelligence;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\Finding;
use App\Services\Intelligence\ChangeDetectionEngine;
use App\Services\Intelligence\FindingMetricRegistry;
use App\Services\Intelligence\Replay\ShadowEvaluationRunner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionIntelligenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_runtime_directories_contain_no_prohibited_demo_literals(): void
    {
        $prohibitedTokens = [
            '4658.47',
            'packaging-tapes.html',
            'www.tgoldkamp.com',
            'Verified concordant procurement contact',
            'missing UTM tags',
        ];

        $strictNoStubTokens = [
            '216.5',
            'tapes_packaging_tapes',
            'cart_item_options',
        ];

        $scanDirs = [
            app_path('Services'),
            app_path('Filament'),
            app_path('Models'),
            app_path('Jobs'),
            app_path('Http'),
        ];

        foreach ($scanDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                // Skip offline historical calibration comparison tables (non-production audit baselines)
                if (str_contains($path, DIRECTORY_SEPARATOR . 'Replay' . DIRECTORY_SEPARATOR . 'Historical')) {
                    continue;
                }
                $contents = file_get_contents($path);
                $this->assertNotFalse($contents, "Failed reading {$path}");

                foreach ($prohibitedTokens as $token) {
                    $this->assertStringNotContainsString(
                        $token,
                        $contents,
                        sprintf('Prohibited hardcoded demo token "%s" found in production file %s', $token, $path)
                    );
                }

                // Runtime generators & connectors must not reference legacy stub identifiers at all
                $isGeneratorOrConnector = str_contains($path, DIRECTORY_SEPARATOR . 'Detectors' . DIRECTORY_SEPARATOR)
                    || str_contains($path, DIRECTORY_SEPARATOR . 'Connectors' . DIRECTORY_SEPARATOR)
                    || str_ends_with($path, 'ShadowEvaluationRunner.php')
                    || str_ends_with($path, 'FindingRuleEvaluator.php');
                if ($isGeneratorOrConnector) {
                    foreach ($strictNoStubTokens as $stubToken) {
                        $this->assertStringNotContainsString(
                            $stubToken,
                            $contents,
                            sprintf('Legacy stub token "%s" must not exist in generator/connector %s', $stubToken, $path)
                        );
                    }
                }

                // Catch malformed non-variable \${...} template artifacts while allowing valid PHP "\${$var}"
                $this->assertDoesNotMatchRegularExpression(
                    '/\\\\\\$\\{(?!\\$)/',
                    $contents,
                    sprintf('Malformed literal \\${...} currency template found in %s', $path)
                );
            }
        }
    }

    public function test_empty_client_emits_zero_findings_and_zero_shadow_incidents(): void
    {
        $client = Client::create([
            'name'          => 'Empty Telemetry Client',
            'industry'      => 'Distribution',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
        ]);

        $created = app(ChangeDetectionEngine::class)->run($client);

        $this->assertSame(0, $created);
        $this->assertSame(0, Finding::where('client_id', $client->id)->count());

        $report = (new ShadowEvaluationRunner())->replay(
            $client,
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-07')
        );

        $this->assertSame([], $report['synthesis_result']['incidents'] ?? []);
    }

    public function test_weighted_conversion_rate_prevents_unweighted_daily_average_distortion(): void
    {
        $client = Client::create([
            'name'          => 'Weighted CVR Client',
            'industry'      => 'Packaging',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
        ]);

        // Day 1: small session count, artificially high conversion rate (10 / 100 = 10.0%)
        CommerceMetric::create([
            'client_id'       => $client->id,
            'source'          => 'ga4',
            'date'            => '2026-11-10',
            'sessions'        => 100,
            'orders'          => 10,
            'revenue'         => 1000.00,
            'conversion_rate' => 10.00,
        ]);

        // Day 2: huge traffic surge, low conversion rate (10 / 9900 = 0.101%)
        CommerceMetric::create([
            'client_id'       => $client->id,
            'source'          => 'ga4',
            'date'            => '2026-11-11',
            'sessions'        => 9900,
            'orders'          => 10,
            'revenue'         => 1000.00,
            'conversion_rate' => 0.10,
        ]);

        $registry = new FindingMetricRegistry();
        $weightedCvr = $registry->resolveMetric(
            $client,
            'ga4.conversion_rate',
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-11')
        );

        // True weighted CVR = (10 + 10) / (100 + 9900) * 100 = 0.20%, NOT (10.0 + 0.10) / 2 = 5.05%
        $this->assertEquals(0.20, $weightedCvr);
    }
}
