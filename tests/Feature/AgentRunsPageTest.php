<?php

namespace Tests\Feature;

use App\Enums\AgentJobStatus;
use App\Filament\Pages\Placeholders\AgentRunsPage;
use App\Models\AgentJob;
use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AgentRunsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_see_agent_jobs_and_open_details(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = Client::query()->create([
            'name' => 'Technopath Internal',
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'client_id' => $client->id,
            'name' => 'Commerce Hub',
            'code' => 'commerce-hub',
            'status' => 'active',
        ]);
        $request = DevelopmentRequest::query()->create([
            'client_id' => $client->id,
            'project_id' => $project->id,
            'owner_user_id' => $admin->id,
            'request_type' => 'development',
            'state' => 'running',
            'title' => 'Agent run fixture',
            'environment_key' => 'development',
            'source_type' => 'staging_fixture',
            'priority' => 'medium',
            'correlation_identifier' => 'staging-agent-runs-page',
        ]);
        $job = AgentJob::query()->create([
            'job_identifier' => (string) Str::uuid(),
            'development_request_id' => $request->id,
            'correlation_identifier' => 'staging-agent-job',
            'role' => 'developer',
            'status' => AgentJobStatus::Running,
            'worker_service_account_email' => 'worker@example.test',
            'payload' => ['fixture' => true],
            'payload_hash' => hash('sha256', 'staging-agent-job'),
            'attempt' => 1,
            'progress_percent' => 50,
            'progress_stage' => 'implementation',
            'progress_message' => 'Working on fixture',
        ]);

        Livewire::actingAs($admin)
            ->test(AgentRunsPage::class)
            ->assertCanSeeTableRecords([$job])
            ->callTableAction('view_details', $job)
            ->assertHasNoTableActionErrors();
    }

    public function test_client_only_user_cannot_access_agent_runs_page(): void
    {
        $client = Client::query()->create([
            'name' => 'Scoped Client',
            'status' => 'active',
        ]);
        $clientUser = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::query()->create([
            'name' => Role::ROLE_CLIENT_USER,
            'description' => 'Client portal user',
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $clientUser->id,
            'role_id' => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $this->actingAs($clientUser);

        $this->assertFalse(AgentRunsPage::canAccess());
        $this
            ->get('/admin/delivery/agent-runs')
            ->assertForbidden();
    }
}
