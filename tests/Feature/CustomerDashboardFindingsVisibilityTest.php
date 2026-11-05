<?php

namespace Tests\Feature;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Filament\Pages\BusinessDashboard;
use App\Filament\Resources\FindingResource;
use App\Models\Client;
use App\Models\Finding;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\VisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDashboardFindingsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_dashboard_get_findings_summary_excludes_internal_and_delivery_findings(): void
    {
        $superAdmin = User::create([
            'name'     => 'Super Admin',
            'email'    => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);
        $this->actingAs($superAdmin);

        $client = Client::create([
            'name'        => 'Test Client',
            'is_internal' => false,
        ]);

        // Customer-visible eCommerce finding
        $customerFinding = Finding::create([
            'client_id'                 => $client->id,
            'title'                     => 'Conversion Rate Drop (-23.86%)',
            'finding_type'              => 'conversion_rate_drop',
            'finding_category'          => FindingCategory::Conversion,
            'severity'                  => FindingSeverity::High,
            'status'                    => FindingStatus::New,
            'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
            'is_customer_visible'       => true,
            'detected_at'               => now()->subDays(2),
        ]);

        // Internal delivery finding (Overdue task)
        $deliveryFinding = Finding::create([
            'client_id'                 => $client->id,
            'title'                     => 'Overdue task: [CMBR2-2188] Product Page Design',
            'finding_type'              => 'task_overdue',
            'finding_category'          => FindingCategory::WorkflowDelay,
            'severity'                  => FindingSeverity::High,
            'status'                    => FindingStatus::New,
            'visibility_classification' => VisibilityService::CLASSIFICATION_INTERNAL,
            'is_customer_visible'       => false,
            'detected_at'               => now()->subDays(1),
        ]);

        // Internal QA rework finding
        $qaFinding = Finding::create([
            'client_id'                 => $client->id,
            'title'                     => 'QA Rework Spike on Checkout',
            'finding_type'              => 'qa_rework',
            'finding_category'          => FindingCategory::WorkflowDelay,
            'severity'                  => FindingSeverity::Medium,
            'status'                    => FindingStatus::New,
            'visibility_classification' => VisibilityService::CLASSIFICATION_INTERNAL,
            'is_customer_visible'       => false,
            'detected_at'               => now()->subDays(3),
        ]);

        $dashboard = new BusinessDashboard();
        $dashboard->selectedClientId = $client->id;

        $summary = $dashboard->getFindingsSummary();

        $this->assertEquals(1, $summary['total'], 'Dashboard must only count customer-visible findings.');
        $this->assertEquals(1, $summary['open']);
        $this->assertCount(1, $summary['recent']);
        $this->assertEquals('Conversion Rate Drop (-23.86%)', $summary['recent']->first()->title);
        $this->assertFalse($summary['recent']->contains('id', $deliveryFinding->id));
        $this->assertFalse($summary['recent']->contains('id', $qaFinding->id));
    }

    public function test_finding_scopes_correctly_separate_customer_visible_and_internal_findings(): void
    {
        $client = Client::create([
            'name' => 'Store Corp',
        ]);

        $visibleFinding = Finding::create([
            'client_id'                 => $client->id,
            'title'                     => 'Revenue Drop Detected',
            'finding_type'              => 'revenue_decrease',
            'finding_category'          => FindingCategory::Revenue,
            'severity'                  => FindingSeverity::High,
            'status'                    => FindingStatus::New,
            'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
            'is_customer_visible'       => true,
            'detected_at'               => now(),
        ]);

        $internalJiraFinding = Finding::create([
            'client_id'                 => $client->id,
            'title'                     => 'Overdue task: [CMBR2-1286] PLP/PDP Styling',
            'finding_type'              => 'task_overdue',
            'finding_category'          => FindingCategory::WorkflowDelay,
            'severity'                  => FindingSeverity::Medium,
            'status'                    => FindingStatus::New,
            'visibility_classification' => VisibilityService::CLASSIFICATION_INTERNAL,
            'is_customer_visible'       => false,
            'detected_at'               => now(),
        ]);

        $this->assertTrue($visibleFinding->isCustomerVisible());
        $this->assertFalse($visibleFinding->isInternal());

        $this->assertFalse($internalJiraFinding->isCustomerVisible());
        $this->assertTrue($internalJiraFinding->isInternal());

        $visibleIds = Finding::customerVisible()->pluck('id')->all();
        $internalIds = Finding::internal()->pluck('id')->all();

        $this->assertContains($visibleFinding->id, $visibleIds);
        $this->assertNotContains($internalJiraFinding->id, $visibleIds);

        $this->assertContains($internalJiraFinding->id, $internalIds);
        $this->assertNotContains($visibleFinding->id, $internalIds);
    }

    public function test_finding_resource_eloquent_query_scopes_customer_roles_to_customer_visible_only(): void
    {
        $client = Client::create([
            'name' => 'Brand Corp',
        ]);

        $visibleFinding = Finding::create([
            'client_id'                 => $client->id,
            'title'                     => 'Friction Score Increased',
            'finding_type'              => 'friction_score_increase',
            'finding_category'          => FindingCategory::Behavioral,
            'severity'                  => FindingSeverity::Medium,
            'status'                    => FindingStatus::New,
            'visibility_classification' => VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
            'is_customer_visible'       => true,
            'detected_at'               => now(),
        ]);

        $internalFinding = Finding::create([
            'client_id'                 => $client->id,
            'title'                     => 'Overdue task: [CMBR2-9999] Checkout Fix',
            'finding_type'              => 'task_overdue',
            'finding_category'          => FindingCategory::WorkflowDelay,
            'severity'                  => FindingSeverity::High,
            'status'                    => FindingStatus::New,
            'visibility_classification' => VisibilityService::CLASSIFICATION_INTERNAL,
            'is_customer_visible'       => false,
            'detected_at'               => now(),
        ]);

        // Customer user with Customer Admin role
        $customerRole = Role::firstOrCreate(['name' => Role::ROLE_CUSTOMER_ADMIN]);
        $customerUser = User::create([
            'name'              => 'Customer Admin User',
            'email'             => 'client@brandcorp.com',
            'password'          => bcrypt('password'),
            'is_admin'          => false,
            'current_client_id' => $client->id,
        ]);

        UserRoleAssignment::create([
            'user_id'   => $customerUser->id,
            'role_id'   => $customerRole->id,
            'client_id' => $client->id,
            'status'    => 'active',
        ]);

        $this->actingAs($customerUser);

        $results = FindingResource::getEloquentQuery()->get();
        $this->assertCount(1, $results);
        $this->assertEquals($visibleFinding->id, $results->first()->id);
        $this->assertFalse($results->contains('id', $internalFinding->id));
    }
}
