<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkInProgress;
use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkInProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_see_raw_jira_status_column(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(WorkInProgress::class)
            ->assertTableColumnVisible('external_status')
            ->assertTableColumnVisible('normalized_delivery_status')
            ->assertTableColumnVisible('external_item_key')
            ->assertTableColumnVisible('summary');
    }

    public function test_internal_staff_can_see_raw_jira_status_column(): void
    {
        $engineer = User::factory()->create(['is_admin' => false]);
        $engineerRole = Role::where('name', Role::ROLE_ENGINEER)->first();

        UserRoleAssignment::create([
            'user_id'   => $engineer->id,
            'role_id'   => $engineerRole->id,
            'client_id' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($engineer)
            ->test(WorkInProgress::class)
            ->assertTableColumnVisible('external_status')
            ->assertTableColumnVisible('normalized_delivery_status');
    }

    public function test_client_role_user_cannot_see_raw_jira_status_column(): void
    {
        $client = Client::create([
            'name'          => 'Acme Inc',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $clientUser = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($clientUser)
            ->test(WorkInProgress::class)
            ->assertTableColumnHidden('external_status')
            ->assertTableColumnVisible('normalized_delivery_status')
            ->assertTableColumnVisible('external_item_key')
            ->assertTableColumnVisible('summary');
    }

    public function test_customer_filter_is_hidden_when_user_has_access_to_single_customer(): void
    {
        $client = Client::create([
            'name'          => 'Solo Client Inc',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $clientUser = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($clientUser)
            ->test(WorkInProgress::class)
            ->assertDontSee('Customer Account Filter');
    }

    public function test_customer_filter_is_visible_when_user_has_access_to_multiple_customers(): void
    {
        $client1 = Client::create([
            'name'          => 'Client Alpha',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);
        $client2 = Client::create([
            'name'          => 'Client Beta',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $clientUser = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client1->id,
            'is_active' => true,
        ]);
        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client2->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($clientUser)
            ->test(WorkInProgress::class)
            ->assertSee('Customer Account Filter');
    }

    private function createClientAndTasks(): array
    {
        $client = Client::create([
            'name'          => 'Acme Deliveries',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);

        $connection = PmConnection::create([
            'client_id'      => $client->id,
            'provider'       => 'jira',
            'name'           => 'Acme Jira',
            'status'         => 'active',
            'last_synced_at' => now(),
        ]);

        $project = PmProject::create([
            'client_id'            => $client->id,
            'pm_connection_id'     => $connection->id,
            'external_project_id'  => 'PROJ-ACME',
            'external_project_key' => 'ACME',
            'name'                 => 'Acme Project',
        ]);

        $user = User::factory()->create(['is_admin' => true]);

        $taskReady = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => 'ACME-101',
            'external_item_key'          => 'ACME-101',
            'summary'                    => 'Feature Ready for Dev',
            'normalized_delivery_status' => 'ready',
            'external_status'            => 'Ready for Dev',
            'priority'                   => 'High',
            'issue_type'                 => 'Story',
        ]);

        $taskInProgress = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => 'ACME-102',
            'external_item_key'          => 'ACME-102',
            'summary'                    => 'Bug Fix In Progress',
            'normalized_delivery_status' => 'in_progress',
            'external_status'            => 'In Progress',
            'priority'                   => 'Highest',
            'issue_type'                 => 'Bug',
        ]);

        $taskReadyDeploy = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => 'ACME-103',
            'external_item_key'          => 'ACME-103',
            'summary'                    => 'Release Ready for Deployment',
            'normalized_delivery_status' => 'ready_for_deployment',
            'external_status'            => 'Ready For Deployment',
            'priority'                   => 'Medium',
            'issue_type'                 => 'Task',
        ]);

        $taskBacklog = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => 'ACME-104',
            'external_item_key'          => 'ACME-104',
            'summary'                    => 'Future Idea In Backlog',
            'normalized_delivery_status' => 'backlog',
            'external_status'            => 'BACKLOG',
            'priority'                   => 'Low',
            'issue_type'                 => 'Story',
        ]);

        $taskDone = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => 'ACME-105',
            'external_item_key'          => 'ACME-105',
            'summary'                    => 'Completed Checkout Optimization',
            'normalized_delivery_status' => 'completed',
            'external_status'            => 'Done',
            'priority'                   => 'Medium',
            'issue_type'                 => 'Story',
        ]);

        return [$client, $user, compact('taskReady', 'taskInProgress', 'taskReadyDeploy', 'taskBacklog', 'taskDone')];
    }

    public function test_quick_filters_toolbar_is_visible_with_accurate_counts(): void
    {
        [$client, $user] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->assertSee('Quick Filter:')
            ->assertSee('All Tasks')
            ->assertSee('Pipeline')
            ->assertSee('Ready for Dev')
            ->assertSee('In Progress')
            ->assertSee('Ready for Deployment')
            ->assertSee('Backlog')
            ->assertSee('Done');
    }

    public function test_quick_filter_pipeline_filters_to_ready_and_in_progress(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->call('setQuickFilter', 'pipeline')
            ->assertSee($tasks['taskReady']->summary)
            ->assertSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskReadyDeploy']->summary)
            ->assertDontSee($tasks['taskBacklog']->summary)
            ->assertDontSee($tasks['taskDone']->summary);
    }

    public function test_quick_filter_ready_for_dev(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->call('setQuickFilter', 'ready')
            ->assertSee($tasks['taskReady']->summary)
            ->assertDontSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskReadyDeploy']->summary)
            ->assertDontSee($tasks['taskBacklog']->summary)
            ->assertDontSee($tasks['taskDone']->summary);
    }

    public function test_quick_filter_in_progress(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->call('setQuickFilter', 'in_progress')
            ->assertSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskReady']->summary)
            ->assertDontSee($tasks['taskReadyDeploy']->summary)
            ->assertDontSee($tasks['taskBacklog']->summary)
            ->assertDontSee($tasks['taskDone']->summary);
    }

    public function test_quick_filter_ready_for_deployment(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->call('setQuickFilter', 'ready_for_deployment')
            ->assertSee($tasks['taskReadyDeploy']->summary)
            ->assertDontSee($tasks['taskReady']->summary)
            ->assertDontSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskBacklog']->summary)
            ->assertDontSee($tasks['taskDone']->summary);
    }

    public function test_quick_filter_backlog(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->call('setQuickFilter', 'backlog')
            ->assertSee($tasks['taskBacklog']->summary)
            ->assertDontSee($tasks['taskReady']->summary)
            ->assertDontSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskReadyDeploy']->summary)
            ->assertDontSee($tasks['taskDone']->summary);
    }

    public function test_quick_filter_done(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->call('setQuickFilter', 'done')
            ->assertSee($tasks['taskDone']->summary)
            ->assertDontSee($tasks['taskReady']->summary)
            ->assertDontSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskReadyDeploy']->summary)
            ->assertDontSee($tasks['taskBacklog']->summary);
    }

    public function test_clear_quick_filter_resets_to_all_tasks(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->call('setQuickFilter', 'done')
            ->assertSee($tasks['taskDone']->summary)
            ->assertDontSee($tasks['taskReady']->summary)
            ->call('clearQuickFilter')
            ->assertSee($tasks['taskReady']->summary)
            ->assertSee($tasks['taskInProgress']->summary)
            ->assertSee($tasks['taskReadyDeploy']->summary)
            ->assertSee($tasks['taskBacklog']->summary)
            ->assertSee($tasks['taskDone']->summary);
    }

    public function test_empty_state_shown_when_status_filter_has_no_matches(): void
    {
        $client = Client::create([
            'name'          => 'Empty Client',
            'industry'      => 'Retail',
            'platform_type' => 'Shopify',
            'status'        => 'active',
        ]);
        $user = User::factory()->create(['is_admin' => true]);
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(WorkInProgress::class)
            ->call('setQuickFilter', 'pipeline')
            ->assertSee('No active pipeline tasks');
    }

    public function test_incoming_quick_filter_query_param_automatically_selects_filter(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        // When navigating to Work in Pipeline with ?quick_filter=pipeline
        Livewire::actingAs($user)
            ->withQueryParams(['quick_filter' => 'pipeline'])
            ->test(WorkInProgress::class)
            ->assertSet('quick_filter', 'pipeline')
            ->assertSee($tasks['taskReady']->summary)
            ->assertSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskReadyDeploy']->summary)
            ->assertDontSee($tasks['taskBacklog']->summary)
            ->assertDontSee($tasks['taskDone']->summary);

        // When navigating to Work in Pipeline with ?quick_filter=in_progress
        Livewire::actingAs($user)
            ->withQueryParams(['quick_filter' => 'in_progress'])
            ->test(WorkInProgress::class)
            ->assertSet('quick_filter', 'in_progress')
            ->assertSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskReady']->summary)
            ->assertDontSee($tasks['taskReadyDeploy']->summary)
            ->assertDontSee($tasks['taskBacklog']->summary)
            ->assertDontSee($tasks['taskDone']->summary);
    }

    public function test_incoming_scope_query_param_maps_to_proper_quick_filter(): void
    {
        [$client, $user, $tasks] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        // Backward compatibility for ?scope=all_pipeline
        Livewire::actingAs($user)
            ->withQueryParams(['scope' => 'all_pipeline'])
            ->test(WorkInProgress::class)
            ->assertSet('quick_filter', 'pipeline')
            ->assertSee($tasks['taskReady']->summary)
            ->assertSee($tasks['taskInProgress']->summary);

        // Backward compatibility for ?scope=in_progress
        Livewire::actingAs($user)
            ->withQueryParams(['scope' => 'in_progress'])
            ->test(WorkInProgress::class)
            ->assertSet('quick_filter', 'in_progress')
            ->assertSee($tasks['taskInProgress']->summary)
            ->assertDontSee($tasks['taskReady']->summary);
    }

    public function test_customer_kpi_widget_links_to_proper_quick_filters(): void
    {
        [$client, $user] = $this->createClientAndTasks();
        session(['current_client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test(\App\Filament\Widgets\Customer\CustomerKpiWidget::class)
            ->assertSee('/admin/work-in-pipeline?quick_filter=pipeline')
            ->assertSee('/admin/work-in-pipeline?quick_filter=in_progress');
    }
}

