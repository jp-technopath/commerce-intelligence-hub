<?php

namespace App\Services;

use App\Models\VmRuntimeState;
use App\ValueObjects\VmTarget;
use InvalidArgumentException;

class VmWorkerRegistry
{
    private const ALLOWED_STATES = ['ready', 'busy', 'draining', 'offline'];

    public function __construct(private readonly VmLifecycleAuditor $auditor) {}

    public function heartbeat(
        array $routingSnapshot,
        string $workerIdentifier,
        string $state = 'ready'
    ): VmRuntimeState {
        if (! in_array($state, self::ALLOWED_STATES, true)) {
            throw new InvalidArgumentException('The worker heartbeat state is invalid.');
        }

        if ($workerIdentifier === '' || strlen($workerIdentifier) > 255) {
            throw new InvalidArgumentException('A valid worker identifier is required.');
        }

        $target = VmTarget::fromRoutingSnapshot($routingSnapshot);
        $runtime = $this->runtimeFor($target);
        $changed = $runtime->worker_identifier !== $workerIdentifier
            || $runtime->worker_state !== $state;

        $runtime->forceFill([
            'status' => $state === 'ready'
                ? VmRuntimeState::STATUS_WORKER_READY
                : VmRuntimeState::STATUS_RUNNING,
            'worker_identifier' => $workerIdentifier,
            'worker_state' => $state,
            'last_worker_heartbeat_at' => now(),
            'last_activity_at' => now(),
            'last_error_code' => null,
        ])->save();

        if ($changed) {
            $this->auditor->record(
                $runtime,
                'worker_registration',
                $state,
                actorType: 'worker',
                actorLabel: $workerIdentifier,
                metadata: ['heartbeat_state' => $state]
            );
        }

        return $runtime->refresh();
    }

    public function isReady(VmRuntimeState $runtime): bool
    {
        if ($runtime->worker_state !== 'ready' || $runtime->last_worker_heartbeat_at === null) {
            return false;
        }

        return $runtime->last_worker_heartbeat_at->greaterThanOrEqualTo(
            now()->subSeconds(config('devforge.worker_heartbeat_ttl_seconds'))
        );
    }

    public function runtimeFor(VmTarget $target): VmRuntimeState
    {
        return VmRuntimeState::query()->firstOrCreate(
            ['target_key' => $target->key()],
            [
                'gcp_project_id' => $target->projectId,
                'gcp_zone' => $target->zone,
                'vm_name' => $target->vmName,
                'status' => VmRuntimeState::STATUS_UNKNOWN,
            ]
        );
    }
}
