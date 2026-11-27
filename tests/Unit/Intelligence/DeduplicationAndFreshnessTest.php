<?php

namespace Tests\Unit\Intelligence;

use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Enums\SyncStatus;
use App\Models\BehavioralMetric;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\Finding;
use App\Models\Integration;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Role;
use App\Models\SyncLog;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\Intelligence\DataQualification\FindingFreshnessService;
use App\Services\Intelligence\FindingRuleEvaluator;
use App\Services\Intelligence\OpportunityExplanationService;
use App\Services\PM\Providers\JiraProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeduplicationAndFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name'          => 'Dedup Freshness Client',
            'industry'      => 'Packaging',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
        ]);
    }

    public function test_open_for_deduplication_scope_includes_under_review_approved_and_active_snooze(): void
    {
        $base = [
            'client_id'        => $this->client->id,
            'finding_type'     => 'technical.script_error',
            'source_type'      => 'detector',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
        ];

        $fNew = Finding::create($base + ['fingerprint' => hash('sha256', 'd_new'), 'title' => 'New', 'status' => FindingStatus::New]);
        $fReview = Finding::create($base + ['fingerprint' => hash('sha256', 'd_rev'), 'title' => 'UnderReview', 'status' => FindingStatus::UnderReview]);
        $fApproved = Finding::create($base + ['fingerprint' => hash('sha256', 'd_app'), 'title' => 'Approved', 'status' => FindingStatus::Approved]);
        $fAssigned = Finding::create($base + ['fingerprint' => hash('sha256', 'd_ass'), 'title' => 'Assigned', 'status' => FindingStatus::Assigned]);
        $fSnoozedActive = Finding::create($base + ['fingerprint' => hash('sha256', 'd_snz_active'), 'title' => 'ActiveSnooze', 'status' => FindingStatus::Snoozed, 'snoozed_until' => now()->addDays(5)]);
        $fSnoozedExpired = Finding::create($base + ['fingerprint' => hash('sha256', 'd_snz_exp'), 'title' => 'ExpiredSnooze', 'status' => FindingStatus::Snoozed, 'snoozed_until' => now()->subDay()]);
        $fDismissed = Finding::create($base + ['fingerprint' => hash('sha256', 'd_dis'), 'title' => 'Dismissed', 'status' => FindingStatus::Dismissed]);

        $ids = Finding::openForDeduplication()->where('client_id', $this->client->id)->pluck('id')->all();

        $this->assertContains($fNew->id, $ids);
        $this->assertContains($fReview->id, $ids);
        $this->assertContains($fApproved->id, $ids);
        $this->assertContains($fAssigned->id, $ids);
        $this->assertContains($fSnoozedActive->id, $ids);
        $this->assertNotContains($fDismissed->id, $ids);

        // scopeActive() respects openValues() and excludes snoozed findings until unsnoozed
        $activeIds = Finding::active()->where('client_id', $this->client->id)->pluck('id')->all();
        $this->assertContains($fNew->id, $activeIds);
        $this->assertContains($fReview->id, $activeIds);
        $this->assertNotContains($fSnoozedActive->id, $activeIds);
        $this->assertNotContains($fDismissed->id, $activeIds);
    }

    public function test_rule_evaluator_updates_existing_under_review_finding_instead_of_duplicating(): void
    {
        // Seed GA4 data across prior (days -14..-8) and current (days -7..-1) periods showing CVR decline
        foreach (range(14, 8) as $daysAgo) {
            CommerceMetric::create([
                'client_id'       => $this->client->id,
                'source'          => 'ga4',
                'date'            => now()->subDays($daysAgo)->toDateString(),
                'sessions'        => 1000,
                'orders'          => 50,
                'revenue'         => 5000.00,
                'conversion_rate' => 5.00,
            ]);
        }
        foreach (range(7, 1) as $daysAgo) {
            CommerceMetric::create([
                'client_id'       => $this->client->id,
                'source'          => 'ga4',
                'date'            => now()->subDays($daysAgo)->toDateString(),
                'sessions'        => 1000,
                'orders'          => 10,
                'revenue'         => 1000.00,
                'conversion_rate' => 1.00,
            ]);
        }

        // Pre-existing UnderReview finding for the same rule key
        $existing = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'gk_ga4_conversion_rate_decline_30d',
            'fingerprint'      => 'legacy_rule_fp_123',
            'source_type'      => 'rule',
            'title'            => 'Conversion rate deteriorating',
            'status'           => FindingStatus::UnderReview,
            'finding_pillar'   => FindingPillar::BusinessRisk,
            'finding_category' => FindingCategory::Conversion,
            'severity'         => FindingSeverity::High,
            'metadata_json'    => [
                'rule_key'            => 'gk_ga4_conversion_rate_decline_30d',
                'source_window_start' => now()->subDays(9)->toDateString(),
                'source_window_end'   => now()->subDays(2)->toDateString(),
            ],
        ]);

        $rule = [
            'key'         => 'gk_ga4_conversion_rate_decline_30d',
            'name'        => 'Conversion rate decline rule',
            'metric_a'    => 'ga4.conversion_rate',
            'operator'    => 'percentage_change',
            'threshold'   => -25.0,
            'period_days' => 7,
            'severity'    => 'high',
        ];

        app(FindingRuleEvaluator::class)->evaluateRule($this->client, $rule);

        $this->assertSame(1, Finding::where('client_id', $this->client->id)->where('finding_type', 'gk_ga4_conversion_rate_decline_30d')->count());

        $existing->refresh();
        $this->assertSame(FindingStatus::UnderReview, $existing->status);
        $firstLastObserved = $existing->metadata_json['last_observed_at'] ?? null;
        $this->assertNotEmpty($firstLastObserved);
        $firstHistoryCount = count($existing->metadata_json['observation_history'] ?? []);
        $this->assertGreaterThan(0, $firstHistoryCount);

        // Re-evaluating the exact same source window must NOT advance freshness or bloat observation_history
        app(FindingRuleEvaluator::class)->evaluateRule($this->client, $rule);
        $existing->refresh();
        $this->assertSame($firstLastObserved, $existing->metadata_json['last_observed_at'] ?? null);
        $this->assertCount($firstHistoryCount, $existing->metadata_json['observation_history'] ?? []);
    }

    public function test_jira_provider_suppresses_duplicate_ticket_when_sibling_finding_is_already_linked(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['is_admin' => true]);
        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->first();
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);

        $connection = PmConnection::create([
            'client_id' => $this->client->id,
            'provider'  => 'jira',
            'name'      => 'Jira Test',
            'is_active' => true,
        ]);

        $project = PmProject::create([
            'client_id'            => $this->client->id,
            'pm_connection_id'     => $connection->id,
            'name'                 => 'Technopath Support',
            'external_project_id'  => '10001',
            'external_project_key' => 'TP',
            'is_active'            => true,
        ]);

        $workItem = PmWorkItem::create([
            'client_id'         => $this->client->id,
            'pm_connection_id'  => $connection->id,
            'pm_project_id'     => $project->id,
            'external_item_id'  => '5001',
            'external_item_key' => 'TP-501',
            'summary'           => 'Fix GA4 attribution mismatch',
            'item_type'         => 'Task',
            'external_status'   => 'To Do',
        ]);

        $sharedScopeKey = "{$this->client->id}:attribution.platform_variance:pair:ga4_vs_commerce";

        Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'attribution.platform_variance',
            'fingerprint'      => hash('sha256', 'jira_sib_1'),
            'source_type'      => 'detector',
            'title'            => 'Linked finding',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::UnderReview,
            'pm_work_item_id'  => $workItem->id,
            'metadata_json'    => ['base_scope_key' => $sharedScopeKey],
        ]);

        $approvedSibling = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'attribution.platform_variance',
            'fingerprint'      => hash('sha256', 'jira_sib_2'),
            'source_type'      => 'detector',
            'title'            => 'Duplicate approved sibling',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::Approved,
            'metadata_json'    => ['base_scope_key' => $sharedScopeKey],
        ]);

        $jira = app(JiraProvider::class);
        $returnedItem = $jira->createWorkItem($project, [
            'client_id' => $this->client->id,
            'finding'   => $approvedSibling,
            'title'     => 'Should not create new Jira issue',
        ], $user);

        $this->assertSame($workItem->id, $returnedItem->id);
        $this->assertSame(1, PmWorkItem::where('pm_project_id', $project->id)->count());

        // Consolidated duplicate (even if somehow re-approved) must NOT create a new external task
        $consolidatedDup = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'attribution.platform_variance',
            'fingerprint'      => hash('sha256', 'jira_dup_consol'),
            'source_type'      => 'detector',
            'title'            => 'Consolidated duplicate',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::Dismissed,
            'dismissed_reason' => 'consolidated_duplicate',
            'metadata_json'    => [
                'base_scope_key'           => $sharedScopeKey,
                'superseded_by_finding_id' => $approvedSibling->id + 999, // No linked work item on primary -> throws!
            ],
        ]);

        $blockedExceptionThrown = false;
        try {
            $jira->createWorkItem($project, [
                'client_id' => $this->client->id,
                'finding'   => $consolidatedDup,
                'title'     => 'Should block consolidated duplicate',
            ], $user);
        } catch (\InvalidArgumentException $e) {
            $blockedExceptionThrown = str_contains($e->getMessage(), 'consolidated duplicate');
        }
        $this->assertTrue($blockedExceptionThrown, 'Consolidated duplicates must be blocked from creating independent Jira tasks.');
    }

    public function test_jira_provider_allows_new_task_for_legitimate_future_recurrence_after_prior_episode_closed(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['is_admin' => true]);
        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->first();
        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);

        $connection = PmConnection::create([
            'client_id'          => $this->client->id,
            'provider'           => 'jira',
            'name'               => 'Jira Test',
            'configuration_json' => ['site_url' => 'https://example.atlassian.net', 'access_token' => 'test-token', 'cloud_id' => 'c1'],
            'is_active'          => true,
        ]);
        $project = PmProject::create([
            'client_id'            => $this->client->id,
            'pm_connection_id'     => $connection->id,
            'name'                 => 'Support',
            'external_project_id'  => '10002',
            'external_project_key' => 'SUP',
            'is_active'            => true,
        ]);
        $closedItem = PmWorkItem::create([
            'client_id'         => $this->client->id,
            'pm_connection_id'  => $connection->id,
            'pm_project_id'     => $project->id,
            'external_item_id'  => '6001',
            'external_item_key' => 'SUP-1',
            'summary'           => 'Prior resolved incident',
            'item_type'         => 'Task',
            'external_status'   => 'Done',
        ]);

        $scopeKey = "{$this->client->id}:technical.script_error:sitewide:clarity_script_errors";

        // Prior episode was Resolved with SUP-1 linked
        Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'technical.script_error',
            'fingerprint'      => hash('sha256', 'ep_1'),
            'source_type'      => 'sitewide',
            'title'            => 'Episode 1',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::Resolved,
            'pm_work_item_id'  => $closedItem->id,
            'metadata_json'    => ['base_scope_key' => $scopeKey],
        ]);

        // Future recurrence gets a new episode fingerprint and Approved status -> MUST NOT short-circuit to closed SUP-1!
        $recurrence = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'technical.script_error',
            'fingerprint'      => hash('sha256', 'ep_2_recurrence'),
            'source_type'      => 'sitewide',
            'title'            => 'Episode 2 recurrence',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::Approved,
            'metadata_json'    => ['base_scope_key' => $scopeKey],
        ]);

        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            '*/search*' => \Illuminate\Support\Facades\Http::response(['issues' => []], 200),
            '*/issue'   => \Illuminate\Support\Facades\Http::response([
                'id'  => '6002',
                'key' => 'SUP-2',
            ], 201),
            '*'         => \Illuminate\Support\Facades\Http::response([], 200),
        ]);

        $newWorkItem = app(JiraProvider::class)->createWorkItem($project, [
            'client_id' => $this->client->id,
            'finding'   => $recurrence,
            'title'     => 'Investigate new recurrence ep_2',
        ], $user);

        $this->assertNotSame($closedItem->id, $newWorkItem->id);
        $this->assertSame('SUP-2', $newWorkItem->external_item_key);
    }

    public function test_freshness_service_emits_six_timestamps_and_distinguishes_current_stale_and_historical(): void
    {
        $integration = Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => IntegrationType::GA4,
            'status'           => IntegrationStatus::Active,
        ]);
        SyncLog::create([
            'integration_id'    => $integration->id,
            'status'            => SyncStatus::Success,
            'started_at'        => now()->subHours(2),
            'completed_at'      => now()->subHour(),
            'records_processed' => 30,
        ]);

        CommerceMetric::create([
            'client_id' => $this->client->id,
            'source'    => 'ga4',
            'date'      => now()->subDay()->toDateString(),
            'sessions'  => 500,
            'orders'    => 10,
            'revenue'   => 1200.0,
        ]);

        $service = new FindingFreshnessService();

        // 1. Current finding matching latest source date
        $current = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'ga4_sessions',
            'fingerprint'      => hash('sha256', 'fresh_curr'),
            'source_type'      => 'detector',
            'title'            => 'Current observation',
            'finding_category' => FindingCategory::Revenue,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::New,
            'detected_at'      => now(),
            'evidence_json'    => [
                'evaluated_at'        => now()->toIso8601String(),
                'source_window_start' => now()->subDays(30)->toDateString(),
                'source_window_end'   => now()->subDay()->toDateString(),
                'last_observed_at'    => now()->subDay()->toDateString(),
            ],
        ]);

        $resCurr = $service->evaluateFinding($current);
        $this->assertSame('current', $resCurr['state']);
        $this->assertFalse($resCurr['requires_revalidation']);
        foreach ([
            'first_detected_at',
            'last_observed_at',
            'source_window_start',
            'source_window_end',
            'last_successful_sync_at',
            'evaluated_at',
            'record_updated_at',
        ] as $tsField) {
            $this->assertArrayHasKey($tsField, $resCurr);
            $this->assertNotEmpty($resCurr[$tsField], "Freshness field {$tsField} must be populated");
        }

        // 2. Historical observation (window ended 15 days before latest source date)
        $historical = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'ga4_sessions',
            'fingerprint'      => hash('sha256', 'fresh_hist'),
            'source_type'      => 'detector',
            'title'            => 'Historical observation',
            'finding_category' => FindingCategory::Revenue,
            'severity'         => FindingSeverity::Medium,
            'status'           => FindingStatus::New,
            'detected_at'      => now()->subDays(16),
            'evidence_json'    => [
                'evaluated_at'        => now()->subDays(16)->toIso8601String(),
                'source_window_start' => now()->subDays(45)->toDateString(),
                'source_window_end'   => now()->subDays(16)->toDateString(),
                'last_observed_at'    => now()->subDays(16)->toDateString(),
            ],
        ]);

        $resHist = $service->evaluateFinding($historical);
        $this->assertSame('historical_observation', $resHist['state']);
        $this->assertTrue($resHist['requires_revalidation']);

        // 3. Source stale (Clarity telemetry delayed > 2 days)
        BehavioralMetric::create([
            'client_id'     => $this->client->id,
            'date'          => now()->subDays(6)->toDateString(),
            'traffic'       => 300,
            'script_errors' => 45,
        ]);

        $staleClarity = Finding::create([
            'client_id'        => $this->client->id,
            'finding_type'     => 'technical.script_error',
            'fingerprint'      => hash('sha256', 'fresh_stale'),
            'source_type'      => 'detector',
            'title'            => 'Stale Clarity finding',
            'finding_category' => FindingCategory::Technical,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
            'detected_at'      => now()->subDays(5),
        ]);

        $resStale = $service->evaluateFinding($staleClarity);
        $this->assertSame('source_stale', $resStale['state']);
        $this->assertTrue($resStale['requires_revalidation']);
    }

    public function test_clarity_multiday_explanation_does_not_extrapolate_single_day_affected_pct(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'id'           => 9901,
            'client_id'    => $this->client->id,
            'finding_type' => 'technical.script_error',
            'source_type'  => 'detector',
            'source_id'    => 'clarity_script_errors',
            'title'        => 'Storewide JavaScript error rate increased',
            'status'       => FindingStatus::New,
            'evidence_json'=> [
                'error_count'                      => 17221,
                'monitored_sessions'               => 8010,
                'window_days'                      => 3,
                'affected_sessions_pct_single_day' => 48.2,
                'scope_granularity'                => 'sitewide',
            ],
        ]);

        $explained = app(OpportunityExplanationService::class)->explainFinding($finding);

        $this->assertStringContainsString('8,010', $explained['concise_explanation']);
        $this->assertStringNotContainsString('48.2% of monitored sessions over 3 days', $explained['concise_explanation']);
    }
}
