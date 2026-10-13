<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\PM\Providers\JiraProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class PmAttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Ensure temp cache directory is clean
        $cacheDir = storage_path('app/jira-attachments');
        if (File::exists($cacheDir)) {
            File::deleteDirectory($cacheDir);
        }
    }

    protected function tearDown(): void
    {
        $cacheDir = storage_path('app/jira-attachments');
        if (File::exists($cacheDir)) {
            File::deleteDirectory($cacheDir);
        }
        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_access_attachment(): void
    {
        $response = $this->get('/pm/attachments/TEST-100/12345');
        $response->assertRedirect('/login');
    }

    public function test_user_without_client_permission_is_forbidden(): void
    {
        $clientA = Client::create(['name' => 'Client A', 'code' => 'CL_A']);
        $clientB = Client::create(['name' => 'Client B', 'code' => 'CL_B']);

        $clientUser = User::factory()->create(['is_admin' => false, 'email' => 'client@customer.com']);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $clientUser->id,
            'role_id'   => $clientRole->id,
            'client_id' => $clientA->id,
            'is_active' => true,
        ]);

        $connection = PmConnection::create([
            'client_id'      => $clientB->id,
            'provider'       => 'jira',
            'name'           => 'Jira B',
            'status'         => 'active',
            'last_synced_at' => now(),
        ]);

        $project = PmProject::create([
            'client_id'            => $clientB->id,
            'pm_connection_id'     => $connection->id,
            'external_project_id'  => 'PROJ-B',
            'external_project_key' => 'TESTB',
            'name'                 => 'Project B',
        ]);

        $workItem = PmWorkItem::create([
            'client_id'                  => $clientB->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => '10001',
            'external_item_key'          => 'TEST-B-1',
            'summary'                    => 'Client B Task',
            'normalized_delivery_status' => 'in_progress',
            'attachments_json'           => [
                [
                    'id'        => '55555',
                    'filename'  => 'secret.png',
                    'mime_type' => 'image/png',
                    'size'      => 1024,
                ],
            ],
        ]);

        $response = $this->actingAs($clientUser)->get('/pm/attachments/TEST-B-1/55555');
        $response->assertStatus(403);
    }

    public function test_authorized_user_can_stream_attachment_and_it_is_cached(): void
    {
        $client = Client::create(['name' => 'Client A', 'code' => 'CL_A2']);
        $user = User::factory()->create(['is_admin' => false, 'email' => 'user@customer.com']);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->first();

        UserRoleAssignment::create([
            'user_id'   => $user->id,
            'role_id'   => $clientRole->id,
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $connection = PmConnection::create([
            'client_id'      => $client->id,
            'provider'       => 'jira',
            'name'           => 'Jira A',
            'status'         => 'active',
            'last_synced_at' => now(),
        ]);

        $project = PmProject::create([
            'client_id'            => $client->id,
            'pm_connection_id'     => $connection->id,
            'external_project_id'  => 'PROJ-A',
            'external_project_key' => 'TESTA',
            'name'                 => 'Project A',
        ]);

        $fakePngData = "\x89PNG\r\n\x1a\nfake-binary-stream";

        $workItem = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => '10002',
            'external_item_key'          => 'TEST-A-1',
            'summary'                    => 'Client A Task',
            'normalized_delivery_status' => 'in_progress',
            'attachments_json'           => [
                [
                    'id'        => '79173',
                    'filename'  => 'diagram.png',
                    'mime_type' => 'image/png',
                    'size'      => strlen($fakePngData),
                ],
            ],
        ]);

        $jiraMock = Mockery::mock(JiraProvider::class);
        $jiraMock->shouldReceive('getAttachmentContent')
            ->once()
            ->with(Mockery::any(), '79173', false, Mockery::any())
            ->andReturn([
                'body'         => $fakePngData,
                'content_type' => 'image/png',
                'status'       => 200,
            ]);

        $this->app->instance(JiraProvider::class, $jiraMock);

        // First request - should hit provider and write to disk
        $response = $this->actingAs($user)->get('/pm/attachments/TEST-A-1/79173');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Content-Disposition', 'inline; filename="diagram.png"');
        $this->assertEquals($fakePngData, $response->getContent());

        // Verify disk cache exists
        $cacheFile = storage_path("app/jira-attachments/{$workItem->id}_79173");
        $this->assertFileExists($cacheFile);
        $this->assertEquals($fakePngData, File::get($cacheFile));

        // Second request - should serve directly from disk cache without calling provider again
        $cachedResponse = $this->actingAs($user)->get('/pm/attachments/TEST-A-1/79173');
        $cachedResponse->assertStatus(200);
        $cachedResponse->assertHeader('Content-Type', 'image/png');
        $this->assertEquals($fakePngData, $cachedResponse->getContent());
    }

    public function test_supports_download_query_param(): void
    {
        $client = Client::create(['name' => 'Client Doc', 'code' => 'CL_DOC']);
        $admin = User::factory()->create(['is_admin' => true]);

        $connection = PmConnection::create([
            'client_id'      => $client->id,
            'provider'       => 'jira',
            'name'           => 'Jira Doc',
            'status'         => 'active',
            'last_synced_at' => now(),
        ]);

        $project = PmProject::create([
            'client_id'            => $client->id,
            'pm_connection_id'     => $connection->id,
            'external_project_id'  => 'PROJ-DOC',
            'external_project_key' => 'TESTDOC',
            'name'                 => 'Project Doc',
        ]);

        $docData = "PDF-SAMPLE-BINARY";

        $workItem = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => '10003',
            'external_item_key'          => 'TEST-DOC-1',
            'summary'                    => 'Task with PDF',
            'normalized_delivery_status' => 'in_progress',
            'attachments_json'           => [
                [
                    'id'        => '90001',
                    'filename'  => 'invoice.pdf',
                    'mime_type' => 'application/pdf',
                    'size'      => strlen($docData),
                ],
            ],
        ]);

        $jiraMock = Mockery::mock(JiraProvider::class);
        $jiraMock->shouldReceive('getAttachmentContent')
            ->once()
            ->with(Mockery::any(), '90001', false, Mockery::any())
            ->andReturn([
                'body'         => $docData,
                'content_type' => 'application/pdf',
                'status'       => 200,
            ]);

        $this->app->instance(JiraProvider::class, $jiraMock);

        $response = $this->actingAs($admin)->get('/pm/attachments/TEST-DOC-1/90001?download=1');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="invoice.pdf"');
        $this->assertEquals($docData, $response->getContent());
    }

    public function test_supports_thumbnail_mode(): void
    {
        $client = Client::create(['name' => 'Client Thumb', 'code' => 'CL_THUMB']);
        $admin = User::factory()->create(['is_admin' => true]);

        $connection = PmConnection::create([
            'client_id'      => $client->id,
            'provider'       => 'jira',
            'name'           => 'Jira Thumb',
            'status'         => 'active',
            'last_synced_at' => now(),
        ]);

        $project = PmProject::create([
            'client_id'            => $client->id,
            'pm_connection_id'     => $connection->id,
            'external_project_id'  => 'PROJ-THUMB',
            'external_project_key' => 'TESTTHUMB',
            'name'                 => 'Project Thumb',
        ]);

        $thumbData = "THUMBNAIL-RAW-DATA";

        $workItem = PmWorkItem::create([
            'client_id'                  => $client->id,
            'pm_connection_id'           => $connection->id,
            'pm_project_id'              => $project->id,
            'external_item_id'           => '10004',
            'external_item_key'          => 'TEST-THUMB-1',
            'summary'                    => 'Task with Thumbnail',
            'normalized_delivery_status' => 'in_progress',
            'attachments_json'           => [
                [
                    'id'        => '90002',
                    'filename'  => 'preview.png',
                    'mime_type' => 'image/png',
                    'size'      => 500,
                ],
            ],
        ]);

        $jiraMock = Mockery::mock(JiraProvider::class);
        $jiraMock->shouldReceive('getAttachmentContent')
            ->once()
            ->with(Mockery::any(), '90002', true, Mockery::any())
            ->andReturn([
                'body'         => $thumbData,
                'content_type' => 'image/png',
                'status'       => 200,
            ]);

        $this->app->instance(JiraProvider::class, $jiraMock);

        $response = $this->actingAs($admin)->get('/pm/attachments/TEST-THUMB-1/90002?thumbnail=1');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertEquals($thumbData, $response->getContent());

        // Cached file should have _thumb suffix
        $thumbCacheFile = storage_path("app/jira-attachments/{$workItem->id}_90002_thumb");
        $this->assertFileExists($thumbCacheFile);
    }

    public function test_returns_404_when_work_item_not_found(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($admin)->get('/pm/attachments/NON-EXISTENT/99999');
        $response->assertStatus(404);
    }
}
