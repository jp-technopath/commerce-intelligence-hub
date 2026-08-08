<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminPagesRenderTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
        ]);
    }

    /**
     * Test static admin page routes.
     *
     * @dataProvider adminRoutesProvider
     */
    public function test_admin_static_pages_render_successfully(string $route): void
    {
        $response = $this->actingAs($this->adminUser)->get($route);

        $response->assertStatus(200);
    }

    public static function adminRoutesProvider(): array
    {
        return [
            'Dashboard' => ['/admin'],
            'Customer Dashboard' => ['/admin/customer-dashboard'],
            'PM Connections' => ['/admin/pm-connections'],
            'Business Dashboard' => ['/admin/dashboard/business'],
            'Clients Index' => ['/admin/clients'],
            'Clients Create' => ['/admin/clients/create'],
            'Clients Contacts' => ['/admin/clients/contacts'],
            'Clients Environments' => ['/admin/clients/environments'],
            'Integrations Index' => ['/admin/integrations'],
            'Integrations Create' => ['/admin/integrations/create'],
            'Projects Index' => ['/admin/projects'],
            'Projects Create' => ['/admin/projects/create'],
            'Findings Index' => ['/admin/findings'],
            'Knowledge Base Index' => ['/admin/knowledge-base'],
            'Project Briefs' => ['/admin/intelligence/project-briefs'],
            'Recommendations' => ['/admin/intelligence/recommendations'],
            'Development Requests' => ['/admin/delivery/requests'],
            'Agent Runs' => ['/admin/delivery/agent-runs'],
            'Pull Requests' => ['/admin/delivery/pull-requests'],
            'Approvals' => ['/admin/delivery/approvals'],
            'Testing' => ['/admin/delivery/testing'],
            'Deployments Index' => ['/admin/deployments'],
            'Deployments Create' => ['/admin/deployments/create'],
            'Deployment Approvals' => ['/admin/deployments/approvals'],
            'Release Calendar' => ['/admin/deployments/release-calendar'],
            'Meetings Index' => ['/admin/client-meetings'],
            'Meetings Create' => ['/admin/client-meetings/create'],
            'Budgets' => ['/admin/financials/budgets'],
            'AI Spend' => ['/admin/financials/ai-spend'],
            'Infrastructure Spend' => ['/admin/financials/infrastructure-spend'],
            'Billing Review' => ['/admin/financials/billing-review'],
            'Users Index' => ['/admin/users'],
            'Roles Index' => ['/admin/roles'],
            'Permissions Index' => ['/admin/permissions'],
            'Integration Settings' => ['/admin/administration/integration-settings'],
            'Agent Settings' => ['/admin/administration/agent-settings'],
            'System Settings' => ['/admin/administration/system-settings'],
        ];
    }

    /**
     * Test record-level detail pages and sub-navigation tabs.
     */
    public function test_record_detail_and_sub_navigation_pages_render_successfully(): void
    {
        $client = Client::create([
            'name' => 'Acme Test Client',
            'industry' => 'Retail',
            'platform_type' => 'Shopify',
            'status' => \App\Enums\ClientStatus::Active,
        ]);

        $project = Project::create([
            'client_id' => $client->id,
            'name' => 'Acme Mobile App',
            'code' => 'ACME-MOB',
            'status' => \App\Enums\ProjectStatus::Active,
            'owner_id' => $this->adminUser->id,
        ]);

        $finding = Finding::create([
            'client_id' => $client->id,
            'title' => 'High Cart Abandonment',
            'finding_type' => 'CRO',
            'finding_category' => \App\Enums\FindingCategory::Conversion,
            'severity' => \App\Enums\FindingSeverity::High,
            'status' => \App\Enums\FindingStatus::New,
        ]);

        $intelligenceMemory = \App\Models\IntelligenceMemory::create([
            'client_id' => $client->id,
            'finding_type' => 'CRO',
            'finding_category' => \App\Enums\FindingCategory::Conversion,
            'pattern_description' => 'Cart abandonment pattern observed during checkout flow.',
            'root_cause' => 'Slow payment gateway response time.',
            'resolution' => 'Optimized API payload.',
            'outcome' => '15% decrease in abandonment.',
        ]);

        $meeting = ClientMeeting::create([
            'client_id' => $client->id,
            'title' => 'Weekly Sync',
            'meeting_start_at' => now(),
            'meeting_end_at' => now()->addHour(),
            'internal_owner_id' => $this->adminUser->id,
            'status' => \App\Enums\MeetingStatus::Detected,
            'source' => \App\Enums\MeetingSource::Manual,
        ]);

        $recordRoutes = [
            "/admin/clients/{$client->id}",
            "/admin/clients/{$client->id}/edit",
            "/admin/projects/{$project->id}",
            "/admin/projects/{$project->id}/requirements",
            "/admin/projects/{$project->id}/agent-tasks",
            "/admin/projects/{$project->id}/code-prs",
            "/admin/projects/{$project->id}/deployments",
            "/admin/projects/{$project->id}/settings",
            "/admin/projects/{$project->id}/edit",
            "/admin/findings/{$finding->id}",
            "/admin/knowledge-base/{$intelligenceMemory->id}",
            "/admin/client-meetings/{$meeting->id}",
            "/admin/client-meetings/{$meeting->id}/edit",
        ];

        foreach ($recordRoutes as $route) {
            $response = $this->actingAs($this->adminUser)->get($route);
            $response->assertStatus(200);
        }
    }
}
