<?php

namespace Tests\Feature\PM;

use App\Enums\ConnectedAccountStatus;
use App\Models\Client;
use App\Models\ConnectedAccount;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\User;
use App\Services\Intelligence\TimeTrackingService;
use App\Services\PM\IdentityResolverService;
use App\Services\PM\Providers\JiraProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiProjectSyncAndTempoAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected Client $goldkamp;
    protected User $engineer;
    protected User $syncUser;
    protected PmConnection $connectionA;
    protected PmConnection $connectionB;
    protected PmProject $projectGkssc;
    protected PmProject $projectGsm;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Client with multi-project Jira keys
        $this->goldkamp = Client::create([
            'name'                     => 'GoldKamp',
            'jira_project_key'         => 'GKSSC, GSM',
            'status'                   => 'active',
            'monthly_allocated_hours'  => 100,
        ]);

        $this->engineer = User::forceCreate([
            'id'       => 3,
            'name'     => 'Donia Khaled',
            'email'    => 'donia@technopath.co',
            'password' => Hash::make('password'),
        ]);

        $this->syncUser = User::create([
            'name'     => 'Sync Admin',
            'email'    => 'admin@technopath.co',
            'password' => Hash::make('password'),
        ]);

        ConnectedAccount::create([
            'user_id'          => $this->syncUser->id,
            'provider'         => 'jira',
            'authorized_email' => 'admin@technopath.co',
            'credentials_json' => ['access_token' => 'mock_token', 'cloud_id' => 'cloud_goldkamp'],
            'token_expires_at' => now()->addHour(),
            'status'           => ConnectedAccountStatus::Active,
        ]);

        // 2. Connection A (dedicated Goldkamp workspace)
        $this->connectionA = PmConnection::create([
            'client_id'            => $this->goldkamp->id,
            'provider'             => 'jira',
            'name'                 => 'Goldkamp Connection A',
            'external_workspace_id'=> 'cloud_goldkamp',
            'default_sync_user_id' => $this->syncUser->id,
            'is_active'            => true,
        ]);

        // 3. Connection B (shared / employee Jira connection)
        $this->connectionB = PmConnection::create([
            'client_id'            => $this->goldkamp->id,
            'provider'             => 'jira',
            'name'                 => 'Goldkamp Connection B',
            'external_workspace_id'=> 'cloud_goldkamp',
            'default_sync_user_id' => $this->syncUser->id,
            'is_active'            => true,
        ]);

        $this->projectGkssc = PmProject::create([
            'pm_connection_id'     => $this->connectionA->id,
            'client_id'            => $this->goldkamp->id,
            'name'                 => 'Goldkamp ScaleSmart Core',
            'external_project_key' => 'GKSSC',
            'external_project_id'  => '10501',
            'is_active'            => true,
        ]);

        $this->projectGsm = PmProject::create([
            'pm_connection_id'     => $this->connectionA->id,
            'client_id'            => $this->goldkamp->id,
            'name'                 => 'Goldkamp Marketing',
            'external_project_key' => 'GSM',
            'external_project_id'  => '10502',
            'is_active'            => true,
        ]);
    }

    public function test_multi_project_keys_normalization_and_discovery(): void
    {
        $this->assertEquals(['GKSSC', 'GSM'], $this->goldkamp->normalized_jira_project_keys);

        Http::fake([
            'https://api.atlassian.com/ex/jira/cloud_goldkamp/rest/api/3/project' => Http::response([
                ['id' => '10501', 'key' => 'GKSSC', 'name' => 'ScaleSmart Core'],
                ['id' => '10502', 'key' => 'GSM', 'name' => 'Goldkamp Marketing'],
                ['id' => '10503', 'key' => 'OTHER', 'name' => 'Unrelated Project'],
            ], 200),
        ]);

        $provider = new JiraProvider();
        $synced = $provider->syncProjects($this->connectionA);

        $gkssc = PmProject::where('pm_connection_id', $this->connectionA->id)
            ->where('external_project_key', 'GKSSC')
            ->first();
        $gsm = PmProject::where('pm_connection_id', $this->connectionA->id)
            ->where('external_project_key', 'GSM')
            ->first();

        $this->assertNotNull($gkssc);
        $this->assertEquals($this->goldkamp->id, $gkssc->client_id);
        $this->assertTrue($gkssc->is_active);

        $this->assertNotNull($gsm);
        $this->assertEquals($this->goldkamp->id, $gsm->client_id);
        $this->assertTrue($gsm->is_active);
    }

    public function test_cross_connection_worklog_deduplication(): void
    {
        $itemA = PmWorkItem::create([
            'pm_connection_id'     => $this->connectionA->id,
            'client_id'            => $this->goldkamp->id,
            'pm_project_id'        => $this->projectGkssc->id,
            'external_item_id'     => '20001',
            'external_item_key'    => 'GKSSC-101',
            'summary'              => 'Task synced from connection A',
            'external_status'      => 'In Progress',
            'delivery_status'      => 'in_progress',
            'item_type'            => 'Task',
            'time_spent_seconds'   => 3600,
        ]);

        $itemB = PmWorkItem::create([
            'pm_connection_id'     => $this->connectionB->id,
            'client_id'            => $this->goldkamp->id,
            'pm_project_id'        => $this->projectGkssc->id,
            'external_item_id'     => '20001',
            'external_item_key'    => 'GKSSC-101',
            'summary'              => 'Same task synced from connection B',
            'external_status'      => 'In Progress',
            'delivery_status'      => 'in_progress',
            'item_type'            => 'Task',
            'time_spent_seconds'   => 3600,
        ]);

        $fakeWorklogPayload = [
            'total' => 1,
            'worklogs' => [
                [
                    'id'               => '991001',
                    'timeSpentSeconds' => 3600,
                    'started'          => Carbon::now()->startOfMonth()->addDays(2)->toIso8601String(),
                    'author'           => [
                        'accountId'   => '64007b8f896d10ebd471046d', // Donia's known Jira account ID (resolves to user #3 or $this->engineer)
                        'displayName' => 'Donia Khaled',
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://api.atlassian.com/ex/jira/cloud_goldkamp/rest/api/3/issue/*/worklog*' => Http::response($fakeWorklogPayload, 200),
        ]);

        $provider = new JiraProvider();

        // Sync via Connection A
        $logsA = $provider->syncWorklogs($itemA);
        $this->assertCount(1, $logsA);

        // Sync same worklog via Connection B (same Jira site)
        $logsB = $provider->syncWorklogs($itemB);
        $this->assertCount(1, $logsB);

        // Verify only ONE database record exists for cloud_goldkamp:991001
        $canonicalKeyA = PmWorklog::generateCanonicalKey($this->connectionA, '991001');
        $this->assertEquals('cloud_goldkamp:991001', $canonicalKeyA);

        $matchingLogs = PmWorklog::where('canonical_worklog_key', $canonicalKeyA)->get();
        $this->assertCount(1, $matchingLogs);

        $worklog = $matchingLogs->first();
        $this->assertEquals($this->connectionA->id, $worklog->pm_connection_id); // preserves original connection
        $this->assertEquals($this->connectionB->id, $worklog->attribution_metadata_json['provenance']['last_sync_connection_id']);
        $this->assertEquals('cloud_goldkamp', $worklog->attribution_metadata_json['provenance']['site_identity']);

        // Verify TimeTrackingService counts these hours exactly once
        $timeTracking = new TimeTrackingService();
        $managerHours = $timeTracking->getManagerMonthlyHours([$this->goldkamp->id], Carbon::now());

        $clientReport = collect($managerHours['by_customer'])->firstWhere('client_id', $this->goldkamp->id);
        $this->assertNotNull($clientReport);
        $this->assertEquals(1.0, $clientReport['actual_hours']);
        $this->assertEquals(1.0, $clientReport['verified_hours']);
        $this->assertEquals(0.0, $clientReport['unattributed_hours']);

        // Verify DIFFERENT Jira site with identical numeric worklog ID creates distinct canonical worklog:
        $otherClient = Client::create([
            'name'             => 'Other Client',
            'jira_project_key' => 'OTH',
        ]);

        $connectionOtherSite = PmConnection::create([
            'client_id'            => $otherClient->id,
            'provider'             => 'jira',
            'name'                 => 'Other Jira Site Connection',
            'external_workspace_id'=> 'cloud_other_site',
            'default_sync_user_id' => $this->syncUser->id,
            'is_active'            => true,
        ]);

        $projectOther = PmProject::create([
            'pm_connection_id'     => $connectionOtherSite->id,
            'client_id'            => $otherClient->id,
            'name'                 => 'Other Client Project',
            'external_project_key' => 'OTH',
            'external_project_id'  => '99001',
            'is_active'            => true,
        ]);

        $itemOther = PmWorkItem::create([
            'pm_connection_id'     => $connectionOtherSite->id,
            'client_id'            => $otherClient->id,
            'pm_project_id'        => $projectOther->id,
            'external_item_id'     => '30001',
            'external_item_key'    => 'OTH-1',
            'summary'              => 'Task on separate Jira site',
            'external_status'      => 'In Progress',
            'delivery_status'      => 'in_progress',
            'item_type'            => 'Task',
            'time_spent_seconds'   => 3600,
        ]);

        Http::fake([
            'https://api.atlassian.com/ex/jira/cloud_other_site/rest/api/3/issue/*/worklog*' => Http::response($fakeWorklogPayload, 200),
        ]);

        $provider->syncWorklogs($itemOther);

        // Verify distinct canonical record was created for cloud_other_site:991001 without overwriting Goldkamp
        $otherLog = PmWorklog::where('canonical_worklog_key', 'cloud_other_site:991001')->first();
        $this->assertNotNull($otherLog);
        $this->assertEquals($otherClient->id, $otherLog->client_id);

        $goldkampLog = PmWorklog::where('canonical_worklog_key', 'cloud_goldkamp:991001')->first();
        $this->assertNotNull($goldkampLog);
        $this->assertEquals($this->goldkamp->id, $goldkampLog->client_id);
    }

    public function test_three_tier_attribution_and_authoritative_correction(): void
    {
        $item = PmWorkItem::create([
            'pm_connection_id'     => $this->connectionA->id,
            'client_id'            => $this->goldkamp->id,
            'pm_project_id'        => $this->projectGkssc->id,
            'external_item_id'     => '20002',
            'external_item_key'    => 'GKSSC-102',
            'summary'              => 'Attribution classification test',
            'external_status'      => 'In Progress',
            'delivery_status'      => 'in_progress',
            'item_type'            => 'Task',
            'time_spent_seconds'   => 7200,
        ]);

        // Setup sequence for 3 consecutive syncWorklogs calls:
        // Scenario 1: New worklog from Tempo bot -> unattributed (no user assigned)
        // Scenario 2: Historical log exists with user_id, but synced with unrecognized author -> legacy_attributed
        // Scenario 3: Authoritative correction by verified Jira account ID -> authoritative_correction
        Http::fake([
            'https://api.atlassian.com/ex/jira/cloud_goldkamp/rest/api/3/issue/*/worklog*' => Http::sequence()
                ->push([
                    'total' => 1,
                    'worklogs' => [
                        [
                            'id'               => '991002',
                            'timeSpentSeconds' => 3600,
                            'started'          => Carbon::now()->startOfMonth()->addDays(3)->toIso8601String(),
                            'author'           => [
                                'accountId'   => '557058:295406f3-a1fc-4733-b906-dd15d021bd79',
                                'displayName' => 'Timesheets by Tempo - Jira Time Tracking',
                            ],
                        ],
                    ],
                ])
                ->push([
                    'total' => 1,
                    'worklogs' => [
                        [
                            'id'               => '991002',
                            'timeSpentSeconds' => 3600,
                            'started'          => Carbon::now()->startOfMonth()->addDays(3)->toIso8601String(),
                            'author'           => [
                                'accountId'   => 'unknown-account-id',
                                'displayName' => 'Unknown Display Name',
                            ],
                        ],
                    ],
                ])
                ->push([
                    'total' => 1,
                    'worklogs' => [
                        [
                            'id'               => '991002',
                            'timeSpentSeconds' => 3600,
                            'started'          => Carbon::now()->startOfMonth()->addDays(3)->toIso8601String(),
                            'author'           => [
                                'accountId'   => '64007b8f896d10ebd471046d',
                                'displayName' => 'Donia Khaled',
                            ],
                        ],
                    ],
                ]),
        ]);

        $provider = new JiraProvider();

        // 1. First sync: Tempo bot worklog
        $provider->syncWorklogs($item);

        $worklog = PmWorklog::where('external_worklog_id', '991002')->first();
        $this->assertNotNull($worklog);
        $this->assertNull($worklog->user_id);
        $this->assertEquals('unattributed', $worklog->attribution_source);
        $this->assertTrue($worklog->isUnattributed());

        // 2. Simulate historical assignment to other user
        $otherUser = User::create([
            'name'     => 'Legacy Engineer',
            'email'    => 'legacy@technopath.co',
            'password' => Hash::make('password'),
        ]);

        $worklog->update([
            'user_id'            => $otherUser->id,
            'attribution_source' => 'legacy_attributed',
        ]);

        // Second sync: unknown author retains legacy_attributed
        $provider->syncWorklogs($item);
        $worklog->refresh();
        $this->assertEquals($otherUser->id, $worklog->user_id);
        $this->assertEquals('legacy_attributed', $worklog->attribution_source);
        $this->assertTrue($worklog->isLegacy());

        // 3. Third sync: authoritative Atlassian account ID resolves to Donia
        $provider->syncWorklogs($item);
        $worklog->refresh();

        $this->assertEquals('authoritative_correction', $worklog->attribution_source);
        $this->assertTrue($worklog->isVerified());
        $this->assertNotNull($worklog->attribution_metadata_json);
        $this->assertEquals($otherUser->id, $worklog->attribution_metadata_json['previous_user_id']);
    }

    public function test_customer_hours_reconciliation_totals(): void
    {
        $item = PmWorkItem::create([
            'pm_connection_id'     => $this->connectionA->id,
            'client_id'            => $this->goldkamp->id,
            'pm_project_id'        => $this->projectGsm->id,
            'external_item_id'     => '20003',
            'external_item_key'    => 'GSM-10',
            'summary'              => 'GSM Reconciliation Task',
            'external_status'      => 'Done',
            'delivery_status'      => 'completed',
            'item_type'            => 'Task',
            'time_spent_seconds'   => 21600, // 6h total
        ]);

        $startDate = Carbon::now()->startOfMonth()->addDays(4);

        // 1. Verified worklog: 2 hours (7200s)
        PmWorklog::create([
            'pm_connection_id'    => $this->connectionA->id,
            'client_id'           => $this->goldkamp->id,
            'pm_work_item_id'     => $item->id,
            'external_worklog_id' => '1001',
            'user_id'             => $this->engineer->id,
            'attribution_source'  => 'jira_verified',
            'time_spent_seconds'  => 7200,
            'worklog_started_at'  => $startDate,
        ]);

        // 2. Legacy worklog: 1.5 hours (5400s)
        PmWorklog::create([
            'pm_connection_id'    => $this->connectionA->id,
            'client_id'           => $this->goldkamp->id,
            'pm_work_item_id'     => $item->id,
            'external_worklog_id' => '1002',
            'user_id'             => $this->engineer->id,
            'attribution_source'  => 'legacy_attributed',
            'time_spent_seconds'  => 5400,
            'worklog_started_at'  => $startDate,
        ]);

        // 3. Unattributed worklog: 2.5 hours (9000s)
        PmWorklog::create([
            'pm_connection_id'    => $this->connectionA->id,
            'client_id'           => $this->goldkamp->id,
            'pm_work_item_id'     => $item->id,
            'external_worklog_id' => '1003',
            'user_id'             => null,
            'attribution_source'  => 'unattributed',
            'time_spent_seconds'  => 9000,
            'worklog_started_at'  => $startDate,
        ]);

        $timeTracking = new TimeTrackingService();
        $report = $timeTracking->getManagerMonthlyHours([$this->goldkamp->id], Carbon::now());

        $clientReport = collect($report['by_customer'])->firstWhere('client_id', $this->goldkamp->id);
        $this->assertNotNull($clientReport);

        // Total hours must equal verified + legacy + unattributed
        $this->assertEquals(6.0, $clientReport['actual_hours']);
        $this->assertEquals(2.0, $clientReport['verified_hours']);
        $this->assertEquals(1.5, $clientReport['legacy_unverified_hours']);
        $this->assertEquals(2.5, $clientReport['unattributed_hours']);

        $arithmeticSum = $clientReport['verified_hours'] + $clientReport['legacy_unverified_hours'] + $clientReport['unattributed_hours'];
        $this->assertEquals($clientReport['actual_hours'], $arithmeticSum);
    }

    public function test_stale_refresh_lock_recovery(): void
    {
        Cache::put('pm_dashboard_sync_lock', true, now()->addMinutes(10));
        Cache::put('pm_dashboard_sync_batch_id', 'non-existent-batch-id', now()->addMinutes(10));

        $dashboard = new \App\Filament\Pages\EngineerDashboard();

        // Stale lock should not prevent checking or running
        $status = $dashboard->getSyncStatus();
        $this->assertNotEquals('running', $status);
    }

    public function test_shared_support_project_scoping_requires_explicit_customer_attribution(): void
    {
        $otherClient = Client::create([
            'name'              => 'Cambro Manufacturing',
            'jira_project_key'  => 'CMBR',
            'status'            => 'active',
        ]);

        $projectSup = PmProject::create([
            'pm_connection_id'     => $this->connectionA->id,
            'client_id'            => $this->goldkamp->id,
            'external_project_id'  => '90999',
            'external_project_key' => 'SUP',
            'name'                 => 'Technopath Support',
            'is_active'            => true,
        ]);

        // 1. SUP item explicitly mapped to Goldkamp
        $itemGoldkampSup = PmWorkItem::create([
            'pm_connection_id'   => $this->connectionA->id,
            'client_id'          => $this->goldkamp->id,
            'pm_project_id'      => $projectSup->id,
            'external_item_id'   => '40001',
            'external_item_key'  => 'SUP-9291',
            'summary'            => 'Goldkamp customer login issue',
            'external_status'    => 'Resolved',
            'delivery_status'    => 'completed',
            'item_type'          => 'Support',
            'time_spent_seconds' => 3600,
        ]);

        $logGoldkampSup = PmWorklog::create([
            'pm_connection_id'      => $this->connectionA->id,
            'client_id'             => $this->goldkamp->id,
            'pm_work_item_id'       => $itemGoldkampSup->id,
            'external_worklog_id'   => '500001',
            'canonical_worklog_key' => 'cloud_goldkamp:500001',
            'time_spent_seconds'    => 3600,
            'worklog_started_at'    => Carbon::now(),
            'attribution_source'    => 'jira_verified',
            'attribution_tier'      => 'verified',
            'user_id'               => $this->engineer->id,
        ]);

        // 2. SUP item explicitly mapped to Other Client (Cambro)
        $itemOtherSup = PmWorkItem::create([
            'pm_connection_id'   => $this->connectionA->id,
            'client_id'          => $otherClient->id,
            'pm_project_id'      => $projectSup->id,
            'external_item_id'   => '40002',
            'external_item_key'  => 'SUP-890',
            'summary'            => 'Cambro warranty ticket',
            'external_status'    => 'In Progress',
            'delivery_status'    => 'in_progress',
            'item_type'          => 'Support',
            'time_spent_seconds' => 7200,
        ]);

        $logOtherSup = PmWorklog::create([
            'pm_connection_id'      => $this->connectionA->id,
            'client_id'             => $otherClient->id,
            'pm_work_item_id'       => $itemOtherSup->id,
            'external_worklog_id'   => '500002',
            'canonical_worklog_key' => 'cloud_goldkamp:500002',
            'time_spent_seconds'    => 7200,
            'worklog_started_at'    => Carbon::now(),
            'attribution_source'    => 'jira_verified',
            'attribution_tier'      => 'verified',
            'user_id'               => $this->engineer->id,
        ]);

        // Scoping for Goldkamp must include Goldkamp's SUP worklog, and strictly exclude Cambro's SUP worklog
        $goldkampScopedLogs = PmWorklog::forCustomerSpacesWithJiraCode($this->goldkamp->id)->get();
        $this->assertTrue($goldkampScopedLogs->contains('id', $logGoldkampSup->id));
        $this->assertFalse($goldkampScopedLogs->contains('id', $logOtherSup->id));

        // Scoping for Cambro must include Cambro's SUP worklog, and strictly exclude Goldkamp's SUP worklog
        $cambroScopedLogs = PmWorklog::forCustomerSpacesWithJiraCode($otherClient->id)->get();
        $this->assertTrue($cambroScopedLogs->contains('id', $logOtherSup->id));
        $this->assertFalse($cambroScopedLogs->contains('id', $logGoldkampSup->id));
    }
}
