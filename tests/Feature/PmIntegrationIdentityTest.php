<?php

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Models\Client;
use App\Models\ConnectedAccount;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\EstimateApprovalService;
use App\Services\PM\Exceptions\UserJiraAccountNotConnectedException;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PmIntegrationIdentityTest extends TestCase
{
    use DatabaseTransactions;

    protected Client $client;
    protected User $syncUser;
    protected User $humanUser;
    protected User $unconnectedUser;
    protected PmConnection $connection;
    protected PmProject $project;
    protected PmWorkItem $workItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name' => 'Cambro',
            'code' => 'CAMBRO',
        ]);

        // 1. Sync User with connected Jira account
        $this->syncUser = User::firstOrCreate(
            ['email' => 'nour@technopath.ai'],
            ['name' => 'Nour SyncUser', 'password' => Hash::make('password')]
        );

        ConnectedAccount::updateOrCreate(
            ['user_id' => $this->syncUser->id, 'provider' => 'jira'],
            [
                'authorized_email' => 'nour@technopath.ai',
                'credentials_json' => ['access_token' => 'sync_access_token_123', 'cloud_id' => 'cloud_123'],
                'status'           => ConnectedAccountStatus::Active,
            ]
        );

        // 2. Human User with connected Jira account
        $this->humanUser = User::firstOrCreate(
            ['email' => 'jp@technopath.ai'],
            ['name' => 'Jean-Paul PM', 'password' => Hash::make('password')]
        );

        ConnectedAccount::firstOrCreate(
            ['user_id' => $this->humanUser->id, 'provider' => 'jira'],
            [
                'authorized_email' => 'jp@technopath.ai',
                'credentials_json' => ['access_token' => 'jp_access_token_456', 'cloud_id' => 'cloud_123'],
                'status'           => ConnectedAccountStatus::Active,
            ]
        );

        // 3. User without connected Jira account
        $this->unconnectedUser = User::firstOrCreate(
            ['email' => 'unconnected@technopath.ai'],
            ['name' => 'Unconnected User', 'password' => Hash::make('password')]
        );

        // 4. Customer PM Connection
        $this->connection = PmConnection::create([
            'client_id'            => $this->client->id,
            'provider'             => 'jira',
            'name'                 => 'Cambro Jira Workspace',
            'external_workspace_id'=> 'cloud_123',
            'default_sync_user_id' => $this->syncUser->id,
            'is_active'            => true,
        ]);

        $this->project = PmProject::create([
            'client_id'            => $this->client->id,
            'pm_connection_id'     => $this->connection->id,
            'name'                 => 'Cambro Main',
            'external_project_key' => 'CMBR2',
            'is_active'            => true,
        ]);

        $this->workItem = PmWorkItem::create([
            'client_id'                  => $this->client->id,
            'pm_connection_id'           => $this->connection->id,
            'pm_project_id'              => $this->project->id,
            'external_item_id'           => '10042',
            'external_item_key'          => 'CMBR2-421',
            'summary'                    => 'Checkout Optimization',
            'normalized_delivery_status' => 'in_progress',
            'estimated_seconds'          => 36000, // 10 hours
        ]);
    }

    public function test_human_action_uses_actor_credentials(): void
    {
        $provider = new JiraProvider();
        $creds = $provider->resolveCredentials($this->humanUser, $this->connection);

        $this->assertEquals($this->humanUser->id, $creds->user_id);
        $this->assertEquals('jp_access_token_456', $creds->getCredential('access_token'));
    }

    public function test_missing_user_connection_throws_exception_without_fallback(): void
    {
        $this->expectException(UserJiraAccountNotConnectedException::class);

        $provider = new JiraProvider();
        $provider->resolveCredentials($this->unconnectedUser, $this->connection, true);
    }

    public function test_background_action_uses_default_sync_user_credentials(): void
    {
        $provider = new JiraProvider();
        $creds = $provider->resolveCredentials(null, $this->connection);

        $this->assertEquals($this->syncUser->id, $creds->user_id);
        $this->assertEquals('sync_access_token_123', $creds->getCredential('access_token'));
    }

    public function test_sync_identity_health_renders_warning_when_broken(): void
    {
        $healthActive = $this->connection->sync_identity_health;
        $this->assertTrue($healthActive['is_healthy']);

        // Revoke token
        $this->syncUser->jiraAccount()->update(['status' => ConnectedAccountStatus::Revoked]);

        $this->connection->refresh();
        $healthRevoked = $this->connection->sync_identity_health;
        $this->assertFalse($healthRevoked['is_healthy']);
        $this->assertStringContainsString('requires reconnection', $healthRevoked['message']);
    }

    public function test_estimate_submission_and_approval_workflow(): void
    {
        /** @var EstimateApprovalService $service */
        $service = app(EstimateApprovalService::class);

        // Submit Estimate v1
        $version1 = $service->submitEstimate($this->workItem, 36000, 'Initial estimate', $this->humanUser);
        $this->assertEquals(1, $version1->version);
        $this->assertEquals(10.0, $version1->estimated_hours);

        // Approve Estimate
        $event = $service->approveEstimate($version1, $this->humanUser, 'Looks good!');
        $this->assertEquals('approved', $event->event_type);
        $this->assertEquals('approved', $this->workItem->fresh()->estimate_approval_status);
    }

    public function test_estimate_reapproval_triggered_when_jira_estimate_changes(): void
    {
        /** @var EstimateApprovalService $service */
        $service = app(EstimateApprovalService::class);

        // Submit & Approve v1
        $version1 = $service->submitEstimate($this->workItem, 36000, 'v1', $this->humanUser);
        $service->approveEstimate($version1, $this->humanUser);

        // Jira estimate updated to 14 hours (50400 seconds)
        $newVersion = $service->checkEstimateReapprovalNeeded($this->workItem, 50400);

        $this->assertNotNull($newVersion);
        $this->assertEquals(2, $newVersion->version);
        $this->assertEquals(14.0, $newVersion->estimated_hours);

        // Check previous version marked as reapproval_required
        $this->assertEquals('reapproval_required', $version1->latestEvent->event_type);
    }
}
