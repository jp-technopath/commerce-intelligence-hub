<?php

namespace App\Services;

use App\Enums\AgentCapabilityTier;
use App\Exceptions\ProjectEnvironmentMappingException;
use App\Models\DevelopmentRequest;
use App\Models\ProjectEnvironmentMapping;
use App\Models\ProjectEnvironmentMappingAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectEnvironmentResolver
{
    public function validate(ProjectEnvironmentMapping $mapping): array
    {
        $errors = [];
        if (! $mapping->project_id) {
            $errors[] = 'A Forge project is required.';
        }
        if (! $mapping->repository_id || ! $mapping->repository?->is_active) {
            $errors[] = 'An active repository is required.';
        }
        if ($mapping->repository && $mapping->repository->project_id !== $mapping->project_id) {
            $errors[] = 'The repository must belong to the selected Forge project.';
        }
        if (! $mapping->environment_key) {
            $errors[] = 'An environment is required.';
        }
        if (! $mapping->gcp_project_id || ! $mapping->gcp_zone || ! $mapping->vm_name || ! $mapping->workspace_path) {
            $errors[] = 'The execution target is incomplete.';
        }
        if (
            ! $mapping->worker_service_account_email
            || ! preg_match(
                '/^[a-z0-9][a-z0-9._-]*@[a-z][a-z0-9-]{4,61}[a-z0-9]\.iam\.gserviceaccount\.com$/',
                $mapping->worker_service_account_email
            )
        ) {
            $errors[] = 'A valid mapped VM service-account identity is required.';
        }
        if (! str_starts_with((string) $mapping->workspace_path, '/')) {
            $errors[] = 'The workspace path must be absolute.';
        }
        if (! $mapping->default_branch) {
            $errors[] = 'A default branch is required.';
        }
        if (empty($mapping->allowed_agent_roles)) {
            $errors[] = 'At least one agent role is required.';
        }

        $tiers = $mapping->allowed_capability_tiers ?? [];
        $validTiers = array_column(AgentCapabilityTier::cases(), 'value');
        if (empty($tiers) || array_diff($tiers, $validTiers)) {
            $errors[] = 'Allowed capability tiers are invalid.';
        }
        if (! in_array($mapping->default_capability_tier?->value, $tiers, true)) {
            $errors[] = 'The default capability tier must be allowed.';
        }

        foreach ($tiers as $tier) {
            if (empty($mapping->model_group_aliases[$tier])) {
                $errors[] = "A model-group alias is required for the {$tier} tier.";
            }
        }

        return array_values(array_unique($errors));
    }

    public function activate(ProjectEnvironmentMapping $mapping, User $actor): ProjectEnvironmentMapping
    {
        Gate::forUser($actor)->authorize('update', $mapping);
        $errors = $this->validate($mapping);
        if ($errors) {
            throw new ProjectEnvironmentMappingException(implode(' ', $errors));
        }

        return DB::transaction(function () use ($mapping, $actor) {
            $duplicate = ProjectEnvironmentMapping::query()
                ->where('project_id', $mapping->project_id)
                ->where('environment_key', $mapping->environment_key)
                ->where('is_active', true)
                ->whereKeyNot($mapping->getKey())
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                throw new ProjectEnvironmentMappingException('Another active mapping already exists for this project and environment.');
            }

            $mapping->forceFill(['is_active' => true, 'activated_at' => now(), 'activated_by' => $actor->id, 'deactivated_at' => null, 'deactivated_by' => null])->save();
            $this->audit($mapping, $actor, 'activated');

            return $mapping->refresh();
        });
    }

    public function resolve(int $projectId, string $environmentKey): ProjectEnvironmentMapping
    {
        $matches = ProjectEnvironmentMapping::query()
            ->with(['project', 'repository', 'pmProject'])
            ->where('project_id', $projectId)
            ->where('environment_key', $environmentKey)
            ->where('is_active', true)
            ->get();
        if ($matches->isEmpty()) {
            throw new ProjectEnvironmentMappingException('No active execution mapping exists for the selected project and environment.');
        }
        if ($matches->count() > 1) {
            throw new ProjectEnvironmentMappingException('Multiple active execution mappings exist; an administrator must correct the configuration.');
        }
        $mapping = $matches->first();
        $errors = $this->validate($mapping);
        if ($errors) {
            throw new ProjectEnvironmentMappingException('The execution mapping is incomplete: '.implode(' ', $errors));
        }

        return $mapping;
    }

    public function attachToRequest(DevelopmentRequest $request, ?string $tier = null): DevelopmentRequest
    {
        $mapping = $this->resolve($request->project_id, $request->environment_key);
        $selectedTier = $tier ?: $mapping->default_capability_tier->value;
        if (! in_array($selectedTier, $mapping->allowed_capability_tiers, true)) {
            throw new ProjectEnvironmentMappingException('The selected capability tier is not allowed for this project and environment.');
        }
        $request->forceFill([
            'project_environment_mapping_id' => $mapping->id,
            'routing_snapshot' => $mapping->snapshot(),
            'selected_capability_tier' => $selectedTier,
        ])->save();

        return $request->refresh();
    }

    private function audit(ProjectEnvironmentMapping $mapping, User $actor, string $action): void
    {
        ProjectEnvironmentMappingAudit::create([
            'project_environment_mapping_id' => $mapping->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'snapshot' => $mapping->snapshot(),
        ]);
    }
}
