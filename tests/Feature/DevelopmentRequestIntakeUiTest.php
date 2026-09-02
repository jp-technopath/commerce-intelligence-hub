<?php

namespace Tests\Feature;

use App\Enums\DevelopmentRequestStatus;
use App\Filament\Resources\DevelopmentRequestResource\Pages\CreateDevelopmentRequest;
use App\Filament\Resources\DevelopmentRequestResource\Pages\ListDevelopmentRequests;
use App\Filament\Resources\DevelopmentRequestResource\Pages\ViewDevelopmentRequest;
use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Repository;
use App\Models\User;
use App\Services\DevelopmentRequestIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DevelopmentRequestIntakeUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private Project $project;

    private PmWorkItem $workItem;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('devforge.orchestration_enabled', false);

        $this->admin = User::factory()->create([
            'name' => 'Staging Admin',
            'email' => 'admin+staging@technopath.co',
            'is_admin' => true,
        ]);

        $this->client = Client::query()->create([
            'name' => 'Technopath Internal',
            'status' => 'active',
        ]);

        $this->project = Project::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Commerce Hub',
            'code' => 'TPF',
            'status' => 'active',
            'jira_project_key' => 'TPF',
        ]);

        $repository = Repository::query()->create([
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'name' => 'commerce-intelligence-hub',
            'url' => 'https://github.com/jp-technopath/commerce-intelligence-hub.git',
            'provider' => 'github',
            'is_active' => true,
        ]);

        $connection = PmConnection::query()->create([
            'client_id' => $this->client->id,
            'provider' => 'jira',
            'name' => 'Technopath Jira',
            'is_active' => true,
        ]);

        $pmProject = PmProject::query()->create([
            'client_id' => $this->client->id,
            'pm_connection_id' => $connection->id,
            'name' => 'Technopath Forge',
            'external_project_id' => '10001',
            'external_project_key' => 'TPF',
            'is_active' => true,
        ]);

        $this->workItem = PmWorkItem::query()->create([
            'client_id' => $this->client->id,
            'pm_connection_id' => $connection->id,
            'pm_project_id' => $pmProject->id,
            'external_item_id' => '11011',
            'external_item_key' => 'TPF-11',
            'summary' => 'Build development request intake UI',
            'description' => 'Create, review, and explicitly start an approved agent workflow.',
            'item_type' => 'Story',
            'priority' => 'High',
            'external_status' => 'In Progress',
            'labels_json' => ['devforge', 'delivery'],
        ]);

        ProjectEnvironmentMapping::query()->create([
            'project_id' => $this->project->id,
            'pm_project_id' => $pmProject->id,
            'repository_id' => $repository->id,
            'environment_key' => 'development',
            'gcp_project_id' => 'development-501913',
            'gcp_zone' => 'us-central1-a',
            'vm_name' => 'agent-commerce-hub',
            'worker_service_account_email' => 'agent-commerce-hub@development-501913.iam.gserviceaccount.com',
            'workspace_path' => '/workspaces/commerce-hub',
            'default_branch' => 'staging',
            'allowed_agent_roles' => ['research_collector', 'lead_investigator', 'developer', 'qa'],
            'allowed_capability_tiers' => ['junior', 'intermediate', 'senior'],
            'default_capability_tier' => 'intermediate',
            'tier_recommendation_policy' => ['high_risk' => 'senior'],
            'model_group_aliases' => [
                'junior' => 'forge-junior',
                'intermediate' => 'forge-intermediate',
                'senior' => 'forge-senior',
            ],
            'version' => 1,
            'is_active' => true,
            'activated_at' => now(),
            'activated_by' => $this->admin->id,
        ]);
    }

    public function test_an_admin_can_create_a_routed_draft_from_a_synced_jira_ticket(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateDevelopmentRequest::class)
            ->fillForm([
                'request_type' => 'development',
                'priority' => 'high',
                'title' => 'Build development request intake UI',
                'description' => 'Use the approved routing and preserve the Jira context.',
                'project_id' => $this->project->id,
                'environment_key' => 'development',
                'selected_capability_tier' => 'senior',
                'pm_work_item_id' => $this->workItem->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = DevelopmentRequest::query()->sole();

        $this->assertSame(DevelopmentRequestStatus::Draft, $request->state);
        $this->assertSame($this->admin->id, $request->owner_user_id);
        $this->assertSame('TPF-11', $request->jira_snapshot['key']);
        $this->assertSame('senior', $request->selected_capability_tier);
        $this->assertSame('agent-commerce-hub', data_get($request->routing_snapshot, 'gcp.vm_name'));
        $this->assertDatabaseHas('development_request_status_histories', [
            'development_request_id' => $request->id,
            'new_state' => DevelopmentRequestStatus::Draft->value,
            'actor_user_id' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListDevelopmentRequests::class)
            ->assertCanSeeTableRecords([$request]);
    }

    public function test_start_agent_is_an_explicit_audited_ui_action(): void
    {
        Queue::fake();

        $request = app(DevelopmentRequestIntakeService::class)->createDraft($this->admin, [
            'pm_work_item_id' => $this->workItem->id,
            'request_type' => 'investigation',
            'priority' => 'medium',
            'title' => 'Investigate TPF-11 delivery risk',
            'description' => 'Collect evidence before recommending implementation changes.',
            'project_id' => $this->project->id,
            'environment_key' => 'development',
            'selected_capability_tier' => 'intermediate',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewDevelopmentRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('submit')
            ->assertHasNoActionErrors()
            ->assertActionHidden('submit')
            ->assertActionHidden('edit');

        $this->assertSame(DevelopmentRequestStatus::Queued, $request->fresh()->state);
        $this->assertDatabaseHas('development_request_status_histories', [
            'development_request_id' => $request->id,
            'old_state' => DevelopmentRequestStatus::Draft->value,
            'new_state' => DevelopmentRequestStatus::Queued->value,
            'actor_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('filament.admin.resources.delivery.requests.edit', $request))
            ->assertForbidden();
    }

    public function test_a_jira_ticket_from_another_client_cannot_be_attached(): void
    {
        $otherClient = Client::query()->create([
            'name' => 'Other Client',
            'status' => 'active',
        ]);

        $otherConnection = PmConnection::query()->create([
            'client_id' => $otherClient->id,
            'provider' => 'jira',
            'name' => 'Other Jira',
            'is_active' => true,
        ]);

        $otherItem = PmWorkItem::query()->create([
            'client_id' => $otherClient->id,
            'pm_connection_id' => $otherConnection->id,
            'external_item_id' => '999',
            'external_item_key' => 'OTHER-9',
            'summary' => 'Unrelated issue',
        ]);

        $this->expectException(ValidationException::class);

        app(DevelopmentRequestIntakeService::class)->createDraft($this->admin, [
            'pm_work_item_id' => $otherItem->id,
            'request_type' => 'development',
            'priority' => 'medium',
            'title' => 'Should be rejected',
            'project_id' => $this->project->id,
            'environment_key' => 'development',
            'selected_capability_tier' => 'intermediate',
        ]);
    }
}
