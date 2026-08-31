<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommerceHubStagingFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['staging', 'testing'])) {
            throw new RuntimeException('Commerce Hub staging fixtures may only be loaded in staging or testing.');
        }

        DB::transaction(function (): void {
            $adminEmail = env('DEVFORGE_STAGING_ADMIN_EMAIL', 'admin+staging@technopath.co');
            $admin = User::query()->where('email', $adminEmail)->first();

            if (! $admin) {
                throw new RuntimeException("The staging administrator {$adminEmail} is not configured.");
            }

            $client = Client::query()->updateOrCreate(
                ['name' => 'Technopath Internal'],
                [
                    'jira_project_key' => 'TPF',
                    'timezone' => 'America/New_York',
                    'currency' => 'USD',
                    'status' => 'active',
                    'notes' => 'Synthetic staging fixture. No production or customer data.',
                ]
            );

            $project = Project::query()->updateOrCreate(
                ['code' => 'commerce-hub'],
                [
                    'client_id' => $client->id,
                    'name' => 'Commerce Hub',
                    'description' => 'Synthetic staging configuration for Forge workflow validation.',
                    'status' => 'active',
                    'platform' => 'Laravel',
                    'jira_project_key' => 'TPF',
                    'repository_url' => 'https://github.com/jp-technopath/commerce-intelligence-hub.git',
                    'owner_id' => $admin->id,
                ]
            );

            $repository = Repository::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'name' => 'commerce-intelligence-hub',
                ],
                [
                    'client_id' => $client->id,
                    'url' => 'https://github.com/jp-technopath/commerce-intelligence-hub.git',
                    'provider' => 'github',
                    'is_active' => true,
                ]
            );

            $connection = PmConnection::query()->updateOrCreate(
                [
                    'client_id' => $client->id,
                    'provider' => 'jira',
                    'name' => 'Technopath Jira staging fixture',
                ],
                [
                    'configuration_json' => [
                        'fixture' => true,
                        'live_sync_enabled' => false,
                    ],
                    'is_active' => false,
                ]
            );

            $pmProject = PmProject::query()->updateOrCreate(
                [
                    'pm_connection_id' => $connection->id,
                    'external_project_key' => 'TPF',
                ],
                [
                    'client_id' => $client->id,
                    'name' => 'Technopath Forge (staging fixture)',
                    'external_project_id' => 'staging-fixture-tpf',
                    'is_active' => true,
                ]
            );

            PmWorkItem::query()->updateOrCreate(
                [
                    'pm_connection_id' => $connection->id,
                    'external_item_key' => 'TPF-11',
                ],
                [
                    'client_id' => $client->id,
                    'pm_project_id' => $pmProject->id,
                    'external_item_id' => 'staging-fixture-tpf-11',
                    'summary' => '[Staging fixture] Build functional Development Request intake UI',
                    'description' => 'Synthetic UAT record. This content was not synchronized from Jira.',
                    'item_type' => 'Story',
                    'priority' => 'Medium',
                    'external_status' => 'In Progress',
                    'normalized_delivery_status' => 'in_progress',
                    'is_blocked' => false,
                    'labels_json' => ['staging-fixture', 'devforge'],
                    'external_updated_at' => now(),
                    'last_synced_at' => now(),
                ]
            );

            ProjectEnvironmentMapping::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'environment_key' => 'development',
                    'version' => 1,
                ],
                [
                    'pm_project_id' => $pmProject->id,
                    'repository_id' => $repository->id,
                    'gcp_project_id' => 'development-501913',
                    'gcp_zone' => 'us-central1-a',
                    'vm_name' => 'agent-commerce-hub',
                    'worker_service_account_email' => 'agent-commerce-hub@development-501913.iam.gserviceaccount.com',
                    'workspace_path' => '/workspaces/commerce-hub',
                    'default_branch' => 'staging',
                    'allowed_agent_roles' => ['research_collector', 'lead_investigator', 'developer', 'qa'],
                    'allowed_capability_tiers' => ['junior', 'intermediate', 'senior'],
                    'default_capability_tier' => 'intermediate',
                    'tier_recommendation_policy' => [
                        'high_complexity' => 'senior',
                        'high_risk' => 'senior',
                        'cross_component' => 'senior',
                        'security_or_infrastructure' => 'senior',
                    ],
                    'model_group_aliases' => [
                        'junior' => 'forge-junior',
                        'intermediate' => 'forge-intermediate',
                        'senior' => 'forge-senior',
                    ],
                    'is_active' => true,
                    'activated_at' => now(),
                    'activated_by' => $admin->id,
                    'deactivated_at' => null,
                    'deactivated_by' => null,
                ]
            );
        });
    }
}
