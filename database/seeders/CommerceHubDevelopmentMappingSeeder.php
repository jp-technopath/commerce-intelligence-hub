<?php

namespace Database\Seeders;

use App\Models\PmProject;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Repository;
use Illuminate\Database\Seeder;
use RuntimeException;

class CommerceHubDevelopmentMappingSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::query()
            ->where('code', 'commerce-hub')
            ->orWhere('jira_project_key', 'TPF')
            ->first();

        if (! $project) {
            throw new RuntimeException('Commerce Hub Forge project is not configured.');
        }

        $repository = Repository::query()
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->first();

        if (! $repository) {
            throw new RuntimeException('Commerce Hub has no active repository.');
        }

        $pmProject = PmProject::query()
            ->where('external_project_key', 'TPF')
            ->where('is_active', true)
            ->first();

        ProjectEnvironmentMapping::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'environment_key' => 'development',
                'version' => 1,
            ],
            [
                'pm_project_id' => $pmProject?->id,
                'repository_id' => $repository->id,
                'gcp_project_id' => 'development-501913',
                'gcp_zone' => 'us-central1-a',
                'vm_name' => 'agent-commerce-hub',
                'worker_service_account_email' => env(
                    'FORGE_COMMERCE_HUB_WORKER_SERVICE_ACCOUNT',
                    'dev-coder@development-501913.iam.gserviceaccount.com'
                ),
                'workspace_path' => '/workspaces/commerce-hub',
                'default_branch' => 'main',
                'allowed_agent_roles' => ['research_collector', 'lead_investigator', 'developer', 'qa'],
                'allowed_capability_tiers' => ['junior', 'intermediate', 'senior', 'principal'],
                'default_capability_tier' => 'intermediate',
                'tier_recommendation_policy' => [
                    'high_complexity' => 'senior',
                    'high_risk' => 'senior',
                    'cross_component' => 'senior',
                    'security_or_infrastructure' => 'principal',
                ],
                'model_group_aliases' => [
                    'junior' => env('FORGE_MODEL_GROUP_JUNIOR', 'forge-junior'),
                    'intermediate' => env('FORGE_MODEL_GROUP_INTERMEDIATE', 'forge-intermediate'),
                    'senior' => env('FORGE_MODEL_GROUP_SENIOR', 'forge-senior'),
                    'principal' => env('FORGE_MODEL_GROUP_PRINCIPAL', 'forge-principal'),
                ],
                'is_active' => false,
            ]
        );
    }
}
