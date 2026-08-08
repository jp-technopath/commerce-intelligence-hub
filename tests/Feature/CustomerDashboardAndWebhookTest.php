<?php

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Http\Controllers\Api\JiraWebhookController;
use App\Jobs\ProcessJiraWebhookJob;
use App\Jobs\ReconcilePmWorkItemsJob;
use App\Models\Client;
use App\Models\ConnectedAccount;
use App\Models\CustomerAttentionItem;
use App\Models\ForgeApprovalEvent;
use App\Models\ForgeEstimateVersion;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\User;
use App\Services\EstimateApprovalService;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerDashboardAndWebhookTest extends TestCase
{
    use DatabaseTransactions;

    protected Client $client;
    protected User $syncUser;
    protected PmConnection $connection;
    protected PmProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create(['name' => 'Acme Corp', 'code' => 'ACME']);

        $this->syncUser = User::firstOrCreate(
            ['email' => 'admin@acme.com'],
            ['name' => 'Sync Admin', 'password' => Hash::make('password')]
        );

        ConnectedAccount::create([
            'user_id'          => $this->syncUser->id,
            'provider'         => 'jira',
            'authorized_email' => 'admin@acme.com',
            'credentials_json' => ['access_token' => 'mock_token', 'cloud_id' => 'cloud_acme'],
            'status'           => ConnectedAccountStatus::Active,
        ]);

        $this->connection = PmConnection::create([
            'client_id'            => $this->client->id,
            'provider'             => 'jira',
            'name'                 => 'Acme Jira',
            'external_workspace_id'=> 'cloud_acme',
            'default_sync_user_id' => $this->syncUser->id,
            'is_active'            => true,
        ]);

        $this->project = PmProject::create([
            'client_id'            => $this->client->id,
            'pm_connection_id'     => $this->connection->id,
            'name'                 => 'Acme Project',
            'external_project_key' => 'ACME',
            'is_active'            => true,
        ]);
    }

    public function test_webhook_controller_dispatches_job_idempotently(): void
    {
        Bus::fake();

        $payload = [
            'webhookEvent' => 'jira:issue_updated',
            'timestamp'    => 1600000000,
            'issue'        => [
                'id'     => '20001',
                'key'    => 'ACME-101',
                'fields' => [
                    'summary'      => 'Fix Login Bug',
                    'project'      => ['key' => 'ACME'],
                    'status'       => ['name' => 'In Progress'],
                    'issuetype'    => ['name' => 'Bug'],
                    'timetracking' => ['originalEstimateSeconds' => 18000],
                ],
            ],
        ];

        // First delivery
        $response1 = $this->postJson('/api/webhooks/jira', $payload, ['X-Atlassian-Webhook-Identifier' => 'event-unique-123']);
        $response1->assertStatus(200);
        $response1->assertJson(['status' => 'accepted']);

        Bus::assertDispatched(ProcessJiraWebhookJob::class, 1);

        // Duplicate delivery with same event identifier
        $response2 = $this->postJson('/api/webhooks/jira', $payload, ['X-Atlassian-Webhook-Identifier' => 'event-unique-123']);
        $response2->assertStatus(200);
        $response2->assertJson(['status' => 'ignored', 'reason' => 'duplicate_delivery']);

        // Assert job was not dispatched again
        Bus::assertDispatched(ProcessJiraWebhookJob::class, 1);
    }

    public function test_process_webhook_job_creates_work_item_and_worklog(): void
    {
        $payload = [
            'webhookEvent' => 'jira:issue_updated',
            'issue'        => [
                'id'     => '20002',
                'key'    => 'ACME-102',
                'fields' => [
                    'summary'      => 'Build Checkout API',
                    'project'      => ['key' => 'ACME'],
                    'status'       => ['name' => 'In Development'],
                    'issuetype'    => ['name' => 'Story'],
                    'timetracking' => ['originalEstimateSeconds' => 28800],
                ],
            ],
            'worklog'      => [
                'id'               => 'wl-555',
                'author'           => ['displayName' => 'Developer Alex'],
                'timeSpentSeconds' => 7200,
                'started'          => '2026-08-01T10:00:00.000+0000',
            ],
        ];

        $job = new ProcessJiraWebhookJob('jira:issue_updated', $payload);
        $jiraProvider = new JiraProvider();
        $approvalService = app(EstimateApprovalService::class);

        $job->handle($jiraProvider, $approvalService);

        // Assert PmWorkItem was created
        $this->assertDatabaseHas('pm_work_items', [
            'client_id'                  => $this->client->id,
            'external_item_key'          => 'ACME-102',
            'summary'                    => 'Build Checkout API',
            'normalized_delivery_status' => 'in_progress',
        ]);

        // Assert PmWorklog was created
        $this->assertDatabaseHas('pm_worklogs', [
            'client_id'           => $this->client->id,
            'external_worklog_id' => 'wl-555',
            'author_name'         => 'Developer Alex',
            'time_spent_seconds'  => 7200,
        ]);
    }

    public function test_customer_attention_items_and_reapproval(): void
    {
        $workItem = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'pm_project_id'              => $this->project->id,
            'external_item_id'           => '20003',
            'external_item_key'          => 'ACME-103',
            'summary'                    => 'Payment Integration',
            'normalized_delivery_status' => 'ready',
            'estimated_seconds'          => 36000, // 10 hrs
        ]);

        /** @var EstimateApprovalService $service */
        $service = app(EstimateApprovalService::class);

        // Submit & Approve
        $v1 = $service->submitEstimate($workItem, 36000, 'v1 notes', $this->syncUser);
        $service->approveEstimate($v1, $this->syncUser);

        $this->assertEquals('approved', $workItem->fresh()->estimate_approval_status);

        // Reapproval triggered by estimate change (12 hrs = 43200s)
        $service->checkEstimateReapprovalNeeded($workItem, 43200);

        // Assert Attention Item raised
        $this->assertDatabaseHas('customer_attention_items', [
            'client_id'   => $this->client->id,
            'category'    => 'estimate_reapproval',
            'is_resolved' => false,
        ]);
    }
}
