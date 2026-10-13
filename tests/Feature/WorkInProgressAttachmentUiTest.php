<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkInProgress;
use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkInProgressAttachmentUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_view_details_renders_empty_attachment_notice_when_none(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = Client::create(['name' => 'Acme Inc', 'code' => 'ACME']);

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

        $workItem = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => '10001',
            'external_item_key'          => 'ACME-1',
            'summary'                    => 'Task Without Attachments',
            'normalized_delivery_status' => 'in_progress',
            'description'                => 'Some description',
            'attachments_json'           => null,
        ]);

        session(['current_client_id' => $client->id]);

        Livewire::actingAs($admin)
            ->test(WorkInProgress::class)
            ->assertTableActionVisible('view_details', $workItem->id)
            ->mountTableAction('view_details', $workItem->id)
            ->assertSee('Attachments & Media (0)', false)
            ->assertSee('No attachments uploaded for this task in Jira.');
    }

    public function test_view_details_renders_attachment_cards_when_attachments_exist(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = Client::create(['name' => 'Acme Inc', 'code' => 'ACME']);

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

        $workItem = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => '10002',
            'external_item_key'          => 'ACME-2',
            'summary'                    => 'Task With Attachments',
            'normalized_delivery_status' => 'in_progress',
            'description'                => 'Some description with image',
            'attachments_json'           => [
                [
                    'id'        => '79173',
                    'filename'  => 'workflow-screenshot.png',
                    'size'      => 204800,
                    'mime_type' => 'image/png',
                ],
                [
                    'id'        => '79174',
                    'filename'  => 'specs.pdf',
                    'size'      => 1048576,
                    'mime_type' => 'application/pdf',
                ],
            ],
        ]);

        session(['current_client_id' => $client->id]);

        Livewire::actingAs($admin)
            ->test(WorkInProgress::class)
            ->assertTableActionVisible('view_details', $workItem->id)
            ->mountTableAction('view_details', $workItem->id)
            ->assertSee('Attachments & Media (2)', false)
            ->assertSee('workflow-screenshot.png')
            ->assertSee('specs.pdf')
            ->assertSee('/pm/attachments/ACME-2/79173')
            ->assertSee('/pm/attachments/ACME-2/79174');
    }
}
