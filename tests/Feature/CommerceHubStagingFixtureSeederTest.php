<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Repository;
use App\Models\User;
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
        $this->assertSame(1, PmWorkItem::query()->where('external_item_key', 'TPF-11')->count());

        $connection = PmConnection::query()->sole();
        $this->assertFalse($connection->is_active);
        $this->assertTrue((bool) data_get($connection->configuration_json, 'fixture'));
        $this->assertFalse((bool) data_get($connection->configuration_json, 'live_sync_enabled'));

        $workItem = PmWorkItem::query()->sole();
        $this->assertStringStartsWith('[Staging fixture]', $workItem->summary);
        $this->assertContains('staging-fixture', $workItem->labels_json);

        $mapping = ProjectEnvironmentMapping::query()->sole();
        $this->assertTrue($mapping->is_active);
        $this->assertSame('development', $mapping->environment_key);
        $this->assertSame('staging', $mapping->default_branch);
        $this->assertSame($admin->id, $mapping->activated_by);
    }
}
