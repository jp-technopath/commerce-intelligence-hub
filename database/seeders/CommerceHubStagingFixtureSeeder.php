<?php

namespace Database\Seeders;

use App\Enums\AgentJobStatus;
use App\Enums\DevelopmentRequestStatus;
use App\Models\AgentJob;
use App\Models\AgentJobEvent;
use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\DevelopmentRequestStatusHistory;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Repository;
use App\Models\User;
use App\Models\VmLifecycleAction;
use App\Models\VmRuntimeState;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommerceHubStagingFixtureSeeder extends Seeder
{
    private const FIXTURE_LABEL = 'staging-fixture';

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

            $client = $this->seedClient();
            $project = $this->seedProject($client, $admin);
            $repository = $this->seedRepository($client, $project);
            [$connection, $pmProject] = $this->seedPmScope($client);
            $workItems = $this->seedWorkItems($client, $connection, $pmProject);
            $mapping = $this->seedMapping($project, $pmProject, $repository, $admin);
            $requests = $this->seedRequests($client, $project, $mapping, $workItems, $admin);
            $runtime = $this->seedRuntimeState($mapping);

            $this->seedLifecycleActions($runtime, $requests['running'], $admin);
            $this->seedAgentJobs($mapping, $requests, $workItems);
        });
    }

    private function seedClient(): Client
    {
        return Client::query()->updateOrCreate(
            ['name' => 'Technopath Internal'],
            [
                'jira_project_key' => 'TPF',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'status' => 'active',
                'notes' => 'Synthetic staging fixture. No production or customer data.',
            ],
        );
    }

    private function seedProject(Client $client, User $admin): Project
    {
        return Project::query()->updateOrCreate(
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
            ],
        );
    }

    private function seedRepository(Client $client, Project $project): Repository
    {
        return Repository::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'name' => 'commerce-intelligence-hub',
            ],
            [
                'client_id' => $client->id,
                'url' => 'https://github.com/jp-technopath/commerce-intelligence-hub.git',
                'provider' => 'github',
                'is_active' => true,
            ],
        );
    }

    /** @return array{0: PmConnection, 1: PmProject} */
    private function seedPmScope(Client $client): array
    {
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
            ],
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
            ],
        );

        return [$connection, $pmProject];
    }

    /** @return array<string, PmWorkItem> */
    private function seedWorkItems(Client $client, PmConnection $connection, PmProject $pmProject): array
    {
        $definitions = [
            'TPF-11' => [
                'summary' => '[Staging fixture] Build functional Development Request intake UI',
                'description' => 'Synthetic UAT record for creating and routing a development request.',
                'item_type' => 'Story',
                'priority' => 'High',
                'external_status' => 'In Progress',
                'normalized_delivery_status' => 'in_progress',
            ],
            'TPF-12' => [
                'summary' => '[Staging fixture] Review approval handoff',
                'description' => 'Synthetic request waiting for an authorized human approval decision.',
                'item_type' => 'Task',
                'priority' => 'Medium',
                'external_status' => 'To Do',
                'normalized_delivery_status' => 'ready',
            ],
            'TPF-13' => [
                'summary' => '[Staging fixture] Validate worker callback',
                'description' => 'Synthetic in-flight request for worker progress and audit checks.',
                'item_type' => 'Task',
                'priority' => 'High',
                'external_status' => 'In Progress',
                'normalized_delivery_status' => 'in_progress',
            ],
            'TPF-14' => [
                'summary' => '[Staging fixture] Confirm QA evidence capture',
                'description' => 'Synthetic QA review item with representative acceptance criteria.',
                'item_type' => 'Story',
                'priority' => 'Medium',
                'external_status' => 'In Review',
                'normalized_delivery_status' => 'review_qa',
            ],
            'TPF-15' => [
                'summary' => '[Staging fixture] Publish release checklist',
                'description' => 'Synthetic completed item for historical delivery views.',
                'item_type' => 'Task',
                'priority' => 'Low',
                'external_status' => 'Done',
                'normalized_delivery_status' => 'completed',
            ],
            'TPF-16' => [
                'summary' => '[Staging fixture] Investigate blocked webhook',
                'description' => 'Synthetic blocked item for failure and escalation paths.',
                'item_type' => 'Bug',
                'priority' => 'Critical',
                'external_status' => 'Blocked',
                'normalized_delivery_status' => 'in_progress',
                'is_blocked' => true,
                'blocked_reason' => 'Waiting for a synthetic callback payload.',
            ],
        ];

        $items = [];
        foreach ($definitions as $key => $definition) {
            $items[$key] = PmWorkItem::query()->updateOrCreate(
                [
                    'pm_connection_id' => $connection->id,
                    'external_item_key' => $key,
                ],
                array_merge(
                    [
                        'client_id' => $client->id,
                        'pm_project_id' => $pmProject->id,
                        'external_item_id' => 'staging-fixture-'.strtolower($key),
                        'is_blocked' => false,
                        'blocked_reason' => null,
                        'labels_json' => [self::FIXTURE_LABEL, 'devforge'],
                        'external_updated_at' => now(),
                        'last_synced_at' => now(),
                    ],
                    $definition,
                ),
            );
        }

        return $items;
    }

    private function seedMapping(
        Project $project,
        PmProject $pmProject,
        Repository $repository,
        User $admin,
    ): ProjectEnvironmentMapping {
        return ProjectEnvironmentMapping::query()->updateOrCreate(
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
            ],
        );
    }

    /** @return array<string, DevelopmentRequest> */
    private function seedRequests(
        Client $client,
        Project $project,
        ProjectEnvironmentMapping $mapping,
        array $workItems,
        User $admin,
    ): array {
        $definitions = [
            'draft' => [
                'work_item' => 'TPF-11',
                'request_type' => 'development',
                'state' => DevelopmentRequestStatus::Draft->value,
                'priority' => 'high',
                'tier' => 'intermediate',
                'title' => 'Staging fixture: draft intake request',
                'description' => 'Use this draft to verify editing, routing preview, and explicit submission.',
            ],
            'queued' => [
                'work_item' => 'TPF-12',
                'request_type' => 'investigation',
                'state' => DevelopmentRequestStatus::Queued->value,
                'priority' => 'medium',
                'tier' => 'junior',
                'title' => 'Staging fixture: queued investigation',
                'description' => 'Verify the queued state and the explicit start boundary.',
            ],
            'running' => [
                'work_item' => 'TPF-13',
                'request_type' => 'development',
                'state' => DevelopmentRequestStatus::Running->value,
                'priority' => 'high',
                'tier' => 'senior',
                'title' => 'Staging fixture: worker in progress',
                'description' => 'Verify progress, worker identity, and lifecycle audit information.',
            ],
            'awaiting_approval' => [
                'work_item' => 'TPF-14',
                'request_type' => 'development',
                'state' => DevelopmentRequestStatus::AwaitingApproval->value,
                'priority' => 'medium',
                'tier' => 'intermediate',
                'title' => 'Staging fixture: approval decision needed',
                'description' => 'Use this request to test approve, request changes, and reject actions.',
            ],
            'completed' => [
                'work_item' => 'TPF-15',
                'request_type' => 'development',
                'state' => DevelopmentRequestStatus::Completed->value,
                'priority' => 'low',
                'tier' => 'junior',
                'title' => 'Staging fixture: completed delivery',
                'description' => 'Verify completed history and read-only terminal behavior.',
            ],
            'failed' => [
                'work_item' => 'TPF-16',
                'request_type' => 'investigation',
                'state' => DevelopmentRequestStatus::Failed->value,
                'priority' => 'critical',
                'tier' => 'senior',
                'title' => 'Staging fixture: failed worker startup',
                'description' => 'Verify failed state visibility and failure audit details.',
            ],
        ];

        $requests = [];
        foreach ($definitions as $fixtureKey => $definition) {
            /** @var PmWorkItem $workItem */
            $workItem = $workItems[$definition['work_item']];
            $correlation = 'staging-fixture-'.str_replace('_', '-', $fixtureKey);
            $request = DevelopmentRequest::query()->updateOrCreate(
                ['correlation_identifier' => $correlation],
                [
                    'client_id' => $client->id,
                    'project_id' => $project->id,
                    'owner_user_id' => $admin->id,
                    'parent_request_id' => null,
                    'request_type' => $definition['request_type'],
                    'state' => $definition['state'],
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'status_reason' => 'Synthetic staging fixture: '.$definition['state'],
                    'environment_key' => 'development',
                    'project_environment_mapping_id' => $mapping->id,
                    'execution_target_key' => $this->executionTargetKey($mapping),
                    'vm_startup_deadline_at' => in_array($definition['state'], [
                        DevelopmentRequestStatus::Queued->value,
                        DevelopmentRequestStatus::Running->value,
                    ], true) ? now()->addMinutes(10) : null,
                    'vm_ready_at' => in_array($definition['state'], [
                        DevelopmentRequestStatus::Running->value,
                        DevelopmentRequestStatus::AwaitingApproval->value,
                        DevelopmentRequestStatus::Completed->value,
                    ], true) ? now()->subMinutes(5) : null,
                    'source_type' => 'jira',
                    'source_id' => $workItem->external_item_key,
                    'priority' => $definition['priority'],
                    'active_run_correlation_id' => in_array($definition['state'], [
                        DevelopmentRequestStatus::Queued->value,
                        DevelopmentRequestStatus::Running->value,
                        DevelopmentRequestStatus::AwaitingApproval->value,
                    ], true) ? $correlation.'-run' : null,
                    'jira_snapshot' => $this->jiraSnapshot($workItem),
                    'routing_snapshot' => $mapping->snapshot(),
                    'selected_capability_tier' => $definition['tier'],
                    'pm_work_item_id' => $workItem->id,
                ],
            );

            $this->seedStatusHistory($request, $definition['state'], $admin);
            $requests[$fixtureKey] = $request->refresh();
        }

        return $requests;
    }

    private function seedStatusHistory(DevelopmentRequest $request, string $finalState, User $admin): void
    {
        $paths = [
            DevelopmentRequestStatus::Draft->value => [
                ['draft', 'draft', 'user', 'Request created as a synthetic draft.'],
            ],
            DevelopmentRequestStatus::Queued->value => [
                ['draft', 'draft', 'user', 'Request created as a synthetic draft.'],
                ['draft', 'queued', 'user', 'Synthetic request submitted for worker processing.'],
            ],
            DevelopmentRequestStatus::Running->value => [
                ['draft', 'draft', 'user', 'Request created as a synthetic draft.'],
                ['draft', 'queued', 'user', 'Synthetic request submitted for worker processing.'],
                ['queued', 'starting_vm', 'system', 'Synthetic VM startup requested.'],
                ['starting_vm', 'waiting_for_worker', 'system', 'Synthetic worker wait started.'],
                ['waiting_for_worker', 'running', 'system', 'Synthetic worker heartbeat received.'],
            ],
            DevelopmentRequestStatus::AwaitingApproval->value => [
                ['draft', 'draft', 'user', 'Request created as a synthetic draft.'],
                ['draft', 'queued', 'user', 'Synthetic request submitted for worker processing.'],
                ['queued', 'starting_vm', 'system', 'Synthetic VM startup requested.'],
                ['starting_vm', 'waiting_for_worker', 'system', 'Synthetic worker wait started.'],
                ['waiting_for_worker', 'running', 'system', 'Synthetic worker heartbeat received.'],
                ['running', 'awaiting_approval', 'system', 'Synthetic result is ready for human review.'],
            ],
            DevelopmentRequestStatus::Completed->value => [
                ['draft', 'draft', 'user', 'Request created as a synthetic draft.'],
                ['draft', 'queued', 'user', 'Synthetic request submitted for worker processing.'],
                ['queued', 'starting_vm', 'system', 'Synthetic VM startup requested.'],
                ['starting_vm', 'waiting_for_worker', 'system', 'Synthetic worker wait started.'],
                ['waiting_for_worker', 'running', 'system', 'Synthetic worker heartbeat received.'],
                ['running', 'awaiting_approval', 'system', 'Synthetic result is ready for human review.'],
                ['awaiting_approval', 'approved', 'user', 'Synthetic staging approval recorded.'],
                ['approved', 'completed', 'system', 'Synthetic delivery completed.'],
            ],
            DevelopmentRequestStatus::Failed->value => [
                ['draft', 'draft', 'user', 'Request created as a synthetic draft.'],
                ['draft', 'queued', 'user', 'Synthetic request submitted for worker processing.'],
                ['queued', 'starting_vm', 'system', 'Synthetic VM startup requested.'],
                ['starting_vm', 'failed', 'system', 'Synthetic worker startup failed for QA coverage.'],
            ],
        ];

        foreach ($paths[$finalState] ?? [] as $index => [$oldState, $newState, $actorType, $reason]) {
            $idempotencyKey = "{$request->correlation_identifier}:history:{$index}";
            DevelopmentRequestStatusHistory::query()->firstOrCreate(
                [
                    'development_request_id' => $request->id,
                    'idempotency_key' => $idempotencyKey,
                ],
                [
                    'old_state' => $oldState,
                    'new_state' => $newState,
                    'actor_user_id' => $actorType === 'user' ? $admin->id : null,
                    'actor_type' => $actorType,
                    'actor_label' => $actorType === 'user' ? $admin->name : 'Staging fixture',
                    'reason' => $reason,
                    'correlation_identifier' => $request->correlation_identifier,
                    'metadata' => [
                        'fixture' => true,
                        'source' => self::FIXTURE_LABEL,
                    ],
                ],
            );
        }
    }

    private function seedRuntimeState(ProjectEnvironmentMapping $mapping): VmRuntimeState
    {
        return VmRuntimeState::query()->updateOrCreate(
            ['target_key' => $this->executionTargetKey($mapping)],
            [
                'gcp_project_id' => $mapping->gcp_project_id,
                'gcp_zone' => $mapping->gcp_zone,
                'vm_name' => $mapping->vm_name,
                'status' => VmRuntimeState::STATUS_WORKER_READY,
                'worker_identifier' => 'staging-fixture-worker',
                'worker_state' => 'ready',
                'last_worker_heartbeat_at' => now()->subMinutes(2),
                'last_activity_at' => now()->subMinutes(1),
                'idle_since' => null,
                'start_requested_at' => now()->subMinutes(8),
                'stop_requested_at' => null,
                'last_operation_id' => 'staging-fixture-worker-ready',
                'last_error_code' => null,
                'manual_override_action' => null,
                'manual_override_at' => null,
                'manual_override_by' => null,
            ],
        );
    }

    private function seedLifecycleActions(VmRuntimeState $runtime, DevelopmentRequest $request, User $admin): void
    {
        $actions = [
            [
                'key' => 'staging-fixture:vm:start',
                'action' => 'start',
                'outcome' => 'succeeded',
                'actor_type' => 'system',
                'actor_label' => 'Staging fixture',
                'reason' => 'Synthetic lifecycle event for QA.',
            ],
            [
                'key' => 'staging-fixture:vm:worker-ready',
                'action' => 'worker_ready',
                'outcome' => 'succeeded',
                'actor_type' => 'system',
                'actor_label' => 'Staging fixture',
                'reason' => 'Synthetic worker heartbeat for QA.',
            ],
            [
                'key' => 'staging-fixture:vm:manual-check',
                'action' => 'inspect',
                'outcome' => 'succeeded',
                'actor_type' => 'user',
                'actor_label' => $admin->name,
                'reason' => 'Synthetic audit event for lifecycle history.',
            ],
        ];

        foreach ($actions as $action) {
            VmLifecycleAction::query()->firstOrCreate(
                ['idempotency_key' => $action['key']],
                [
                    'vm_runtime_state_id' => $runtime->id,
                    'development_request_id' => $request->id,
                    'action' => $action['action'],
                    'outcome' => $action['outcome'],
                    'gcp_operation_id' => null,
                    'actor_type' => $action['actor_type'],
                    'actor_label' => $action['actor_label'],
                    'reason' => $action['reason'],
                    'metadata' => [
                        'fixture' => true,
                        'source' => self::FIXTURE_LABEL,
                    ],
                ],
            );
        }
    }

    /** @param array<string, DevelopmentRequest> $requests */
    private function seedAgentJobs(ProjectEnvironmentMapping $mapping, array $requests, array $workItems): void
    {
        $definitions = [
            'queued' => [
                'role' => 'research_collector',
                'status' => AgentJobStatus::Queued->value,
                'progress_percent' => 0,
                'progress_stage' => 'queued',
                'progress_message' => 'Waiting for a worker.',
            ],
            'running' => [
                'role' => 'developer',
                'status' => AgentJobStatus::Running->value,
                'progress_percent' => 55,
                'progress_stage' => 'implementation',
                'progress_message' => 'Synthetic worker is applying changes.',
                'claimed_by_worker_identity' => 'staging-fixture-worker',
            ],
            'awaiting_approval' => [
                'role' => 'developer',
                'status' => AgentJobStatus::ResultReceived->value,
                'progress_percent' => 100,
                'progress_stage' => 'result_received',
                'progress_message' => 'Synthetic result is ready for review.',
                'result' => ['summary' => 'Synthetic result for approval QA.', 'fixture' => true],
            ],
            'completed' => [
                'role' => 'developer',
                'status' => AgentJobStatus::Completed->value,
                'progress_percent' => 100,
                'progress_stage' => 'completed',
                'progress_message' => 'Synthetic delivery completed.',
                'result' => ['summary' => 'Synthetic completed result.', 'fixture' => true],
                'completed_at' => now()->subMinutes(4),
            ],
            'failed' => [
                'role' => 'research_collector',
                'status' => AgentJobStatus::Failed->value,
                'progress_percent' => 20,
                'progress_stage' => 'startup',
                'progress_message' => 'Synthetic worker startup failed.',
                'failure' => ['code' => 'fixture_startup_failure', 'message' => 'Synthetic failure for QA coverage.'],
            ],
        ];

        foreach ($definitions as $fixtureKey => $definition) {
            $request = $requests[$fixtureKey];
            $workItem = $workItems[$request->source_id];
            $payload = [
                'fixture' => true,
                'source' => self::FIXTURE_LABEL,
                'request_correlation' => $request->correlation_identifier,
                'ticket' => $workItem->external_item_key,
                'role' => $definition['role'],
            ];
            $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

            $job = AgentJob::query()->updateOrCreate(
                [
                    'development_request_id' => $request->id,
                    'role' => $definition['role'],
                    'attempt' => 1,
                ],
                array_merge(
                    [
                        'job_identifier' => $this->fixtureUuid(
                            $request->correlation_identifier.':'.$definition['role']
                        ),
                        'project_environment_mapping_id' => $mapping->id,
                        'correlation_identifier' => $request->active_run_correlation_id
                            ?: $request->correlation_identifier,
                        'status' => $definition['status'],
                        'worker_service_account_email' => $mapping->worker_service_account_email,
                        'payload' => $payload,
                        'payload_hash' => $payloadHash,
                        'available_at' => now()->subMinutes(5),
                        'claimed_at' => null,
                        'claimed_by_worker_identity' => null,
                        'claim_request_identifier' => null,
                        'lease_expires_at' => null,
                        'last_heartbeat_at' => null,
                        'progress_percent' => null,
                        'progress_stage' => null,
                        'progress_message' => null,
                        'result' => null,
                        'failure' => null,
                        'completed_at' => null,
                    ],
                    $definition,
                ),
            );

            AgentJobEvent::query()->firstOrCreate(
                [
                    'agent_job_id' => $job->id,
                    'operation' => 'fixture',
                    'event_type' => $definition['status'],
                ],
                [
                    'worker_identity' => $mapping->worker_service_account_email,
                    'request_identifier' => null,
                    'request_payload_hash' => $payloadHash,
                    'metadata' => [
                        'fixture' => true,
                        'source' => self::FIXTURE_LABEL,
                    ],
                ],
            );
        }
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

    private function executionTargetKey(ProjectEnvironmentMapping $mapping): string
    {
        return sprintf('%s/%s/%s', $mapping->gcp_project_id, $mapping->gcp_zone, $mapping->vm_name);
    }

    private function fixtureUuid(string $seed): string
    {
        $hex = md5($seed);
        $hex = substr_replace($hex, '5', 12, 1);
        $hex = substr_replace($hex, dechex((hexdec($hex[16]) & 0x3) | 0x8), 16, 1);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
