<?php

namespace Tests\Feature;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Filament\Pages\BusinessDashboard;
use App\Filament\Resources\FindingResource;
use App\Filament\Widgets\RecentFindingsWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Client;
use App\Models\Finding;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\Intelligence\DeliveryFindingEvaluator;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class InternalClientDashboardExclusionTest extends TestCase
{
    use RefreshDatabase;

    private Client $externalClient;
    private Client $internalClient;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->externalClient = Client::create([
            'name'             => 'Acme External Store',
            'industry'         => 'ecommerce',
            'platform_type'    => 'adobe_commerce',
            'jira_project_key' => 'ACME',
            'status'           => 'active',
            'is_internal'      => false,
        ]);

        $this->internalClient = Client::create([
            'name'             => 'Technopath Internal',
            'industry'         => 'agency',
            'platform_type'    => 'custom',
            'jira_project_key' => 'TEC',
            'status'           => 'active',
            'is_internal'      => true,
        ]);

        $this->adminUser = User::create([
            'name'     => 'Super Admin',
            'email'    => 'admin@technopath.co',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);
    }

    public function test_client_is_internal_helper_and_scopes(): void
    {
        $this->assertFalse($this->externalClient->isInternal());
        $this->assertTrue($this->internalClient->isInternal());

        $externalIds = Client::external()->pluck('id')->all();
        $this->assertContains($this->externalClient->id, $externalIds);
        $this->assertNotContains($this->internalClient->id, $externalIds);

        $internalIds = Client::internal()->pluck('id')->all();
        $this->assertContains($this->internalClient->id, $internalIds);
        $this->assertNotContains($this->externalClient->id, $internalIds);
    }

    public function test_stats_overview_widget_excludes_internal_clients_and_findings(): void
    {
        Finding::create([
            'client_id'                 => $this->externalClient->id,
            'fingerprint'               => 'ext_finding_1',
            'finding_type'              => 'revenue_anomaly',
            'finding_category'          => FindingCategory::Revenue,
            'title'                     => 'External Client Finding',
            'severity'                  => FindingSeverity::High,
            'status'                    => FindingStatus::New,
            'visibility_classification' => 'internal',
            'detected_at'               => now(),
        ]);

        Finding::create([
            'client_id'                 => $this->internalClient->id,
            'fingerprint'               => 'int_finding_1',
            'finding_type'              => 'task_overdue',
            'finding_category'          => FindingCategory::WorkflowDelay,
            'title'                     => 'Internal Overdue Ticket',
            'severity'                  => FindingSeverity::Critical,
            'status'                    => FindingStatus::New,
            'visibility_classification' => 'internal',
            'detected_at'               => now(),
        ]);

        $widget = new class extends StatsOverviewWidget {
            public function invokeGetStats(): array
            {
                return $this->getStats();
            }
        };

        $stats = $widget->invokeGetStats();

        // Total Clients should be 1 (external only), not 2
        $this->assertSame(1, $stats[0]->getValue());

        // Clients Needing Attention should be 1 (external only)
        $this->assertSame(1, $stats[1]->getValue());

        // Open Findings should be 1 (external finding only), internal excluded
        $this->assertSame(1, $stats[2]->getValue());

        // High / Critical Findings should be 1 (external finding only), internal excluded
        $this->assertSame(1, $stats[3]->getValue());
    }

    public function test_recent_findings_widget_excludes_internal_client_findings(): void
    {
        Auth::login($this->adminUser);

        Finding::create([
            'client_id'                 => $this->externalClient->id,
            'fingerprint'               => 'ext_finding_recent',
            'finding_type'              => 'revenue_anomaly',
            'finding_category'          => FindingCategory::Revenue,
            'title'                     => 'External Client Finding',
            'severity'                  => FindingSeverity::High,
            'status'                    => FindingStatus::New,
            'visibility_classification' => 'internal',
            'detected_at'               => now(),
        ]);

        Finding::create([
            'client_id'                 => $this->internalClient->id,
            'fingerprint'               => 'int_finding_recent',
            'finding_type'              => 'task_overdue',
            'finding_category'          => FindingCategory::WorkflowDelay,
            'title'                     => 'Internal Overdue Ticket',
            'severity'                  => FindingSeverity::Critical,
            'status'                    => FindingStatus::New,
            'visibility_classification' => 'internal',
            'detected_at'               => now(),
        ]);

        $widget = new class extends RecentFindingsWidget {
            public function resolveQuery(): \Illuminate\Database\Eloquent\Builder
            {
                $table = Table::make($this);
                return $this->table($table)->getQuery();
            }
        };

        $findings = $widget->resolveQuery()->get();

        $this->assertCount(1, $findings);
        $this->assertSame('External Client Finding', $findings->first()->title);
    }

    public function test_finding_resource_eloquent_query_excludes_internal_client_findings(): void
    {
        Auth::login($this->adminUser);

        Finding::create([
            'client_id'                 => $this->externalClient->id,
            'fingerprint'               => 'ext_finding_resource',
            'finding_type'              => 'revenue_anomaly',
            'finding_category'          => FindingCategory::Revenue,
            'title'                     => 'External Client Finding',
            'severity'                  => FindingSeverity::High,
            'status'                    => FindingStatus::New,
            'visibility_classification' => 'internal',
            'detected_at'               => now(),
        ]);

        Finding::create([
            'client_id'                 => $this->internalClient->id,
            'fingerprint'               => 'int_finding_resource',
            'finding_type'              => 'task_overdue',
            'finding_category'          => FindingCategory::WorkflowDelay,
            'title'                     => 'Internal Overdue Ticket',
            'severity'                  => FindingSeverity::Critical,
            'status'                    => FindingStatus::New,
            'visibility_classification' => 'internal',
            'detected_at'               => now(),
        ]);

        $findings = FindingResource::getEloquentQuery()->get();

        $this->assertCount(1, $findings);
        $this->assertSame($this->externalClient->id, $findings->first()->client_id);
    }

    public function test_business_dashboard_get_clients_excludes_internal_clients(): void
    {
        Auth::login($this->adminUser);

        $dashboard = new BusinessDashboard();
        $clients = $dashboard->getClients();

        $this->assertArrayHasKey($this->externalClient->id, $clients);
        $this->assertArrayNotHasKey($this->internalClient->id, $clients);
    }

    public function test_delivery_finding_evaluator_skips_internal_clients(): void
    {
        $connExt = PmConnection::create([
            'client_id' => $this->externalClient->id,
            'provider'  => 'jira',
            'name'      => 'Acme Jira',
            'base_url'  => 'https://acme.atlassian.net',
            'status'    => 'active',
            'auth_type' => 'oauth2',
        ]);

        $connInt = PmConnection::create([
            'client_id' => $this->internalClient->id,
            'provider'  => 'jira',
            'name'      => 'Internal Jira',
            'base_url'  => 'https://technopath.atlassian.net',
            'status'    => 'active',
            'auth_type' => 'oauth2',
        ]);

        $pmProjectExt = PmProject::create([
            'client_id'            => $this->externalClient->id,
            'pm_connection_id'     => $connExt->id,
            'name'                 => 'Acme Project',
            'external_project_key' => 'ACME',
        ]);

        $pmProjectInt = PmProject::create([
            'client_id'            => $this->internalClient->id,
            'pm_connection_id'     => $connInt->id,
            'name'                 => 'Internal Engineering',
            'external_project_key' => 'TEC',
        ]);

        // Overdue item for external client
        PmWorkItem::create([
            'client_id'                  => $this->externalClient->id,
            'pm_connection_id'           => $connExt->id,
            'pm_project_id'              => $pmProjectExt->id,
            'external_item_id'           => '1001',
            'external_item_key'          => 'ACME-101',
            'summary'                    => 'Overdue client deliverable',
            'normalized_delivery_status' => 'in_progress',
            'priority'                   => 'High',
            'target_due_date'            => now()->subDays(2),
        ]);

        // Overdue item for internal client
        PmWorkItem::create([
            'client_id'                  => $this->internalClient->id,
            'pm_connection_id'           => $connInt->id,
            'pm_project_id'              => $pmProjectInt->id,
            'external_item_id'           => '1002',
            'external_item_key'          => 'TEC-202',
            'summary'                    => 'Overdue internal sprint task',
            'normalized_delivery_status' => 'in_progress',
            'priority'                   => 'High',
            'target_due_date'            => now()->subDays(5),
        ]);

        $evaluator = new DeliveryFindingEvaluator();
        $evaluator->evaluateAll();

        $this->assertTrue(Finding::where('client_id', $this->externalClient->id)->exists());
        $this->assertFalse(Finding::where('client_id', $this->internalClient->id)->exists());
    }
}
