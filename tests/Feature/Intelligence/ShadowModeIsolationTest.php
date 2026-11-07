<?php

namespace Tests\Feature\Intelligence;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\PmWorkItem;
use App\Services\Intelligence\DataQualification\IntegrationFreshnessTracker;
use App\Services\Intelligence\Replay\ShadowEvaluationRunner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShadowModeIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shadow_mode_execution_guarantees_zero_database_mutations(): void
    {
        $client = Client::create([
            'id' => 5,
            'name' => 'Goldkamp Test',
            'industry' => 'Packaging',
            'platform_type' => 'Magento',
            'status' => ClientStatus::Active,
        ]);

        $initialFindingsCount = Finding::count();
        $initialWorkItemsCount = class_exists(PmWorkItem::class) ? PmWorkItem::count() : 0;
        $initialFindingsIds = Finding::pluck('id')->toArray();

        $from = Carbon::parse('2026-10-24');
        $to = Carbon::parse('2026-11-07');

        // Track every query executed during runner invocation
        $capturedWriteQueries = [];
        DB::listen(function ($query) use (&$capturedWriteQueries) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|truncate)\s+/i', $query->sql)) {
                $capturedWriteQueries[] = [
                    'sql' => $query->sql,
                    'time' => $query->time,
                ];
            }
        });

        $runner = new ShadowEvaluationRunner();
        $report = $runner->replay($client, $from, $to);

        // Verify report generated
        $this->assertEquals('shadow', $report['evaluation_mode']);
        $this->assertNotEmpty($report['synthesis_result']['incidents']);

        // Assert ZERO write queries captured across entire execution
        $this->assertEmpty($capturedWriteQueries, 'Shadow mode must not execute any mutating SQL queries.');

        // Verify ZERO findings added, mutated, or deleted
        $this->assertEquals($initialFindingsCount, Finding::count(), 'Findings table count must remain identical.');
        $postFindingsIds = Finding::pluck('id')->toArray();
        $this->assertEquals($initialFindingsIds, $postFindingsIds, 'Existing finding IDs must remain unaltered.');

        // Verify ZERO work items created
        if (class_exists(PmWorkItem::class)) {
            $this->assertEquals($initialWorkItemsCount, PmWorkItem::count(), 'PM work items must not be created.');
        }

        // Verify report guarantees
        $this->assertEquals(0, $report['isolation_guarantee']['write_queries_detected']);
        $this->assertEquals(0, $report['isolation_guarantee']['production_findings_written']);
        $this->assertEquals(0, $report['isolation_guarantee']['notifications_sent']);
        $this->assertEquals(0, $report['isolation_guarantee']['pm_work_items_created']);
        $this->assertFalse($report['isolation_guarantee']['customer_config_modified']);
        $this->assertEquals('VERIFIED_ZERO_MUTATIONS', $report['isolation_guarantee']['status']);
    }

    public function test_shadow_mode_cannot_modify_finding_statuses_or_customer_configurations(): void
    {
        $client = Client::create([
            'id' => 5,
            'name' => 'Goldkamp Status Test',
            'industry' => 'Packaging',
            'platform_type' => 'Magento',
            'status' => ClientStatus::Active,
        ]);

        $existingFinding = Finding::create([
            'client_id' => $client->id,
            'finding_type' => 'technical_anomaly',
            'finding_category' => \App\Enums\FindingCategory::Technical,
            'title' => 'Pre-existing Unresolved Finding',
            'description' => 'Original description that must not be modified.',
            'severity' => \App\Enums\FindingSeverity::Medium,
            'status' => \App\Enums\FindingStatus::Acknowledged,
            'detected_at' => Carbon::parse('2026-10-20 12:00:00'),
        ]);

        $initialUpdatedAt = $existingFinding->updated_at->toIso8601String();

        $from = Carbon::parse('2026-10-24');
        $to = Carbon::parse('2026-11-07');

        $runner = new ShadowEvaluationRunner();
        $runner->replay($client, $from, $to);

        $freshFinding = $existingFinding->fresh();
        $this->assertEquals(\App\Enums\FindingStatus::Acknowledged, $freshFinding->status, 'Finding status must remain strictly acknowledged and unmutated.');
        $this->assertEquals('Pre-existing Unresolved Finding', $freshFinding->title);
        $this->assertEquals($initialUpdatedAt, $freshFinding->updated_at->toIso8601String(), 'updated_at timestamp must not change.');

        $freshClient = $client->fresh();
        $this->assertEquals(ClientStatus::Active, $freshClient->status);
        $this->assertEquals('Goldkamp Status Test', $freshClient->name);
    }

    public function test_shadow_mode_guarantees_zero_notifications_and_zero_queued_jobs(): void
    {
        Notification::fake();
        Queue::fake();

        $client = Client::create([
            'id' => 5,
            'name' => 'Goldkamp Notification Test',
            'industry' => 'Packaging',
            'platform_type' => 'Magento',
            'status' => ClientStatus::Active,
        ]);

        $from = Carbon::parse('2026-10-24');
        $to = Carbon::parse('2026-11-07');

        $runner = new ShadowEvaluationRunner();
        $report = $runner->replay($client, $from, $to);

        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertEquals(0, $report['isolation_guarantee']['notifications_sent']);
        $this->assertEquals(0, $report['isolation_guarantee']['pm_work_items_created']);
    }

    public function test_shadow_mode_aborts_immediately_if_mutation_attempted(): void
    {
        $client = Client::create([
            'id' => 5,
            'name' => 'Goldkamp Mutation Guard Test',
            'industry' => 'Packaging',
            'platform_type' => 'Magento',
            'status' => ClientStatus::Active,
        ]);

        $from = Carbon::parse('2026-10-24');
        $to = Carbon::parse('2026-11-07');

        // Create a mock synthesizer that illegally attempts to write a finding
        $violatingSynthesizer = new class extends \App\Services\Intelligence\Synthesis\IncidentSynthesizer {
            public function synthesize(int $clientId, array $rawSignals, Carbon $detectedDate, array $existingEpisodes = []): array
            {
                // Illegal write operation inside shadow mode
                DB::table('findings')->insert([
                    'client_id' => $clientId,
                    'finding_type' => 'illegal_write',
                    'finding_category' => 'Technical',
                    'title' => 'Illegal Finding Write',
                    'severity' => 'critical',
                    'status' => 'new',
                    'visibility_classification' => 'internal_only',
                    'is_customer_visible' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return parent::synthesize($clientId, $rawSignals, $detectedDate, $existingEpisodes);
            }
        };

        $runner = new ShadowEvaluationRunner(null, $violatingSynthesizer);

        $mutationCaught = false;
        try {
            $runner->replay($client, $from, $to);
        } catch (\Throwable $e) {
            $mutationCaught = true;
            // Either the database engine rejected the write directly (SQLSTATE 25006 on MySQL, or readonly on SQLite)
            // or the DB::listen query guard caught it
            $this->assertTrue(
                str_contains($e->getMessage(), 'readonly') ||
                str_contains($e->getMessage(), 'READ ONLY') ||
                str_contains($e->getMessage(), 'Shadow Mode Isolation Violation'),
                'Exception must be engine-level read-only or query-listener isolation violation. Got: ' . $e->getMessage()
            );
        }

        $this->assertTrue($mutationCaught, 'Mutating query must cause immediate abort.');
    }

    public function test_integration_freshness_tracker_evaluates_timezone_and_business_day_completion(): void
    {
        $client = Client::create([
            'id' => 5,
            'name' => 'Goldkamp Timezone Test',
            'industry' => 'Packaging',
            'platform_type' => 'Magento',
            'timezone' => 'America/New_York',
            'status' => ClientStatus::Active,
        ]);

        $tracker = new IntegrationFreshnessTracker();

        // Simulate 02:30 UTC: in New York, it is 21:30 (9:30 PM) on previous calendar day -> NOT complete
        $earlyUtc = Carbon::parse('2026-11-07 02:30:00', 'UTC');
        $freshnessEarly = $tracker->evaluateFreshness($client, '2026-11-06', $earlyUtc);

        $this->assertFalse($freshnessEarly['is_business_day_complete']);
        $this->assertStringContainsString('has not yet completed in client timezone', $freshnessEarly['gating_rationale']);

        // Simulate 06:00 UTC: in New York, it is 01:00 AM on next day -> 100% complete
        $lateUtc = Carbon::parse('2026-11-07 06:00:00', 'UTC');
        $freshnessLate = $tracker->evaluateFreshness($client, '2026-11-06', $lateUtc);

        $this->assertTrue($freshnessLate['is_business_day_complete']);
        $this->assertNull($freshnessLate['gating_rationale']);
    }

    public function test_14_day_parallel_observation_completes_with_zero_missed_issues(): void
    {
        $client = Client::create([
            'id' => 5,
            'name' => 'Goldkamp Observation Test',
            'industry' => 'Packaging',
            'platform_type' => 'Magento',
            'timezone' => 'America/New_York',
            'status' => ClientStatus::Active,
        ]);

        $runner = new ShadowEvaluationRunner();
        $start = Carbon::parse('2026-10-25');
        $end = Carbon::parse('2026-11-07');

        $result = $runner->run14DayParallelObservation($client, $start, $end);

        $this->assertEquals(14, $result['evaluation_period']['total_consecutive_days']);
        $this->assertEquals(0, $result['totals']['potential_missed_problems']);
        $this->assertEquals('COMPLETE_ISOLATION_VERIFIED', $result['isolation_audit']['status']);
        $this->assertEquals(0, $result['isolation_audit']['mutating_queries_executed']);
        $this->assertEquals(0, $result['isolation_audit']['production_tables_modified']);
    }
}
