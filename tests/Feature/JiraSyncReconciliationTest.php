<?php

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Jobs\ProcessJiraWebhookJob;
use App\Models\Client;
use App\Models\ConnectedAccount;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\User;
use App\Services\EstimateApprovalService;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraSyncReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;
    protected User $syncUser;
    protected PmConnection $connection;
    protected PmProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create(['name' => 'Cambro Test', 'code' => 'CMBR_TEST']);

        $this->syncUser = User::firstOrCreate(
            ['email' => 'sync_reconcile@test.com'],
            ['name' => 'Sync Tester', 'password' => Hash::make('password')]
        );

        ConnectedAccount::create([
            'user_id'          => $this->syncUser->id,
            'provider'         => 'jira',
            'authorized_email' => 'sync_reconcile@test.com',
            'credentials_json' => ['access_token' => 'mock_token', 'cloud_id' => 'cloud_test'],
            'status'           => ConnectedAccountStatus::Active,
        ]);

        $this->connection = PmConnection::create([
            'client_id'            => $this->client->id,
            'provider'             => 'jira',
            'name'                 => 'Cambro Jira Test',
            'external_workspace_id'=> 'cloud_test',
            'default_sync_user_id' => $this->syncUser->id,
            'is_active'            => true,
        ]);

        $this->project = PmProject::create([
            'pm_connection_id'     => $this->connection->id,
            'client_id'            => $this->client->id,
            'name'                 => 'Cambro Test Project',
            'external_project_key' => 'CMBR_TEST',
            'external_project_id'  => '99999',
            'is_active'            => true,
        ]);
    }

    public function test_backlog_item_is_updated_and_preserves_worklogs(): void
    {
        $provider = new JiraProvider();

        // 1. Create an existing work item with logged time
        $item = PmWorkItem::create([
            'pm_connection_id'           => $this->connection->id,
            'client_id'                  => $this->client->id,
            'pm_project_id'              => $this->project->id,
            'external_item_id'           => '10001',
            'external_item_key'          => 'CMBR_TEST-1',
            'summary'                    => 'Test Task Moved to Backlog',
            'external_status'            => 'Ready for Dev',
            'normalized_delivery_status' => 'ready',
            'time_spent_seconds'         => 3600,
        ]);

        PmWorklog::create([
            'client_id'           => $this->client->id,
            'pm_connection_id'    => $this->connection->id,
            'pm_work_item_id'     => $item->id,
            'external_worklog_id' => 'wl_1001',
            'author_name'         => 'Engineer',
            'time_spent_seconds'  => 3600,
            'worklog_started_at'  => now(),
        ]);

        // 2. Jira returns status "Backlog"
        $issueData = [
            'id' => '10001',
            'key' => 'CMBR_TEST-1',
            'fields' => [
                'summary' => 'Test Task Moved to Backlog',
                'status' => ['name' => 'BACKLOG'],
                'issuetype' => ['name' => 'Task'],
                'priority' => ['name' => 'Medium'],
                'timetracking' => ['originalEstimateSeconds' => 7200, 'timeSpentSeconds' => 3600],
            ],
        ];

        $savedItem = $provider->normalizeAndSaveWorkItem($issueData, $this->project, $this->connection);

        $this->assertNotNull($savedItem);
        $this->assertSame('BACKLOG', $savedItem->external_status);
        $this->assertSame('backlog', $savedItem->normalized_delivery_status);

        // Verify work item still exists in DB and worklogs are intact
        $this->assertDatabaseHas('pm_work_items', [
            'id' => $item->id,
            'normalized_delivery_status' => 'backlog',
        ]);
        $this->assertSame(1, $item->worklogs()->count());
    }

    public function test_issue_deleted_webhook_removes_work_item(): void
    {
        $item = PmWorkItem::create([
            'pm_connection_id'           => $this->connection->id,
            'client_id'                  => $this->client->id,
            'pm_project_id'              => $this->project->id,
            'external_item_id'           => '10002',
            'external_item_key'          => 'CMBR_TEST-2',
            'summary'                    => 'Deleted ticket in Jira',
            'external_status'            => 'Ready for Dev',
            'normalized_delivery_status' => 'ready',
        ]);

        $this->assertDatabaseHas('pm_work_items', ['id' => $item->id]);

        $payload = [
            'webhookEvent' => 'jira:issue_deleted',
            'issue' => [
                'id' => '10002',
                'key' => 'CMBR_TEST-2',
                'fields' => [
                    'project' => ['key' => 'CMBR_TEST'],
                ],
            ],
        ];

        $job = new ProcessJiraWebhookJob('jira:issue_deleted', $payload);
        $job->handle(app(JiraProvider::class), app(EstimateApprovalService::class));

        $this->assertDatabaseMissing('pm_work_items', ['id' => $item->id]);
    }

    public function test_sync_reconciles_missing_tickets_and_deletes_when_404(): void
    {
        // 1. Create an active ticket in Forge that no longer exists in Jira
        $deletedItem = PmWorkItem::create([
            'pm_connection_id'           => $this->connection->id,
            'client_id'                  => $this->client->id,
            'pm_project_id'              => $this->project->id,
            'external_item_id'           => '99991',
            'external_item_key'          => 'CMBR_TEST-999',
            'summary'                    => 'Item deleted from Jira',
            'external_status'            => 'Ready for Dev',
            'normalized_delivery_status' => 'ready',
        ]);

        // 2. Create an active ticket in Forge that is still active in Jira
        $activeItem = PmWorkItem::create([
            'pm_connection_id'           => $this->connection->id,
            'client_id'                  => $this->client->id,
            'pm_project_id'              => $this->project->id,
            'external_item_id'           => '99992',
            'external_item_key'          => 'CMBR_TEST-100',
            'summary'                    => 'Item active in Jira',
            'external_status'            => 'In Progress',
            'normalized_delivery_status' => 'in_progress',
        ]);

        // Mock Http for Jira
        Http::fake([
            '*/rest/api/3/search/jql' => function (\Illuminate\Http\Client\Request $request) {
                $jql = $request['jql'] ?? '';
                if (str_contains($jql, 'key in')) {
                    // Reconciliation check: CMBR_TEST-999 is NOT returned (it was deleted in Jira)
                    return Http::response(['issues' => [], 'isLast' => true], 200);
                }

                // Main sync search: returns only active ticket 99992
                return Http::response([
                    'issues' => [
                        [
                            'id' => '99992',
                            'key' => 'CMBR_TEST-100',
                            'fields' => [
                                'summary' => 'Item active in Jira',
                                'status' => ['name' => 'In Progress'],
                                'issuetype' => ['name' => 'Task'],
                                'priority' => ['name' => 'High'],
                                'timetracking' => ['originalEstimateSeconds' => 3600, 'timeSpentSeconds' => 0],
                            ],
                        ],
                    ],
                    'isLast' => true,
                ], 200);
            },
            '*/rest/api/3/issue/CMBR_TEST-999' => Http::response(['errorMessages' => ['Issue does not exist']], 404),
        ]);

        $provider = new JiraProvider();
        $provider->syncWorkItems($this->project, 30);

        // Verify CMBR_TEST-999 was deleted from Forge
        $this->assertDatabaseMissing('pm_work_items', ['id' => $deletedItem->id]);

        // Verify active item is still present
        $this->assertDatabaseHas('pm_work_items', ['id' => $activeItem->id]);
    }

    public function test_work_in_progress_query_excludes_backlog_items(): void
    {
        PmWorkItem::create([
            'pm_connection_id'           => $this->connection->id,
            'client_id'                  => $this->client->id,
            'pm_project_id'              => $this->project->id,
            'external_item_id'           => '10003',
            'external_item_key'          => 'CMBR_TEST-3',
            'summary'                    => 'Backlog item',
            'external_status'            => 'BACKLOG',
            'normalized_delivery_status' => 'backlog',
        ]);

        PmWorkItem::create([
            'pm_connection_id'           => $this->connection->id,
            'client_id'                  => $this->client->id,
            'pm_project_id'              => $this->project->id,
            'external_item_id'           => '10004',
            'external_item_key'          => 'CMBR_TEST-4',
            'summary'                    => 'Active ready item',
            'external_status'            => 'Ready for Dev',
            'normalized_delivery_status' => 'ready',
        ]);

        $pipelineItems = PmWorkItem::query()
            ->where('client_id', $this->client->id)
            ->whereNotIn('normalized_delivery_status', ['completed', 'backlog'])
            ->whereRaw('UPPER(external_status) NOT LIKE ?', ['%BACKLOG%'])
            ->get();

        $this->assertSame(1, $pipelineItems->count());
        $this->assertSame('CMBR_TEST-4', $pipelineItems->first()->external_item_key);
    }
}
