<?php

namespace Database\Seeders;

use App\Enums\ActionItemSource;
use App\Enums\ActionItemStatus;
use App\Enums\ClientStatus;
use App\Enums\ConnectedAccountStatus;
use App\Enums\MeetingSource;
use App\Enums\MeetingStatus;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\ConnectedAccount;
use App\Models\CustomerAttentionItem;
use App\Models\ForgeApprovalEvent;
use App\Models\ForgeEstimateVersion;
use App\Models\MeetingActionItem;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CustomerPortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Get or create Client 1
        $client1 = Client::firstOrCreate(
            ['name' => 'BrightServe Foodservice Group'],
            [
                'industry'      => 'Commercial Foodservice & Hospitality',
                'platform_type' => 'Shopify',
                'status'        => ClientStatus::Active,
            ]
        );

        // 2. Create Product Owner / Sync Identity User
        $syncUser = User::firstOrCreate(
            ['email' => 'nour@technopath.ai'],
            [
                'name'     => 'Nour Ayman (Product Owner)',
                'password' => Hash::make('changeme123!'),
            ]
        );

        $poRole = \App\Models\Role::where('name', \App\Models\Role::ROLE_PRODUCT_OWNER)->first();
        if ($poRole) {
            UserRoleAssignment::firstOrCreate(
                ['user_id' => $syncUser->id, 'client_id' => $client1->id],
                ['role_id' => $poRole->id, 'is_active' => true]
            );
        }

        // 3. Create Connected Account for Nour
        ConnectedAccount::firstOrCreate(
            ['user_id' => $syncUser->id, 'provider' => 'jira'],
            [
                'authorized_email' => 'nour@technopath.ai',
                'credentials_json' => ['access_token' => 'mock_jira_access_token', 'cloud_id' => 'cloud_brightserve_123'],
                'status'           => ConnectedAccountStatus::Active,
                'token_expires_at' => now()->addDays(30),
            ]
        );

        // 4. Create PM Connection
        $connection = PmConnection::firstOrCreate(
            ['client_id' => $client1->id, 'provider' => 'jira'],
            [
                'name'                 => 'BrightServe Jira Workspace',
                'external_workspace_id'=> 'cloud_brightserve_123',
                'default_sync_user_id' => $syncUser->id,
                'is_active'            => true,
                'last_synced_at'       => now()->subMinutes(12),
            ]
        );

        // 5. Create PM Projects
        $project1 = PmProject::firstOrCreate(
            ['pm_connection_id' => $connection->id, 'external_project_key' => 'CMBR2'],
            [
                'client_id' => $client1->id,
                'name'      => 'BrightServe Checkout v2',
                'is_active' => true,
            ]
        );

        $project2 = PmProject::firstOrCreate(
            ['pm_connection_id' => $connection->id, 'external_project_key' => 'CMBR-SUPPORT'],
            [
                'client_id' => $client1->id,
                'name'      => 'BrightServe Support & Maintenance',
                'is_active' => true,
            ]
        );

        // 6. Create PM Work Items
        $task1 = PmWorkItem::firstOrCreate(
            ['pm_connection_id' => $connection->id, 'external_item_key' => 'CMBR2-421'],
            [
                'client_id'                  => $client1->id,
                'pm_project_id'              => $project1->id,
                'external_item_id'           => '2001',
                'summary'                    => 'B2B Quantity Selector & Minimum Order Rules',
                'description'                => 'Implement bulk order quantity selector for restaurant procurement managers.',
                'item_type'                  => 'Story',
                'external_status'            => 'In Development',
                'normalized_delivery_status' => 'in_progress',
                'estimated_seconds'          => 50400, // 14 hours
                'time_spent_seconds'         => 28800, // 8 hours
                'assignee_name'              => 'Alex Engineer',
                'target_due_date'            => now()->addDays(5),
            ]
        );

        $task2 = PmWorkItem::firstOrCreate(
            ['pm_connection_id' => $connection->id, 'external_item_key' => 'CMBR2-422'],
            [
                'client_id'                  => $client1->id,
                'pm_project_id'              => $project1->id,
                'external_item_id'           => '2002',
                'summary'                    => 'Winter Promotion Discount Applied at Cart',
                'description'                => 'Apply 15% discount automatically for orders exceeding $2,500.',
                'item_type'                  => 'Task',
                'external_status'            => 'Customer UAT',
                'normalized_delivery_status' => 'customer_review',
                'estimated_seconds'          => 43200, // 12 hours
                'time_spent_seconds'         => 39600, // 11 hours
                'assignee_name'              => 'Sarah Dev',
                'target_due_date'            => now()->addDays(2),
            ]
        );

        $task3 = PmWorkItem::firstOrCreate(
            ['pm_connection_id' => $connection->id, 'external_item_key' => 'CMBR2-425'],
            [
                'client_id'                  => $client1->id,
                'pm_project_id'              => $project1->id,
                'external_item_id'           => '2003',
                'summary'                    => 'Klaviyo B2B Abandoned Cart Sequence Integration',
                'description'                => 'Trigger custom Klaviyo flow when B2B buyer leaves wholesale cart.',
                'item_type'                  => 'Story',
                'external_status'            => 'To Do',
                'normalized_delivery_status' => 'ready',
                'estimated_seconds'          => 57600, // 16 hours
                'time_spent_seconds'         => 0,
                'assignee_name'              => 'Unassigned',
                'target_due_date'            => now()->addDays(12),
            ]
        );

        $task4 = PmWorkItem::firstOrCreate(
            ['pm_connection_id' => $connection->id, 'external_item_key' => 'CMBR-SUPPORT-88'],
            [
                'client_id'                  => $client1->id,
                'pm_project_id'              => $project2->id,
                'external_item_id'           => '2004',
                'summary'                    => 'Payment Gateway Response Time Optimization',
                'description'                => 'Optimize payload handling to reduce gateway checkout latency.',
                'item_type'                  => 'Bug',
                'external_status'            => 'Done',
                'normalized_delivery_status' => 'completed',
                'estimated_seconds'          => 28800, // 8 hours
                'time_spent_seconds'         => 28800, // 8 hours
                'assignee_name'              => 'Alex Engineer',
                'target_due_date'            => now()->subDays(3),
            ]
        );

        // 7. Estimate Version & Approvals for Task 3 (Pending Approval)
        $v1_task3 = ForgeEstimateVersion::firstOrCreate(
            ['pm_work_item_id' => $task3->id, 'version' => 1],
            [
                'estimated_seconds'                => 57600,
                'external_estimate_at_submission' => 57600,
                'submitted_by_user_id'             => $syncUser->id,
                'submitted_at'                     => now()->subDays(1),
                'po_notes'                         => 'Includes custom Klaviyo API integration and template design.',
                'cost_impact_amount'               => 2400.00,
            ]
        );

        ForgeApprovalEvent::firstOrCreate(
            ['estimate_version_id' => $v1_task3->id, 'event_type' => 'submitted'],
            ['actor_user_id' => $syncUser->id, 'notes' => 'Submitted for customer approval']
        );

        // 8. Estimate Version & Reapproval for Task 1
        $v1_task1 = ForgeEstimateVersion::firstOrCreate(
            ['pm_work_item_id' => $task1->id, 'version' => 1],
            [
                'estimated_seconds'                => 36000, // 10 hrs approved
                'external_estimate_at_submission' => 36000,
                'submitted_by_user_id'             => $syncUser->id,
                'submitted_at'                     => now()->subDays(10),
                'po_notes'                         => 'Initial scope approved.',
            ]
        );

        ForgeApprovalEvent::firstOrCreate(
            ['estimate_version_id' => $v1_task1->id, 'event_type' => 'approved'],
            ['actor_user_id' => $syncUser->id, 'notes' => 'Approved by customer']
        );

        // V2 created due to Jira estimate increase (14 hrs)
        $v2_task1 = ForgeEstimateVersion::firstOrCreate(
            ['pm_work_item_id' => $task1->id, 'version' => 2],
            [
                'estimated_seconds'                => 50400, // 14 hrs
                'external_estimate_at_submission' => 50400,
                'submitted_by_user_id'             => $syncUser->id,
                'submitted_at'                     => now()->subHours(2),
                'po_notes'                         => 'Reapproval required: Jira estimate increased to 14 hours to cover additional bulk tiered pricing rules.',
                'cost_impact_amount'               => 600.00,
            ]
        );

        ForgeApprovalEvent::firstOrCreate(
            ['estimate_version_id' => $v1_task1->id, 'event_type' => 'reapproval_required'],
            ['notes' => 'Jira estimate changed from 10h to 14h.']
        );

        ForgeApprovalEvent::firstOrCreate(
            ['estimate_version_id' => $v2_task1->id, 'event_type' => 'submitted'],
            ['actor_user_id' => $syncUser->id, 'notes' => 'Reapproval submitted']
        );

        // 9. Customer Attention Items
        CustomerAttentionItem::firstOrCreate(
            ['client_id' => $client1->id, 'category' => 'estimate_approval', 'source_id' => (string) $v1_task3->id],
            [
                'title'       => 'Estimate Approval Required: CMBR2-425',
                'description' => 'Proposed estimate v1: 16.0 hrs for Klaviyo B2B Abandoned Cart Sequence Integration',
                'severity'    => 'urgent',
                'source_type' => 'jira',
                'is_resolved' => false,
            ]
        );

        CustomerAttentionItem::firstOrCreate(
            ['client_id' => $client1->id, 'category' => 'estimate_reapproval', 'source_id' => (string) $v2_task1->id],
            [
                'title'       => 'Reapproval Required: CMBR2-421',
                'description' => 'Jira estimate updated to 14.0 hrs (Previously approved: 10.0 hrs) for B2B Quantity Selector',
                'severity'    => 'urgent',
                'source_type' => 'jira',
                'is_resolved' => false,
            ]
        );

        CustomerAttentionItem::firstOrCreate(
            ['client_id' => $client1->id, 'category' => 'action_item_overdue'],
            [
                'title'       => 'Customer Commitment Overdue: Send Product Catalog CSV',
                'description' => 'Action item from August Monthly Strategy Meeting assigned to Customer Admin is 2 days overdue.',
                'severity'    => 'warning',
                'source_type' => 'meeting',
                'is_resolved' => false,
            ]
        );

        // 10. Worklogs across past 12 months for historical consumption
        for ($m = 0; $m < 12; $m++) {
            $monthDate = now()->subMonths($m)->startOfMonth()->addDays(5);
            $secondsLogged = rand(25, 45) * 3600;

            PmWorklog::firstOrCreate(
                [
                    'pm_connection_id'    => $connection->id,
                    'external_worklog_id' => "wl-demo-m{$m}",
                ],
                [
                    'client_id'          => $client1->id,
                    'pm_work_item_id'    => $task4->id,
                    'author_name'        => 'Alex Engineer',
                    'time_spent_seconds' => $secondsLogged,
                    'worklog_started_at' => $monthDate,
                    'last_synced_at'     => now(),
                ]
            );
        }

        // 11. Client Meetings & Action Items
        $meeting = ClientMeeting::firstOrCreate(
            ['client_id' => $client1->id, 'title' => 'August Monthly Strategy & Delivery Review'],
            [
                'meeting_start_at'  => now()->subDays(4),
                'meeting_end_at'    => now()->subDays(4)->addHour(),
                'internal_owner_id' => $syncUser->id,
                'status'            => MeetingStatus::Detected,
                'source'            => MeetingSource::Manual,
            ]
        );

        MeetingActionItem::firstOrCreate(
            ['client_meeting_id' => $meeting->id, 'title' => 'Finalize checkout B2B quantity selector UI design'],
            [
                'description'        => 'Review wireframes for quantity inputs on mobile and desktop.',
                'owner_name'         => 'Technopath',
                'due_date'           => now()->addDays(3),
                'status'             => ActionItemStatus::InProgress,
                'source'             => ActionItemSource::Manual,
                'jira_issue_key'     => 'CMBR2-421',
                'is_customer_facing' => true,
            ]
        );

        MeetingActionItem::firstOrCreate(
            ['client_meeting_id' => $meeting->id, 'title' => 'Send Product Catalog CSV for Klaviyo sync'],
            [
                'description'        => 'Customer team to provide updated wholesale product catalog feed.',
                'owner_name'         => 'BrightServe Customer Team',
                'due_date'           => now()->subDays(2),
                'status'             => ActionItemStatus::Open,
                'source'             => ActionItemSource::Manual,
                'jira_issue_key'     => 'CMBR2-425',
                'is_customer_facing' => true,
            ]
        );

        $this->command->info('CustomerPortalDemoSeeder completed successfully!');
    }
}
