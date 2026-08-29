<?php

namespace App\Services;

use App\Models\DevelopmentRequest;
use App\Models\VmLifecycleAction;
use App\Models\VmRuntimeState;

class VmLifecycleAuditor
{
    public function record(
        VmRuntimeState $runtime,
        string $action,
        string $outcome,
        ?DevelopmentRequest $request = null,
        ?string $operationId = null,
        string $actorType = 'system',
        ?string $actorLabel = 'VM orchestrator',
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null
    ): VmLifecycleAction {
        $attributes = [
            'vm_runtime_state_id' => $runtime->getKey(),
            'development_request_id' => $request?->getKey(),
            'action' => $action,
            'outcome' => $outcome,
            'gcp_operation_id' => $operationId,
            'actor_type' => $actorType,
            'actor_label' => $actorLabel,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
        ];

        if ($idempotencyKey === null) {
            return VmLifecycleAction::query()->create($attributes);
        }

        return VmLifecycleAction::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            $attributes
        );
    }
}
