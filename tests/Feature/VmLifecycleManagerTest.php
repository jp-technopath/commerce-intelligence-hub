<?php

namespace Tests\Feature;

use App\Contracts\ComputeEngineClient;
use App\Jobs\EnsureDevelopmentRequestVmReady;
use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\VmRuntimeState;
use App\Services\DevelopmentRequestLifecycleService;
use App\Services\VmLifecycleManager;
use App\Services\VmWorkerRegistry;
use App\ValueObjects\VmTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class VmLifecycleManagerTest extends TestCase
{
    use RefreshDatabase;

    private FakeComputeEngineClient $compute;

    private array $snapshot;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('devforge.vm_startup_timeout_seconds', 600);
        config()->set('devforge.worker_heartbeat_ttl_seconds', 45);
        config()->set('devforge.vm_idle_shutdown_seconds', 60);

        $this->compute = new FakeComputeEngineClient;
        $this->app->instance(ComputeEngineClient::class, $this->compute);
        $this->snapshot = [
            'mapping_id' => 42,
            'mapping_version' => 1,
            'gcp' => [
                'project_id' => 'development-501913',
                'zone' => 'us-central1-a',
                'vm_name' => 'agent-commerce-hub',
            ],
        ];
    }

    #[Test]
    public function a_queued_request_starts_a_terminated_mapped_vm(): void
    {
        $this->compute->instanceStatus = 'TERMINATED';
        $request = $this->request('queued');

        $result = $this->manager()->ensureRequestVmReady($request);

        $this->assertSame('starting', $result->state);
        $this->assertFalse($result->workerReady);
        $this->assertSame('start-operation-1', $result->operationId);
        $this->assertSame(1, $this->compute->startCalls);
        $this->assertSame('starting_vm', $request->fresh()->state->value);
        $this->assertNotNull($request->fresh()->vm_startup_deadline_at);
        $this->assertDatabaseHas('vm_lifecycle_actions', [
            'development_request_id' => $request->id,
            'action' => 'start_requested',
            'outcome' => 'accepted',
            'gcp_operation_id' => 'start-operation-1',
        ]);
    }

    #[Test]
    public function an_already_running_vm_is_reused_without_restart(): void
    {
        $this->compute->instanceStatus = 'RUNNING';
        $request = $this->request('queued');

        $result = $this->manager()->ensureRequestVmReady($request);

        $this->assertSame('waiting_for_worker', $result->state);
        $this->assertFalse($result->workerReady);
        $this->assertSame(0, $this->compute->startCalls);
        $this->assertSame('waiting_for_worker', $request->fresh()->state->value);
        $this->assertDatabaseHas('vm_lifecycle_actions', [
            'development_request_id' => $request->id,
            'action' => 'start_reused',
            'outcome' => 'already_running',
        ]);
    }

    #[Test]
    public function forge_reports_ready_only_after_a_fresh_worker_heartbeat(): void
    {
        $this->compute->instanceStatus = 'RUNNING';
        $request = $this->request('queued');
        $this->app->make(VmWorkerRegistry::class)->heartbeat(
            $this->snapshot,
            'commerce-hub-worker-1'
        );

        $result = $this->manager()->ensureRequestVmReady($request);

        $this->assertTrue($result->workerReady);
        $this->assertSame('worker_ready', $result->state);
        $this->assertNotNull($request->fresh()->vm_ready_at);
        $this->assertDatabaseHas('vm_lifecycle_actions', [
            'development_request_id' => $request->id,
            'action' => 'worker_ready',
            'outcome' => 'confirmed',
        ]);
    }

    #[Test]
    public function a_stale_worker_heartbeat_does_not_mark_the_vm_ready(): void
    {
        $this->compute->instanceStatus = 'RUNNING';
        $request = $this->request('queued');
        $runtime = $this->app->make(VmWorkerRegistry::class)->heartbeat(
            $this->snapshot,
            'commerce-hub-worker-1'
        );
        $runtime->forceFill(['last_worker_heartbeat_at' => now()->subMinutes(2)])->save();

        $result = $this->manager()->ensureRequestVmReady($request);

        $this->assertFalse($result->workerReady);
        $this->assertSame('waiting_for_worker', $result->state);
        $this->assertNull($request->fresh()->vm_ready_at);
    }

    #[Test]
    public function starting_a_terminated_vm_clears_any_previous_worker_heartbeat(): void
    {
        $runtime = $this->app->make(VmWorkerRegistry::class)->heartbeat(
            $this->snapshot,
            'commerce-hub-worker-before-restart'
        );
        $this->compute->instanceStatus = 'TERMINATED';
        $request = $this->request('queued');

        $this->manager()->ensureRequestVmReady($request);

        $runtime->refresh();
        $this->assertNull($runtime->worker_identifier);
        $this->assertNull($runtime->worker_state);
        $this->assertNull($runtime->last_worker_heartbeat_at);

        $this->compute->instanceStatus = 'RUNNING';
        $result = $this->manager()->ensureRequestVmReady($request);

        $this->assertFalse($result->workerReady);
        $this->assertSame('waiting_for_worker', $result->state);
    }

    #[Test]
    public function startup_timeout_moves_the_request_to_a_visible_failed_state(): void
    {
        $this->compute->instanceStatus = 'STARTING';
        $request = $this->request('starting_vm', [
            'vm_startup_deadline_at' => now()->subSecond(),
        ]);

        $result = $this->manager()->ensureRequestVmReady($request);

        $this->assertSame('failed', $result->state);
        $this->assertSame('failed', $request->fresh()->state->value);
        $this->assertStringContainsString('startup timeout', $request->fresh()->status_reason);
        $this->assertDatabaseHas('vm_lifecycle_actions', [
            'development_request_id' => $request->id,
            'action' => 'startup_failed',
            'outcome' => 'failed',
        ]);
    }

    #[Test]
    public function a_concurrent_start_claim_does_not_issue_a_second_start(): void
    {
        $this->compute->instanceStatus = 'TERMINATED';
        $request = $this->request('queued');
        $runtime = $this->runtime();
        $runtime->forceFill([
            'status' => VmRuntimeState::STATUS_STARTING,
            'start_requested_at' => now(),
        ])->save();

        $result = $this->manager()->ensureRequestVmReady($request);

        $this->assertSame('starting', $result->state);
        $this->assertSame(0, $this->compute->startCalls);
        $this->assertDatabaseHas('vm_lifecycle_actions', [
            'development_request_id' => $request->id,
            'action' => 'start_reused',
            'outcome' => 'already_starting',
        ]);
    }

    #[Test]
    public function idle_shutdown_never_stops_a_vm_with_an_active_request(): void
    {
        $runtime = $this->runtime([
            'status' => VmRuntimeState::STATUS_RUNNING,
            'idle_since' => now()->subMinutes(5),
        ]);
        $this->request('running', ['execution_target_key' => $runtime->target_key]);

        $outcome = $this->manager()->stopIfIdle($runtime);

        $this->assertSame('active_jobs', $outcome);
        $this->assertSame(0, $this->compute->stopCalls);
        $this->assertDatabaseHas('vm_lifecycle_actions', [
            'vm_runtime_state_id' => $runtime->id,
            'action' => 'stop_skipped',
            'outcome' => 'active_jobs',
        ]);
    }

    #[Test]
    public function an_idle_vm_is_stopped_and_the_provider_operation_is_audited(): void
    {
        $this->compute->instanceStatus = 'RUNNING';
        $runtime = $this->runtime([
            'status' => VmRuntimeState::STATUS_RUNNING,
            'idle_since' => now()->subMinutes(5),
        ]);

        $outcome = $this->manager()->stopIfIdle($runtime);

        $this->assertSame('stopping', $outcome);
        $this->assertSame(1, $this->compute->stopCalls);
        $this->assertDatabaseHas('vm_lifecycle_actions', [
            'vm_runtime_state_id' => $runtime->id,
            'action' => 'stop_requested',
            'outcome' => 'accepted',
            'gcp_operation_id' => 'stop-operation-1',
        ]);
    }

    #[Test]
    public function a_routed_request_queues_vm_orchestration_automatically(): void
    {
        Queue::fake();
        $client = Client::query()->create(['name' => 'Technopath', 'status' => 'active']);
        $project = Project::query()->create([
            'client_id' => $client->id,
            'name' => 'Commerce Hub',
            'status' => 'active',
        ]);
        $owner = User::factory()->create(['is_admin' => true]);
        $request = $this->request('draft', [
            'client_id' => $client->id,
            'project_id' => $project->id,
            'owner_user_id' => $owner->id,
        ]);

        $this->app->make(DevelopmentRequestLifecycleService::class)
            ->transitionState($request, 'queued', $owner);

        Queue::assertPushed(
            EnsureDevelopmentRequestVmReady::class,
            fn (EnsureDevelopmentRequestVmReady $job): bool => $job->developmentRequestId === $request->id
        );
    }

    #[Test]
    public function an_old_queued_transition_retry_does_not_dispatch_orchestration_again(): void
    {
        Queue::fake();
        $client = Client::query()->create(['name' => 'Technopath', 'status' => 'active']);
        $project = Project::query()->create([
            'client_id' => $client->id,
            'name' => 'Commerce Hub',
            'status' => 'active',
        ]);
        $owner = User::factory()->create(['is_admin' => true]);
        $request = $this->request('draft', [
            'client_id' => $client->id,
            'project_id' => $project->id,
            'owner_user_id' => $owner->id,
        ]);
        $lifecycle = $this->app->make(DevelopmentRequestLifecycleService::class);

        $lifecycle->transitionState($request, 'queued', $owner, idempotencyKey: 'submit-request');
        $lifecycle->transitionState(
            $request,
            'starting_vm',
            actorType: 'system',
            actorLabel: 'VM orchestrator'
        );
        $lifecycle->transitionState($request, 'queued', $owner, idempotencyKey: 'submit-request');

        Queue::assertPushed(EnsureDevelopmentRequestVmReady::class, 1);
    }

    #[Test]
    public function manual_stop_override_is_blocked_while_work_is_active(): void
    {
        $runtime = $this->runtime();
        $this->request('running', ['execution_target_key' => $runtime->target_key]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->expectException(RuntimeException::class);

        $this->manager()->recordManualOverride(
            $this->snapshot,
            'stop',
            $admin,
            'Operator maintenance'
        );
    }

    private function manager(): VmLifecycleManager
    {
        return $this->app->make(VmLifecycleManager::class);
    }

    private function runtime(array $overrides = []): VmRuntimeState
    {
        $target = VmTarget::fromRoutingSnapshot($this->snapshot);

        return VmRuntimeState::query()->create(array_merge([
            'target_key' => $target->key(),
            'gcp_project_id' => $target->projectId,
            'gcp_zone' => $target->zone,
            'vm_name' => $target->vmName,
            'status' => VmRuntimeState::STATUS_UNKNOWN,
        ], $overrides));
    }

    private function request(string $state, array $overrides = []): DevelopmentRequest
    {
        return DevelopmentRequest::query()->create(array_merge([
            'request_type' => 'development',
            'state' => $state,
            'title' => 'TPF-7 VM lifecycle test',
            'environment_key' => 'development',
            'source_type' => 'jira',
            'correlation_identifier' => fake()->uuid(),
            'routing_snapshot' => $this->snapshot,
        ], $overrides));
    }
}

class FakeComputeEngineClient implements ComputeEngineClient
{
    public string $instanceStatus = 'RUNNING';

    public int $startCalls = 0;

    public int $stopCalls = 0;

    public function status(VmTarget $target): string
    {
        return $this->instanceStatus;
    }

    public function start(VmTarget $target): ?string
    {
        $this->startCalls++;

        return "start-operation-{$this->startCalls}";
    }

    public function stop(VmTarget $target): ?string
    {
        $this->stopCalls++;

        return "stop-operation-{$this->stopCalls}";
    }
}
