<?php

namespace Tests\Unit\Services\PM;

use App\Jobs\SyncPmProjectJob;
use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Services\PM\Providers\JiraProvider;
use PHPUnit\Framework\TestCase;

class JiraSyncOptimizationTest extends TestCase
{
    public function test_should_project_be_active_scopes_dedicated_connections(): void
    {
        $provider = new JiraProvider();

        $clientTranspart = new Client();
        $clientTranspart->id = 20;

        $clientCambro = new Client();
        $clientCambro->id = 10;

        $transpartConn = new PmConnection();
        $transpartConn->id = 51;
        $transpartConn->client_id = 20;

        // Dedicated connection #51 for Transpart should only activate its own project (TRAN)
        $this->assertTrue($provider->shouldProjectBeActive($transpartConn, 'TRAN', $clientTranspart));
        $this->assertFalse($provider->shouldProjectBeActive($transpartConn, 'CMBR2', $clientCambro));
        $this->assertFalse($provider->shouldProjectBeActive($transpartConn, 'RANDOM', null));
    }

    public function test_should_project_be_active_scopes_primary_connection(): void
    {
        $provider = new JiraProvider();

        $clientCambro = new Client();
        $clientCambro->id = 10;

        $clientTranspart = new Client();
        $clientTranspart->id = 20;

        $clientUnassigned = new Client();
        $clientUnassigned->id = 30;

        $primaryConn = new PmConnection();
        $primaryConn->id = 32;
        $primaryConn->client_id = 10;

        // 1. SUP (Shared desk) should always be active on primary connection #32
        $this->assertTrue($provider->shouldProjectBeActive($primaryConn, 'SUP', null));

        // 2. Primary connection activates its direct client (Cambro)
        $this->assertTrue($provider->shouldProjectBeActive($primaryConn, 'CMBR2', $clientCambro));

        // 3. Primary connection activates mapped clients that do NOT have a dedicated connection
        $dedicatedMap = [20 => 51]; // Transpart (20) has dedicated connection 51
        $this->assertTrue($provider->shouldProjectBeActive($primaryConn, 'NEWCLI', $clientUnassigned, $dedicatedMap));

        // 4. Primary connection does NOT activate Transpart because it has a dedicated connection
        $this->assertFalse($provider->shouldProjectBeActive($primaryConn, 'TRAN', $clientTranspart, $dedicatedMap));
    }

    public function test_sync_pm_project_job_properties(): void
    {
        $project = new PmProject();
        $project->id = 99;
        $job = new SyncPmProjectJob($project, 30);

        $this->assertEquals(180, $job->timeout);
        $this->assertEquals(2, $job->tries);
        $this->assertEquals(30, $job->days);
        $this->assertEquals(99, $job->project->id);
    }
}
