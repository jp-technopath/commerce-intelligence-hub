<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\AgentJob;
use App\Models\DevelopmentRequest;
use App\Models\DevelopmentRequestStatusHistory;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Repository;
use App\Models\User;
use App\Models\VmLifecycleAction;
use App\Models\VmRuntimeState;
use Database\Seeders\CommerceHubStagingFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceHubStagingFixtureSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_an_idempotent_synthetic_staging_fixture(): void
    {
        $admin = User::factory()->create([
            'name' => 'Staging Administrator',
            'email' => 'admin+staging@technopath.co',
            'is_admin' => true,
        ]);

        $this->seed(CommerceHubStagingFixtureSeeder::class);
        $this->seed(CommerceHubStagingFixtureSeeder::class);

        $this->assertSame(1, Client::query()->where('name', 'Technopath Internal')->count());
        $this->assertSame(1, Project::query()->where('code', 'commerce-hub')->count());
        $this->assertSame(1, Repository::query()->where('name', 'commerce-intelligence-hub')->count());
        $this->assertSame(1, PmProject::query()->where('external_project_key', 'TPF')->count());
        $this->assertSame(6, PmWorkItem::query()->whereHas('connection', fn ($query) => $query->where('name', 'Technopath Jira staging fixture'))->count());
        $this->assertSame(1, DevelopmentRequest::query()->where('correlation_identifier', 'staging-fixture-awaiting-approval')->count());
        $this->assertSame(6, DevelopmentRequest::query()->where('correlation_identifier', 'like', 'staging-fixture-%')->count());
        $this->assertSame(5, AgentJob::query()->whereHas('developmentRequest', fn ($query) => $query->where('correlation_identifier', 'like', 'staging-fixture-%'))->count());
        $this->assertSame(1, VmRuntimeState::query()->where('target_key', 'development-501913/us-central1-a/agent-commerce-hub')->count());
        $this->assertSame(3, VmLifecycleAction::query()->where('idempotency_key', 'like', 'staging-fixture:%')->count());

        $connection = PmConnection::query()->sole();
        $this->assertFalse($connection->is_active);
        $this->assertTrue((bool) data_get($connection->configuration_json, 'fixture'));
        $this->assertFalse((bool) data_get($connection->configuration_json, 'live_sync_enabled'));

        $workItem = PmWorkItem::query()->where('external_item_key', 'TPF-11')->sole();
        $this->assertStringStartsWith('[Staging fixture]', $workItem->summary);
        $this->assertContains('staging-fixture', $workItem->labels_json);

        $this->assertSame(26, DevelopmentRequestStatusHistory::query()
            ->whereHas('developmentRequest', fn ($query) => $query->where('correlation_identifier', 'like', 'staging-fixture-%'))
            ->count());

        $mapping = ProjectEnvironmentMapping::query()->sole();
        $this->assertTrue($mapping->is_active);
        $this->assertSame('development', $mapping->environment_key);
        $this->assertSame('staging', $mapping->default_branch);
        $this->assertSame($admin->id, $mapping->activated_by);
    }
}
