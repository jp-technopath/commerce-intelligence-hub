<?php

namespace Tests\Feature\Intelligence;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\FindingStatusTransition;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\PM\FindingPmSyncService;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PmWorkItemExecutionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Client $clientA;
    protected Client $clientB;
    protected User $admin;
    protected PmConnection $connection;
    protected PmProject $projectA;
    protected PmProject $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientA = Client::create([
            'name'              => 'Cambro Manufacturing',
            'status'            => 'active',
            'jira_project_key'  => 'CAMB',
        ]);

        $this->clientB = Client::create([
            'name'              => 'Goldkamp Equipment',
            'status'            => 'active',
            'jira_project_key'  => 'GOLD',
        ]);

        $this->admin = User::factory()->create([
            'name'     => 'Sarah Admin',
            'email'    => 'sarah.admin@technopath.test',
            'is_admin' => true,
        ]);

        $this->connection = PmConnection::create([
            'client_id'          => $this->clientA->id,
            'provider'           => 'jira',
            'name'               => 'Corporate Jira Integration',
            'is_active'          => true,
            'configuration_json' => [
                'base_url'  => 'https://technopath.atlassian.net',
                'email'     => 'bot@technopath.io',
                'api_token' => 'mock-api-token',
            ],
        ]);

        $this->projectA = PmProject::create([
            'client_id'            => $this->clientA->id,
            'pm_connection_id'     => $this->connection->id,
            'name'                 => 'Cambro Commerce Portal',
            'external_project_id'  => '10010',
            'external_project_key' => 'CAMB',
            'is_active'            => true,
        ]);

        $this->projectB = PmProject::create([
            'client_id'            => $this->clientB->id,
            'pm_connection_id'     => $this->connection->id,
            'name'                 => 'Goldkamp Operations',
            'external_project_id'  => '10020',
            'external_project_key' => 'GOLD',
            'is_active'            => true,
        ]);
    }

    protected function createFinding(array $attributes = []): Finding
    {
        return Finding::create(array_merge([
            'client_id'                => $this->clientA->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', uniqid('finding_', true)),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.wholesale_retention',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => 'Revenue',
            'severity'                 => 'high',
            'status'                   => FindingStatus::New->value,
            'execution_status'         => FindingExecutionStatus::New->value,
            'outcome_status'           => FindingOutcomeStatus::PendingExecution->value,
            'title'                    => 'Test Opportunity',
            'description'              => 'Test description',
            'estimated_revenue_impact' => 5000.00,
        ], $attributes));
    }

    public function test_cannot_create_pm_work_item_for_unapproved_finding(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Unapproved Account Outreach',
            'status'                    => FindingStatus::New->value,
            'execution_status'          => FindingExecutionStatus::New->value,
            'estimated_revenue_impact'  => 12500.00,
        ]);

        $jiraProvider = app(JiraProvider::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/approved/i');

        $jiraProvider->createWorkItem($this->projectA, [
            'finding' => $finding,
            'title'   => 'Outreach Task',
        ], $this->admin);
    }

    public function test_creates_jira_work_item_with_idempotency_and_links_to_finding(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Approved Dormant Account Reactivation',
            'status'                    => FindingStatus::Approved->value,
            'execution_status'          => FindingExecutionStatus::Approved->value,
            'estimated_revenue_impact'  => 8500.00,
            'target_role'               => 'sales',
        ]);

        Http::fake([
            'https://technopath.atlassian.net/rest/api/3/issue' => Http::response([
                'id'   => '99101',
                'key'  => 'CAMB-404',
                'self' => 'https://technopath.atlassian.net/rest/api/3/issue/99101',
            ], 201),
        ]);

        $jiraProvider = app(JiraProvider::class);

        $item = $jiraProvider->createWorkItem($this->projectA, [
            'finding'     => $finding,
            'title'       => 'Re-engage Cambro Premier Account',
            'description' => 'Target reorder value: $8,500.',
            'priority'    => 'High',
            'issue_type'  => 'Task',
        ], $this->admin);

        $this->assertInstanceOf(PmWorkItem::class, $item);
        $this->assertEquals('CAMB-404', $item->external_item_key);
        $this->assertEquals($this->clientA->id, $item->client_id);
        $this->assertEquals($this->projectA->id, $item->pm_project_id);

        $finding->refresh();
        $this->assertEquals($item->id, $finding->pm_work_item_id);
        $this->assertNotNull($finding->pm_idempotency_token);
        $this->assertEquals("forge_finding_{$finding->id}_{$this->projectA->id}", $finding->pm_idempotency_token);
    }

    public function test_idempotency_prevents_duplicate_jira_issues_on_retry(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Double Submit Test Finding',
            'status'                    => FindingStatus::Approved->value,
            'execution_status'          => FindingExecutionStatus::Approved->value,
            'estimated_revenue_impact'  => 5000.00,
        ]);

        $callCount = 0;
        Http::fake([
            'https://technopath.atlassian.net/rest/api/3/issue' => function () use (&$callCount) {
                $callCount++;
                return Http::response([
                    'id'   => '99102',
                    'key'  => 'CAMB-405',
                    'self' => 'https://technopath.atlassian.net/rest/api/3/issue/99102',
                ], 201);
            },
        ]);

        $jiraProvider = app(JiraProvider::class);

        // First attempt creates issue
        $item1 = $jiraProvider->createWorkItem($this->projectA, [
            'finding' => $finding,
            'title'   => 'Reactivation Task',
        ], $this->admin);

        $this->assertEquals(1, $callCount);
        $this->assertEquals('CAMB-405', $item1->external_item_key);

        // Second attempt with same finding reuses idempotency token and does NOT make another HTTP call
        $item2 = $jiraProvider->createWorkItem($this->projectA, [
            'finding' => $finding,
            'title'   => 'Reactivation Task Retry',
        ], $this->admin);

        $this->assertEquals(1, $callCount, 'HTTP call was not made on duplicate execution due to idempotency token.');
        $this->assertEquals($item1->id, $item2->id);
    }

    public function test_jira_immediate_post_timeout_reconciliation_heals_when_jira_created_issue(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Immediate Healing Finding',
            'status'                    => FindingStatus::Approved->value,
            'execution_status'          => FindingExecutionStatus::Approved->value,
            'estimated_revenue_impact'  => 8500.00,
        ]);

        $postIssueCallCount = 0;
        $jqlSearchCallCount = 0;

        // Mock sequence:
        // 1. Initial pre-check: Search returns empty
        // 2. POST /issue: times out (ConnectionException)
        // 3. Immediate post-timeout reconciliation: Search finds the issue committed by Jira
        \Illuminate\Support\Facades\Http::fake([
            'https://technopath.atlassian.net/rest/api/3/search/jql' => function ($request) use (&$jqlSearchCallCount, $finding) {
                $jqlSearchCallCount++;
                if ($jqlSearchCallCount <= 2) {
                    return \Illuminate\Support\Facades\Http::response(['issues' => []], 200);
                }

                return \Illuminate\Support\Facades\Http::response([
                    'issues' => [
                        [
                            'id'     => '99881',
                            'key'    => 'CAMB-9988',
                            'fields' => [
                                'summary' => 'Immediate Healing Finding',
                                'status'  => ['name' => 'To Do'],
                                'labels'  => ['forge', "forge-finding-{$finding->id}"],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://technopath.atlassian.net/rest/api/3/issue' => function () use (&$postIssueCallCount) {
                $postIssueCallCount++;
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds');
            },
        ]);

        $jiraProvider = app(JiraProvider::class);

        $item = $jiraProvider->createWorkItem($this->projectA, [
            'finding' => $finding,
            'title'   => 'Immediate Healing Finding',
        ], $this->admin);

        $this->assertInstanceOf(PmWorkItem::class, $item);
        $this->assertEquals('CAMB-9988', $item->external_item_key);
        $this->assertEquals(1, $postIssueCallCount);
        $finding->refresh();
        $this->assertEquals($item->id, $finding->pm_work_item_id);
    }

    public function test_jira_delayed_search_indexing_and_ambiguous_timeout_prevents_duplicate_issue(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Delayed Indexing Finding',
            'status'                    => FindingStatus::Approved->value,
            'execution_status'          => FindingExecutionStatus::Approved->value,
            'estimated_revenue_impact'  => 6200.00,
        ]);

        $postIssueCallCount = 0;
        $searchCallCount = 0;
        $searchMode = 'lagging'; // 'lagging', 'matching_summary_without_label', 'indexed_with_label'

        \Illuminate\Support\Facades\Http::fake([
            'https://technopath.atlassian.net/rest/api/3/search/jql' => function ($request) use (&$searchCallCount, &$searchMode, $finding) {
                $searchCallCount++;
                if ($searchMode === 'lagging') {
                    // Jira indexer is lagging; returns empty
                    return \Illuminate\Support\Facades\Http::response(['issues' => []], 200);
                }

                if ($searchMode === 'matching_summary_without_label') {
                    return \Illuminate\Support\Facades\Http::response([
                        'issues' => [
                            [
                                'id'     => '99990',
                                'key'    => 'CAMB-9990',
                                'fields' => [
                                    'summary' => 'Delayed Indexing Finding',
                                    'status'  => ['name' => 'To Do'],
                                    'labels'  => ['forge', 'unrelated-label'],
                                ],
                            ],
                        ],
                    ], 200);
                }

                // Indexer has converged: issue is visible via deterministic finding label
                return \Illuminate\Support\Facades\Http::response([
                    'issues' => [
                        [
                            'id'     => '99899',
                            'key'    => 'CAMB-9989',
                            'fields' => [
                                'summary' => 'Delayed Indexing Finding',
                                'status'  => ['name' => 'To Do'],
                                'labels'  => ['forge', "forge-finding-{$finding->id}"],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://technopath.atlassian.net/rest/api/3/issue' => function () use (&$postIssueCallCount) {
                $postIssueCallCount++;
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
            },
        ]);

        $jiraProvider = app(JiraProvider::class);

        // 1. Initial attempt: times out, and immediate reconciliation doesn't see it yet due to index lag
        try {
            $jiraProvider->createWorkItem($this->projectA, [
                'finding' => $finding,
                'title'   => 'Delayed Indexing Finding',
            ], $this->admin);
            $this->fail('Expected PmSyncAmbiguousException due to timeout and index lag');
        } catch (\App\Services\PM\Exceptions\PmSyncAmbiguousException $e) {
            $this->assertStringContainsString('Duplicate prevention lock', $e->getMessage());
        }

        $this->assertEquals(1, $postIssueCallCount);

        // 2. Retry while index is still lagging: duplicate lock prevents POST
        try {
            $jiraProvider->createWorkItem($this->projectA, [
                'finding' => $finding,
                'title'   => 'Delayed Indexing Finding',
            ], $this->admin);
            $this->fail('Expected duplicate prevention lock to block POST while index is reconciling');
        } catch (\App\Services\PM\Exceptions\PmSyncAmbiguousException $e) {
            $this->assertStringContainsString('Duplicate POST requests remain blocked', $e->getMessage());
        }

        $this->assertEquals(1, $postIssueCallCount, 'Jira POST /issue was NOT called a second time while index was lagging.');

        // 3. Retry after 15 minutes (past traditional 5-minute cooldown): lock remains durable and blocks POST
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(15));
        try {
            $jiraProvider->createWorkItem($this->projectA, [
                'finding' => $finding,
                'title'   => 'Delayed Indexing Finding',
            ], $this->admin);
            $this->fail('Expected duplicate prevention lock to remain active even after 15 minutes');
        } catch (\App\Services\PM\Exceptions\PmSyncAmbiguousException $e) {
            $this->assertStringContainsString('Duplicate POST requests remain blocked', $e->getMessage());
        }

        $this->assertEquals(1, $postIssueCallCount, 'Jira POST /issue remained blocked past the 5-minute cooldown.');

        // 4. Retry when Jira returns an issue with the matching summary but WITHOUT the finding label
        // Proof of identity requires the unique label; summary alone must NOT be treated as identity proof
        $searchMode = 'matching_summary_without_label';
        try {
            $jiraProvider->createWorkItem($this->projectA, [
                'finding' => $finding,
                'title'   => 'Delayed Indexing Finding',
            ], $this->admin);
            $this->fail('Expected summary alone without finding label to be rejected as identity proof');
        } catch (\App\Services\PM\Exceptions\PmSyncAmbiguousException $e) {
            $this->assertStringContainsString('Duplicate POST requests remain blocked', $e->getMessage());
        }
        $this->assertEquals(1, $postIssueCallCount, 'Duplicate POST remained blocked when issue lacked unique label.');

        // 5. Retry after Jira search index converges with unique label: finds the issue and reconciles without duplicate POST
        $searchMode = 'indexed_with_label';

        $reconciledItem = $jiraProvider->createWorkItem($this->projectA, [
            'finding' => $finding,
            'title'   => 'Delayed Indexing Finding',
        ], $this->admin);

        \Illuminate\Support\Carbon::setTestNow(); // Reset clock

        $this->assertEquals(1, $postIssueCallCount, 'Jira POST /issue was never duplicated.');
        $this->assertInstanceOf(PmWorkItem::class, $reconciledItem);
        $this->assertEquals('CAMB-9989', $reconciledItem->external_item_key);

        $finding->refresh();
        $this->assertEquals($reconciledItem->id, $finding->pm_work_item_id);
        $this->assertEmpty($finding->metadata_json['jira_ambiguous_creation'] ?? null);
    }

    public function test_client_mismatch_between_project_and_finding_fails_closed_with_authorization_exception(): void
    {
        $this->actingAs($this->admin);

        // Finding belongs to Client A (Cambro)
        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Cambro Opportunity',
            'status'                    => FindingStatus::Approved->value,
            'execution_status'          => FindingExecutionStatus::Approved->value,
            'estimated_revenue_impact'  => 3000.00,
        ]);

        $jiraProvider = app(JiraProvider::class);

        // Attempting to create ticket in Client B's (Goldkamp) project must fail closed!
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageMatches('/does not match PM Project client/i');

        $jiraProvider->createWorkItem($this->projectB, [
            'finding' => $finding,
            'title'   => 'Cross-tenant Leak Attempt',
        ], $this->admin);
    }

    public function test_finding_pm_sync_service_syncs_work_item_status_to_finding_execution_state_loop_free(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Sync Test Finding',
            'status'                    => FindingStatus::Approved->value,
            'execution_status'          => FindingExecutionStatus::Assigned->value,
            'outcome_status'            => FindingOutcomeStatus::PendingExecution->value,
            'estimated_revenue_impact'  => 9200.00,
        ]);

        $item = PmWorkItem::create([
            'client_id'                  => $this->clientA->id,
            'pm_project_id'              => $this->projectA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'jira_99201',
            'external_item_key'          => 'CAMB-501',
            'summary'                    => 'Cambro Sync Item',
            'description'                => 'Sync item description',
            'external_status'            => 'In Progress',
            'normalized_delivery_status' => 'in_progress',
        ]);

        $syncService = app(FindingPmSyncService::class);
        $syncService->linkFindingToWorkItem($finding, $item, $this->admin);

        $finding->refresh();
        $this->assertEquals($item->id, $finding->pm_work_item_id);
        $this->assertEquals(FindingExecutionStatus::WorkInProgress, $finding->execution_status);
        $this->assertEquals(FindingOutcomeStatus::PendingExecution, $finding->outcome_status);

        // Record transition count after linking and initial sync
        $transitionCount = FindingStatusTransition::where('finding_id', $finding->id)->count();
        $this->assertGreaterThan(0, $transitionCount);

        // 2. Loop-free guarantee: subsequent sync with unchanged work item status produces NO new transitions
        $syncService->syncWorkItemToFinding($item);
        $this->assertEquals(
            $transitionCount,
            FindingStatusTransition::where('finding_id', $finding->id)->count(),
            'Loop-free check verified no duplicate transitions were logged when status was unchanged.'
        );
    }

    public function test_pm_completion_sets_action_completed_and_awaiting_results_without_touching_revenue_or_assuming_lift(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Revenue Segregation Test Finding',
            'status'                    => FindingStatus::Approved->value,
            'execution_status'          => FindingExecutionStatus::WorkInProgress->value,
            'outcome_status'            => FindingOutcomeStatus::PendingExecution->value,
            'estimated_revenue_impact'  => 14000.00,
        ]);

        $item = PmWorkItem::create([
            'client_id'                  => $this->clientA->id,
            'pm_project_id'              => $this->projectA->id,
            'pm_connection_id'           => $this->connection->id,
            'external_item_id'           => 'jira_99202',
            'external_item_key'          => 'CAMB-502',
            'summary'                    => 'Cambro Completion Item',
            'description'                => 'Completion item description',
            'external_status'            => 'Done',
            'normalized_delivery_status' => 'completed',
        ]);

        $syncService = app(FindingPmSyncService::class);
        $syncService->linkFindingToWorkItem($finding, $item, $this->admin);

        // Sync Done state from PM tool
        $syncService->syncWorkItemToFinding($item);

        $finding->refresh();

        // Execution status is ActionCompleted
        $this->assertEquals(FindingExecutionStatus::ActionCompleted, $finding->execution_status);
        $this->assertNotNull($finding->action_completed_at);

        // Outcome status is moved to AwaitingResults (waiting for true observed order data, NOT assumed lift!)
        $this->assertEquals(FindingOutcomeStatus::AwaitingResults, $finding->outcome_status);
        $this->assertNull($finding->outcome_measured_at, 'Outcome is not measured yet.');

        // Crucial financial safeguard check: estimated revenue impact is NOT altered or claimed as achieved
        $this->assertEquals(14000.00, (float) $finding->estimated_revenue_impact);

        // Verify lifecycle clarity helpers
        $this->assertTrue($finding->isActionCompleted());
        $this->assertFalse($finding->isOutcomeMeasured());
        $this->assertFalse($finding->hasVerifiedRevenueRecovery());
    }

    public function test_finding_status_transitions_audit_table_records_immutable_history(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Audit Trail Test Finding',
            'status'                    => FindingStatus::New->value,
            'execution_status'          => FindingExecutionStatus::New->value,
            'outcome_status'            => FindingOutcomeStatus::PendingExecution->value,
            'estimated_revenue_impact'  => 4500.00,
        ]);

        $finding->transitionTo(FindingStatus::Approved, $this->admin, 'Approved by Management');
        $finding->transitionExecutionState(FindingExecutionStatus::WorkInProgress, 'pm_sync', $this->admin, 'Jira sprint started');

        $transitions = FindingStatusTransition::where('finding_id', $finding->id)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(2, $transitions);

        $first = $transitions[0];
        $this->assertEquals(FindingStatus::New, $first->from_status);
        $this->assertEquals(FindingStatus::Approved, $first->to_status);
        $this->assertEquals($this->admin->id, $first->user_id);
        $this->assertEquals('Approved by Management', $first->notes);

        $second = $transitions[1];
        $this->assertEquals(FindingExecutionStatus::Approved, $second->from_execution_status);
        $this->assertEquals(FindingExecutionStatus::WorkInProgress, $second->to_execution_status);
        $this->assertEquals('pm_sync', $second->transition_source);
        $this->assertEquals('Jira sprint started', $second->notes);
    }

    public function test_explicitly_handled_or_clear_ambiguity_lock_allows_new_jira_creation(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Explicit Handling Finding',
            'status'                    => FindingStatus::Approved->value,
            'execution_status'          => FindingExecutionStatus::Approved->value,
            'estimated_revenue_impact'  => 5000.00,
        ]);

        $jiraProvider = app(JiraProvider::class);

        $issueShouldTimeout = true;

        // 1. Induce ambiguous timeout
        \Illuminate\Support\Facades\Http::fake([
            'https://technopath.atlassian.net/rest/api/3/search/jql' => \Illuminate\Support\Facades\Http::response(['issues' => []], 200),
            'https://technopath.atlassian.net/rest/api/3/issue' => function () use (&$issueShouldTimeout) {
                if ($issueShouldTimeout) {
                    throw new \Illuminate\Http\Client\ConnectionException('Simulated cURL timeout');
                }
                return \Illuminate\Support\Facades\Http::response([
                    'id'  => '99995',
                    'key' => 'CAMB-9995',
                ], 201);
            },
        ]);

        try {
            $jiraProvider->createWorkItem($this->projectA, [
                'finding' => $finding,
                'title'   => 'Explicit Handling Finding',
            ], $this->admin);
            $this->fail('Expected timeout exception');
        } catch (\App\Services\PM\Exceptions\PmSyncAmbiguousException $e) {
            $this->assertStringContainsString('Duplicate prevention lock engaged', $e->getMessage());
        }

        $finding->refresh();
        $this->assertNotNull($finding->metadata_json['jira_ambiguous_creation'] ?? null);

        // 2. An unhandled retry remains blocked
        try {
            $jiraProvider->createWorkItem($this->projectA, [
                'finding' => $finding,
                'title'   => 'Explicit Handling Finding',
            ], $this->admin);
            $this->fail('Expected lock to block unhandled retry');
        } catch (\App\Services\PM\Exceptions\PmSyncAmbiguousException $e) {
            $this->assertStringContainsString('Duplicate POST requests remain blocked', $e->getMessage());
        }

        // 3. Explicitly handled request successfully bypasses the lock and recreates when Jira issue endpoint succeeds
        $issueShouldTimeout = false;

        $newItem = $jiraProvider->createWorkItem($this->projectA, [
            'finding'            => $finding,
            'title'              => 'Explicit Handling Finding',
            'explicitly_handled' => true,
        ], $this->admin);

        $this->assertInstanceOf(PmWorkItem::class, $newItem);
        $this->assertEquals('CAMB-9995', $newItem->external_item_key);
        $finding->refresh();
        $this->assertEmpty($finding->metadata_json['jira_ambiguous_creation'] ?? null);
    }

    public function test_revenue_recovery_classification_distinguishes_observed_orders_from_modeled_lift_and_causal_proof(): void
    {
        $this->actingAs($this->admin);

        $finding = $this->createFinding([
            'client_id'                 => $this->clientA->id,
            'title'                     => 'Revenue Distinction Finding',
            'status'                    => FindingStatus::Resolved->value,
            'execution_status'          => FindingExecutionStatus::ActionCompleted->value,
            'outcome_status'            => FindingOutcomeStatus::OutcomeMeasured->value,
            'estimated_revenue_impact'  => 14000.00,
        ]);

        // Scenario A: Post-action orders observed ($18,000 gross) with modeled incremental impact ($14,000),
        // but NO causal holdout / causal verification evidence.
        $outcome = \App\Models\RecommendationOutcome::create([
            'finding_id'               => $finding->id,
            'client_id'                => $this->clientA->id,
            'observation_window_days'  => 30,
            'observed_revenue'         => 18000.00,
            'baseline_expected_revenue'=> 4000.00,
            'actual_incremental_impact'=> 14000.00,
            'attribution_method'       => 'modeled_pre_post', // NOT causal_holdout
            'verified_at'              => null,
        ]);

        $finding->refresh();

        // 1. Observed post-action orders are verified gross transactions
        $this->assertTrue($finding->hasObservedPostActionOrders());
        $this->assertEquals(18000.00, $finding->getObservedPostActionRevenue());

        // 2. Incremental lift ($14,000) is recognized as modeled run-rate lift
        $this->assertTrue($finding->hasModeledIncrementalLift());
        $this->assertEquals(14000.00, $finding->getModeledIncrementalLift());

        // 3. CRITICAL: A positive modeled incremental lift must NOT automatically be treated
        // as verified or causally attributable recovered revenue!
        $this->assertFalse($finding->hasCausallyVerifiedRecovery(), 'Modeled pre-post lift alone is not causally verified recovery.');
        $this->assertFalse($finding->hasVerifiedRevenueRecovery(), 'hasVerifiedRevenueRecovery fails closed without causal proof.');

        // Scenario B: Causal verification is established via randomized control holdout
        $outcome->update([
            'attribution_method' => 'causal_holdout',
            'verified_at'        => now(),
        ]);
        $finding->refresh();

        $this->assertTrue($finding->hasCausallyVerifiedRecovery());
        $this->assertTrue($finding->hasVerifiedRevenueRecovery());
    }
}
