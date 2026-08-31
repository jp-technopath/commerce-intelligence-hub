<?php

namespace App\Services;

use App\Enums\DevelopmentRequestStatus;
use App\Models\DevelopmentRequest;
use App\Models\DevelopmentRequestStatusHistory;
use App\Models\PmWorkItem;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DevelopmentRequestIntakeService
{
    public function __construct(private readonly ProjectEnvironmentResolver $resolver) {}

    public function createDraft(User $owner, array $attributes): DevelopmentRequest
    {
        $data = $this->validated($attributes);
        $project = Project::query()->findOrFail($data['project_id']);

        if (! $owner->hasPermission('development_requests.create', $project->client_id, $project->id)) {
            throw new AuthorizationException('You are not authorized to create a request for this project.');
        }

        $mapping = $this->resolver->resolve($project->id, $data['environment_key']);
        $workItem = $this->resolveWorkItem($project, $mapping, $data['pm_work_item_id']);

        return DB::transaction(function () use ($data, $mapping, $owner, $project, $workItem): DevelopmentRequest {
            $request = DevelopmentRequest::query()->create([
                'client_id' => $project->client_id,
                'project_id' => $project->id,
                'owner_user_id' => $owner->getKey(),
                'request_type' => $data['request_type'],
                'state' => DevelopmentRequestStatus::Draft,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'environment_key' => $data['environment_key'],
                'source_type' => 'jira',
                'source_id' => $workItem->external_item_key,
                'priority' => $data['priority'],
                'correlation_identifier' => 'devreq_'.Str::lower((string) Str::ulid()),
                'jira_snapshot' => $this->jiraSnapshot($workItem),
                'pm_work_item_id' => $workItem->getKey(),
            ]);

            $this->resolver->attachToRequest($request, $data['selected_capability_tier']);
            $this->recordCreation($request, $owner, $mapping);

            return $request->fresh([
                'client',
                'project',
                'owner',
                'pmWorkItem',
                'projectEnvironmentMapping',
                'statusHistory',
            ]);
        });
    }

    public function updateDraft(DevelopmentRequest $request, User $actor, array $attributes): DevelopmentRequest
    {
        if (! in_array($request->state, [
            DevelopmentRequestStatus::Draft,
            DevelopmentRequestStatus::ChangesRequested,
        ], true)) {
            throw new RuntimeException('Only draft requests or requests with changes requested may be edited.');
        }

        if (! $actor->isSuperAdmin() && (int) $request->owner_user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('Only the request owner may edit this request.');
        }

        $data = $this->validated($attributes);
        $project = Project::query()->findOrFail($data['project_id']);

        if (
            ! $actor->hasPermission('development_requests.update', $project->client_id, $project->id)
            && ! $actor->hasPermission('development_requests.create', $project->client_id, $project->id)
        ) {
            throw new AuthorizationException('You are not authorized to update this request.');
        }

        $mapping = $this->resolver->resolve($project->id, $data['environment_key']);
        $workItem = $this->resolveWorkItem($project, $mapping, $data['pm_work_item_id']);

        return DB::transaction(function () use ($actor, $data, $mapping, $project, $request, $workItem): DevelopmentRequest {
            $request->forceFill([
                'client_id' => $project->client_id,
                'project_id' => $project->id,
                'request_type' => $data['request_type'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'environment_key' => $data['environment_key'],
                'source_type' => 'jira',
                'source_id' => $workItem->external_item_key,
                'priority' => $data['priority'],
                'jira_snapshot' => $this->jiraSnapshot($workItem),
                'pm_work_item_id' => $workItem->getKey(),
                'project_environment_mapping_id' => null,
                'routing_snapshot' => null,
                'selected_capability_tier' => null,
            ])->save();

            $this->resolver->attachToRequest($request, $data['selected_capability_tier']);

            DevelopmentRequestStatusHistory::query()->create([
                'development_request_id' => $request->getKey(),
                'old_state' => $request->state->value,
                'new_state' => $request->state->value,
                'actor_user_id' => $actor->getKey(),
                'actor_type' => 'user',
                'actor_label' => $actor->name,
                'reason' => 'Request details and routing snapshot updated.',
                'correlation_identifier' => $request->correlation_identifier,
                'metadata' => [
                    'event' => 'request_updated',
                    'mapping_id' => $mapping->getKey(),
                    'mapping_version' => $mapping->version,
                ],
            ]);

            return $request->fresh([
                'client',
                'project',
                'owner',
                'pmWorkItem',
                'projectEnvironmentMapping',
                'statusHistory',
            ]);
        });
    }

    private function validated(array $attributes): array
    {
        return Validator::make($attributes, [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'pm_work_item_id' => ['required', 'integer', 'exists:pm_work_items,id'],
            'request_type' => ['required', 'in:investigation,development'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'environment_key' => ['required', 'string', 'max:100'],
            'selected_capability_tier' => ['required', 'in:junior,intermediate,senior,principal'],
            'priority' => ['required', 'in:low,medium,high,critical'],
        ])->validate();
    }

    private function resolveWorkItem(
        Project $project,
        ProjectEnvironmentMapping $mapping,
        int $workItemId
    ): PmWorkItem {
        $workItem = PmWorkItem::query()->findOrFail($workItemId);

        if ((int) $workItem->client_id !== (int) $project->client_id) {
            throw ValidationException::withMessages([
                'pm_work_item_id' => 'The Jira ticket does not belong to the selected project client.',
            ]);
        }

        if ($mapping->pm_project_id !== null && (int) $workItem->pm_project_id !== (int) $mapping->pm_project_id) {
            throw ValidationException::withMessages([
                'pm_work_item_id' => 'The Jira ticket does not belong to the Jira project mapped to this environment.',
            ]);
        }

        return $workItem;
    }

    private function jiraSnapshot(PmWorkItem $workItem): array
    {
        return [
            'key' => $workItem->external_item_key,
            'id' => $workItem->external_item_id,
            'summary' => $workItem->summary,
            'description' => $workItem->description,
            'acceptance_criteria' => null,
            'issue_type' => $workItem->item_type,
            'priority' => $workItem->priority,
            'labels' => $workItem->labels_json ?? [],
            'url' => null,
            'status' => $workItem->external_status,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function recordCreation(
        DevelopmentRequest $request,
        User $owner,
        ProjectEnvironmentMapping $mapping
    ): void {
        DevelopmentRequestStatusHistory::query()->create([
            'development_request_id' => $request->getKey(),
            'old_state' => DevelopmentRequestStatus::Draft->value,
            'new_state' => DevelopmentRequestStatus::Draft->value,
            'actor_user_id' => $owner->getKey(),
            'actor_type' => 'user',
            'actor_label' => $owner->name,
            'reason' => 'Request created as a draft.',
            'correlation_identifier' => $request->correlation_identifier,
            'metadata' => [
                'event' => 'request_created',
                'mapping_id' => $mapping->getKey(),
                'mapping_version' => $mapping->version,
            ],
        ]);
    }
}
