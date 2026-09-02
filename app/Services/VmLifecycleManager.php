<?php

namespace App\Services;

use App\Contracts\ComputeEngineClient;
use App\Enums\DevelopmentRequestStatus;
use App\Exceptions\ComputeEngineException;
use App\Models\DevelopmentRequest;
use App\Models\User;
use App\Models\VmRuntimeState;
use App\ValueObjects\VmReadinessResult;
use App\ValueObjects\VmTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class VmLifecycleManager
{
    private const ACTIVE_REQUEST_STATES = [
        'queued',
        'starting_vm',
        'waiting_for_worker',
        'running',
        'cancelling',
    ];

    private const STARTING_INSTANCE_STATES = [
        'PROVISIONING',
        'STAGING',
        'STARTING',
        'REPAIRING',
        'STOPPING',
    ];

    public function __construct(
        private readonly ComputeEngineClient $compute,
        private readonly DevelopmentRequestLifecycleService $lifecycle,
        private readonly VmWorkerRegistry $workers,
        private readonly VmLifecycleAuditor $auditor
    ) {}

    public function ensureRequestVmReady(DevelopmentRequest $request): VmReadinessResult
    {
        $request = DevelopmentRequest::query()->findOrFail($request->getKey());
        $target = VmTarget::fromRoutingSnapshot($request->routing_snapshot);
        $runtime = $this->workers->runtimeFor($target);

        $request = $this->prepareRequest($request, $target);

        try {
            $instanceStatus = strtoupper($this->compute->status($target));

            if ($instanceStatus === 'RUNNING') {
                return $this->handleRunningInstance($request, $runtime);
            }

            if ($this->deadlineExpired($request)) {
                return $this->failRequest($request, $runtime, 'vm_startup_timeout', 'The mapped VM did not become ready before the startup timeout.');
            }

            if ($instanceStatus === 'TERMINATED') {
                return $this->startTerminatedInstance($request, $runtime, $target);
            }

            if (in_array($instanceStatus, self::STARTING_INSTANCE_STATES, true)) {
                $runtime->forceFill([
                    'status' => VmRuntimeState::STATUS_STARTING,
                    'worker_identifier' => null,
                    'worker_state' => null,
                    'last_worker_heartbeat_at' => null,
                    'last_activity_at' => now(),
                    'idle_since' => null,
                ])->save();

                return new VmReadinessResult('starting', false);
            }

            return $this->failRequest(
                $request,
                $runtime,
                'unsupported_vm_state',
                'The mapped VM entered an unsupported state and requires operator review.'
            );
        } catch (ComputeEngineException $exception) {
            return $this->failRequest(
                $request,
                $runtime,
                $exception->providerCode ?? 'compute_api_error',
                'Forge could not inspect or start the mapped VM. The request can be retried after the infrastructure issue is corrected.'
            );
        }
    }

    public function stopIfIdle(VmRuntimeState $runtime): string
    {
        $claim = DB::transaction(function () use ($runtime): string {
            $locked = VmRuntimeState::query()->lockForUpdate()->findOrFail($runtime->getKey());
            $activeJobs = DevelopmentRequest::query()
                ->where('execution_target_key', $locked->target_key)
                ->whereIn('state', self::ACTIVE_REQUEST_STATES)
                ->count();

            if ($activeJobs > 0) {
                $wasIdle = $locked->idle_since !== null;
                $locked->forceFill(['idle_since' => null, 'last_activity_at' => now()])->save();
                if ($wasIdle) {
                    $this->auditor->record(
                        $locked,
                        'stop_skipped',
                        'active_jobs',
                        reason: 'The VM still has active Development Requests.',
                        metadata: ['active_job_count' => $activeJobs]
                    );
                }

                return 'active_jobs';
            }

            if ($locked->idle_since === null) {
                $locked->forceFill(['idle_since' => now()])->save();
                $this->auditor->record($locked, 'idle_window_started', 'recorded');

                return 'idle_window_started';
            }

            if ($locked->idle_since->addSeconds(config('devforge.vm_idle_shutdown_seconds'))->isFuture()) {
                return 'idle_wait';
            }

            if (in_array($locked->status, [VmRuntimeState::STATUS_STOPPING, VmRuntimeState::STATUS_TERMINATED], true)) {
                return $locked->status;
            }

            $locked->forceFill([
                'status' => VmRuntimeState::STATUS_STOPPING,
                'stop_requested_at' => now(),
            ])->save();

            return 'stop_claimed';
        });

        if ($claim !== 'stop_claimed') {
            return $claim;
        }

        $runtime->refresh();
        $target = new VmTarget($runtime->gcp_project_id, $runtime->gcp_zone, $runtime->vm_name);

        try {
            if (strtoupper($this->compute->status($target)) === 'TERMINATED') {
                $runtime->forceFill(['status' => VmRuntimeState::STATUS_TERMINATED])->save();
                $this->auditor->record($runtime, 'stop_reused', 'already_terminated');

                return 'terminated';
            }

            $operationId = $this->compute->stop($target);
            $runtime->forceFill([
                'status' => VmRuntimeState::STATUS_STOPPING,
                'last_operation_id' => $operationId,
                'last_error_code' => null,
            ])->save();
            $this->auditor->record(
                $runtime,
                'stop_requested',
                'accepted',
                operationId: $operationId,
                idempotencyKey: hash('sha256', implode('|', [
                    'vm-stop',
                    $runtime->target_key,
                    (string) $runtime->stop_requested_at->timestamp,
                    (string) $operationId,
                ]))
            );

            return 'stopping';
        } catch (ComputeEngineException $exception) {
            $runtime->forceFill([
                'status' => VmRuntimeState::STATUS_UNKNOWN,
                'last_error_code' => $exception->providerCode ?? 'compute_api_error',
            ])->save();
            $this->auditor->record(
                $runtime,
                'stop_requested',
                'failed',
                reason: 'Compute Engine could not stop the mapped VM.',
                metadata: ['error_code' => $exception->providerCode ?? 'compute_api_error']
            );

            return 'stop_failed';
        }
    }

    public function shutdownIdleTargets(): array
    {
        return VmRuntimeState::query()
            ->whereNotIn('status', [VmRuntimeState::STATUS_TERMINATED])
            ->get()
            ->mapWithKeys(fn (VmRuntimeState $runtime): array => [
                $runtime->target_key => $this->stopIfIdle($runtime),
            ])
            ->all();
    }

    public function recordManualOverride(
        array $routingSnapshot,
        string $action,
        User $actor,
        string $reason
    ): void {
        if (! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a super administrator may record a manual VM override.');
        }

        if (! in_array($action, ['start', 'stop'], true)) {
            throw new InvalidArgumentException('The manual VM override action must be start or stop.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required for a manual VM override.');
        }

        $target = VmTarget::fromRoutingSnapshot($routingSnapshot);
        $runtime = $this->workers->runtimeFor($target);

        if ($action === 'stop' && $this->activeRequestCount($runtime) > 0) {
            throw new RuntimeException('The mapped VM cannot be stopped while it has active Development Requests.');
        }

        $runtime->forceFill([
            'status' => VmRuntimeState::STATUS_UNKNOWN,
            'manual_override_action' => $action,
            'manual_override_at' => now(),
            'manual_override_by' => $actor->getKey(),
        ])->save();

        $this->auditor->record(
            $runtime,
            "manual_{$action}_override",
            'recorded',
            actorType: 'user',
            actorLabel: $actor->email,
            reason: $reason
        );
    }

    private function prepareRequest(DevelopmentRequest $request, VmTarget $target): DevelopmentRequest
    {
        if (! in_array($request->state->value, ['queued', 'starting_vm', 'waiting_for_worker'], true)) {
            throw new RuntimeException('Only a queued or starting Development Request can prepare a VM.');
        }

        if ($request->execution_target_key !== null && $request->execution_target_key !== $target->key()) {
            throw new RuntimeException('The Development Request execution target cannot change after startup begins.');
        }

        $request->forceFill([
            'execution_target_key' => $target->key(),
            'vm_startup_deadline_at' => $request->vm_startup_deadline_at
                ?? now()->addSeconds(config('devforge.vm_startup_timeout_seconds')),
        ])->save();

        if ($request->state === DevelopmentRequestStatus::Queued) {
            $this->lifecycle->transitionState(
                $request,
                DevelopmentRequestStatus::StartingVm,
                reason: 'Preparing the mapped VM.',
                idempotencyKey: "vm-starting:{$request->correlation_identifier}",
                correlationIdentifier: $request->correlation_identifier,
                actorType: 'system',
                actorLabel: 'VM orchestrator'
            );
        }

        return DevelopmentRequest::query()->findOrFail($request->getKey());
    }

    private function handleRunningInstance(
        DevelopmentRequest $request,
        VmRuntimeState $runtime
    ): VmReadinessResult {
        $runtime->forceFill([
            'status' => VmRuntimeState::STATUS_RUNNING,
            'last_activity_at' => now(),
            'idle_since' => null,
            'last_error_code' => null,
        ])->save();

        $startedByThisRequest = $runtime->lifecycleActions()
            ->where('development_request_id', $request->getKey())
            ->where('action', 'start_requested')
            ->exists();

        if (! $startedByThisRequest) {
            $this->auditor->record(
                $runtime,
                'start_reused',
                'already_running',
                $request,
                idempotencyKey: "vm-running-reused:{$request->correlation_identifier}"
            );
        }

        if ($request->state === DevelopmentRequestStatus::StartingVm) {
            $this->lifecycle->transitionState(
                $request,
                DevelopmentRequestStatus::WaitingForWorker,
                reason: 'The mapped VM is running; waiting for a fresh worker heartbeat.',
                idempotencyKey: "vm-running:{$request->correlation_identifier}",
                correlationIdentifier: $request->correlation_identifier,
                actorType: 'system',
                actorLabel: 'VM orchestrator'
            );
            $request = DevelopmentRequest::query()->findOrFail($request->getKey());
        }

        if ($this->workers->isReady($runtime->fresh())) {
            $runtime->forceFill(['status' => VmRuntimeState::STATUS_WORKER_READY])->save();
            $request->forceFill(['vm_ready_at' => $request->vm_ready_at ?? now()])->save();
            $this->auditor->record(
                $runtime,
                'worker_ready',
                'confirmed',
                $request,
                idempotencyKey: "worker-ready:{$request->correlation_identifier}"
            );

            return new VmReadinessResult('worker_ready', true);
        }

        if ($this->deadlineExpired($request)) {
            return $this->failRequest($request, $runtime, 'worker_readiness_timeout', 'The VM started, but its worker did not report ready before the timeout.');
        }

        return new VmReadinessResult('waiting_for_worker', false);
    }

    private function startTerminatedInstance(
        DevelopmentRequest $request,
        VmRuntimeState $runtime,
        VmTarget $target
    ): VmReadinessResult {
        $claimed = DB::transaction(function () use ($runtime): bool {
            $locked = VmRuntimeState::query()->lockForUpdate()->findOrFail($runtime->getKey());
            $claimIsFresh = $locked->status === VmRuntimeState::STATUS_STARTING
                && $locked->start_requested_at?->greaterThanOrEqualTo(
                    now()->subSeconds(config('devforge.vm_startup_timeout_seconds'))
                );

            if ($claimIsFresh) {
                return false;
            }

            $locked->forceFill([
                'status' => VmRuntimeState::STATUS_STARTING,
                'worker_identifier' => null,
                'worker_state' => null,
                'last_worker_heartbeat_at' => null,
                'start_requested_at' => now(),
                'last_activity_at' => now(),
                'idle_since' => null,
                'last_error_code' => null,
            ])->save();

            return true;
        });

        if (! $claimed) {
            $this->auditor->record(
                $runtime,
                'start_reused',
                'already_starting',
                $request,
                idempotencyKey: "vm-start-reused:{$request->correlation_identifier}"
            );

            return new VmReadinessResult('starting', false);
        }

        $runtime->refresh();
        $operationId = $this->compute->start($target);
        $runtime->forceFill(['last_operation_id' => $operationId])->save();
        $this->auditor->record(
            $runtime,
            'start_requested',
            'accepted',
            $request,
            $operationId,
            idempotencyKey: hash('sha256', implode('|', [
                'vm-start',
                $runtime->target_key,
                $request->correlation_identifier,
                (string) $operationId,
            ]))
        );

        return new VmReadinessResult('starting', false, $operationId);
    }

    private function failRequest(
        DevelopmentRequest $request,
        VmRuntimeState $runtime,
        string $errorCode,
        string $reason
    ): VmReadinessResult {
        $runtime->forceFill([
            'status' => VmRuntimeState::STATUS_FAILED,
            'last_error_code' => $errorCode,
        ])->save();

        $request = DevelopmentRequest::query()->findOrFail($request->getKey());
        if (in_array($request->state->value, ['starting_vm', 'waiting_for_worker'], true)) {
            $this->lifecycle->transitionState(
                $request,
                DevelopmentRequestStatus::Failed,
                reason: $reason,
                idempotencyKey: "vm-failed:{$request->correlation_identifier}:{$errorCode}",
                correlationIdentifier: $request->correlation_identifier,
                actorType: 'system',
                actorLabel: 'VM orchestrator',
                metadata: ['error_code' => $errorCode]
            );
        }

        $this->auditor->record(
            $runtime,
            'startup_failed',
            'failed',
            $request,
            reason: $reason,
            idempotencyKey: "vm-startup-failed:{$request->correlation_identifier}:{$errorCode}",
            metadata: ['error_code' => $errorCode]
        );

        return new VmReadinessResult('failed', false);
    }

    private function deadlineExpired(DevelopmentRequest $request): bool
    {
        return $request->vm_startup_deadline_at?->isPast() ?? false;
    }

    private function activeRequestCount(VmRuntimeState $runtime): int
    {
        return DevelopmentRequest::query()
            ->where('execution_target_key', $runtime->target_key)
            ->whereIn('state', self::ACTIVE_REQUEST_STATES)
            ->count();
    }
}
