<?php

namespace Tests\Unit;

use App\Exceptions\ProjectEnvironmentMappingException;
use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Repository;
use App\Models\User;
use App\Services\ProjectEnvironmentResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectEnvironmentResolverTest extends TestCase
{
    private const TEST_CONNECTION = 'project_environment_resolver_test';

    private ProjectEnvironmentResolver $resolver;

    private Project $project;

    private Repository $repository;

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.'.self::TEST_CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge(self::TEST_CONNECTION);
        DB::setDefaultConnection(self::TEST_CONNECTION);

        $this->createFocusedSchema();
        $this->resolver = new ProjectEnvironmentResolver;
        $client = Client::query()->create(['name' => 'Technopath', 'status' => 'active']);
        $this->project = Project::query()->create(['client_id' => $client->id, 'name' => 'Commerce Hub', 'code' => 'commerce-hub', 'status' => 'active']);
        $this->repository = Repository::query()->create(['client_id' => $client->id, 'project_id' => $this->project->id, 'name' => 'Commerce Hub', 'url' => 'https://github.com/example/commerce-hub.git', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->originalConnection !== null) {
            DB::disconnect(self::TEST_CONNECTION);
            DB::setDefaultConnection($this->originalConnection);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_resolves_one_complete_active_mapping(): void
    {
        $mapping = $this->mapping(['is_active' => true]);
        $this->assertTrue($this->resolver->resolve($this->project->id, 'development')->is($mapping));
    }

    #[Test]
    public function it_blocks_missing_ambiguous_and_incomplete_mappings(): void
    {
        foreach (['missing', 'ambiguous', 'incomplete'] as $scenario) {
            ProjectEnvironmentMapping::query()->delete();
            if ($scenario !== 'missing') {
                $this->mapping(['is_active' => true, 'workspace_path' => $scenario === 'incomplete' ? '' : '/workspaces/one', 'version' => 1]);
            }
            if ($scenario === 'ambiguous') {
                $this->mapping(['is_active' => true, 'workspace_path' => '/workspaces/two', 'version' => 2]);
            }
            try {
                $this->resolver->resolve($this->project->id, 'development');
                $this->fail("Expected {$scenario} mapping to be blocked.");
            } catch (ProjectEnvironmentMappingException) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function it_snapshots_routing_and_rejects_disallowed_tiers(): void
    {
        $mapping = $this->mapping(['is_active' => true]);
        $request = DevelopmentRequest::query()->create([
            'client_id' => $this->project->client_id,
            'project_id' => $this->project->id,
            'owner_user_id' => User::factory()->create()->id,
            'request_type' => 'development', 'state' => 'draft', 'title' => 'Routing test',
            'environment_key' => 'development', 'source_type' => 'manual',
            'correlation_identifier' => fake()->uuid(),
        ]);

        $attached = $this->resolver->attachToRequest($request, 'senior');
        $this->assertSame($mapping->id, $attached->project_environment_mapping_id);
        $this->assertSame('senior', $attached->selected_capability_tier);
        $this->assertSame(1, $attached->routing_snapshot['mapping_version']);
        $mapping->update(['vm_name' => 'changed-later']);
        $this->assertSame('agent-commerce-hub', $attached->fresh()->routing_snapshot['gcp']['vm_name']);

        $this->expectException(ProjectEnvironmentMappingException::class);
        $this->resolver->attachToRequest($request, 'principal');
    }

    #[Test]
    public function only_an_administrator_can_activate_and_activation_is_audited(): void
    {
        $mapping = $this->mapping();
        $ordinaryUser = User::factory()->create(['is_admin' => false, 'email' => 'engineer@example.com']);
        $roleId = \DB::table('roles')->insertGetId(['name' => 'engineer', 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('user_role_assignments')->insert(['user_id' => $ordinaryUser->id, 'role_id' => $roleId, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $this->resolver->activate($mapping, $ordinaryUser);
            $this->fail('Expected administrator authorization to be enforced.');
        } catch (AuthorizationException) {
            $this->assertFalse($mapping->fresh()->is_active);
        }

        $admin = User::factory()->create(['is_admin' => true]);
        $this->resolver->activate($mapping, $admin);
        $this->assertTrue($mapping->fresh()->is_active);
        $this->assertDatabaseHas('project_environment_mapping_audits', ['project_environment_mapping_id' => $mapping->id, 'actor_id' => $admin->id, 'action' => 'activated']);
    }

    private function mapping(array $overrides = []): ProjectEnvironmentMapping
    {
        return ProjectEnvironmentMapping::query()->create(array_merge([
            'project_id' => $this->project->id,
            'repository_id' => $this->repository->id,
            'environment_key' => 'development',
            'gcp_project_id' => 'development-project',
            'gcp_zone' => 'us-central1-a',
            'vm_name' => 'agent-commerce-hub',
            'worker_service_account_email' => 'agent-commerce-hub@development-project.iam.gserviceaccount.com',
            'workspace_path' => '/workspaces/commerce-hub',
            'default_branch' => 'main',
            'allowed_agent_roles' => ['research_collector', 'lead_investigator', 'developer', 'qa'],
            'allowed_capability_tiers' => ['junior', 'intermediate', 'senior'],
            'default_capability_tier' => 'intermediate',
            'tier_recommendation_policy' => ['high_risk' => 'senior'],
            'model_group_aliases' => ['junior' => 'forge-junior', 'intermediate' => 'forge-intermediate', 'senior' => 'forge-senior'],
            'version' => 1,
            'is_active' => false,
        ], $overrides));
    }

    private function createFocusedSchema(): void
    {
        Schema::dropAllTables();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name');
            $table->string('url')->unique();
            $table->string('provider')->default('github');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pm_projects', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('project_environment_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('pm_project_id')->nullable();
            $table->unsignedBigInteger('repository_id');
            $table->string('environment_key');
            $table->string('gcp_project_id');
            $table->string('gcp_zone');
            $table->string('vm_name');
            $table->string('worker_service_account_email')->nullable();
            $table->string('workspace_path');
            $table->string('default_branch');
            $table->json('allowed_agent_roles');
            $table->json('allowed_capability_tiers');
            $table->string('default_capability_tier');
            $table->json('tier_recommendation_policy');
            $table->json('model_group_aliases');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedBigInteger('deactivated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('development_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('request_type');
            $table->string('state');
            $table->string('title');
            $table->string('environment_key');
            $table->unsignedBigInteger('project_environment_mapping_id')->nullable();
            $table->string('source_type');
            $table->string('priority')->default('medium');
            $table->string('correlation_identifier')->unique();
            $table->json('routing_snapshot')->nullable();
            $table->string('selected_capability_tier')->nullable();
            $table->timestamps();
        });
        Schema::create('project_environment_mapping_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_environment_mapping_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('snapshot');
            $table->timestamps();
        });
    }
}
