<?php

namespace App\Services;

use App\Enums\DevelopmentRequestStatus;
use App\Jobs\EnsureDevelopmentRequestVmReady;
use App\Models\DevelopmentRequest;
use App\Models\DevelopmentRequestStatusHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DevelopmentRequestLifecycleService
{
    private const SYSTEM_ACTOR_TYPES = ['system', 'webhook', 'callback', 'agent'];

    private const TRANSITION_MAP = [
        'draft' => ['queued', 'cancelled'],
        'queued' => ['starting_vm', 'cancelling'],
        'starting_vm' => ['waiting_for_worker', 'failed', 'cancelling'],
        'waiting_for_worker' => ['running', 'failed', 'cancelling'],
        'running' => ['awaiting_approval', 'failed', 'cancelling'],
        'awaiting_approval' => ['approved', 'changes_requested', 'rejected'],
        'approved' => ['completed', 'changes_requested'],
        'changes_requested' => ['queued', 'cancelled'],
        'cancelling' => ['cancelled', 'failed'],
        'rejected' => [],
        'cancelled' => [],
        'failed' => [],
        'completed' => [],
    ];

    public function transitionState(
        DevelopmentRequest $request,
        string|DevelopmentRequestStatus $newState,
        ?User $actor = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?string $correlationIdentifier = null,
        ?string $actorType = null,
        ?string $actorLabel = null,
        ?array $metadata = null
    ): DevelopmentRequestStatusHistory {
        $targetState = $this->normalizeState($newState);

        $history = DB::transaction(function () use (
            $request,
            $targetState,
            $actor,
            $reason,
            $idempotencyKey,
            $correlationIdentifier,
            $actorType,
            $actorLabel,
            $metadata
        ): DevelopmentRequestStatusHistory {
            $lockedRequest = DevelopmentRequest::query()
                ->with('project')
                ->lockForUpdate()
                ->findOrFail($request->getKey());

            $currentState = $this->normalizeState($lockedRequest->state);

            $this->authorizeActor(
                $lockedRequest,
                $currentState,
                $targetState,
                $actor,
                $actorType,
                $actorLabel
            );

            if ($idempotencyKey !== null) {
                $existing = DevelopmentRequestStatusHistory::query()
                    ->where('development_request_id', $lockedRequest->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    if ($this->isExactRetry(
                        $existing,
                        $targetState,
                        $actor,
                        $reason,
                        $correlationIdentifier,
                        $actorType,
                        $actorLabel,
                        $metadata
                    )) {
                        return $existing;
                    }

                    throw new RuntimeException(
                        "Idempotency key {$idempotencyKey} was already used for a different transition payload."
                    );
                }
            }

            $this->validateTransition($currentState, $targetState);

            $lockedRequest->forceFill([
                'state' => $targetState,
                'status_reason' => $reason,
                'active_run_correlation_id' => $correlationIdentifier
                    ?? $lockedRequest->active_run_correlation_id,
            ])->save();

            return DevelopmentRequestStatusHistory::query()->create([
                'development_request_id' => $lockedRequest->getKey(),
                'old_state' => $currentState,
                'new_state' => $targetState,
                'actor_user_id' => $actor?->getKey(),
                'actor_type' => $actorType,
                'actor_label' => $actorLabel,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'correlation_identifier' => $correlationIdentifier,
                'metadata' => $metadata,
            ]);
        });

        $currentRequest = $request->fresh();

        if (
            $history->new_state === DevelopmentRequestStatus::Queued->value
            && $currentRequest?->state === DevelopmentRequestStatus::Queued
            && config('devforge.orchestration_enabled')
            && $currentRequest->routing_snapshot !== null
        ) {
            EnsureDevelopmentRequestVmReady::dispatch($request->getKey())->afterCommit();
        }

        return $history;
    }

    public function getCurrentState(DevelopmentRequest $request): string
    {
        return $this->normalizeState($request->freshOrFail()->state);
    }

    public function getAllowedTransitions(string|DevelopmentRequestStatus $state): array
    {
        return self::TRANSITION_MAP[$this->normalizeState($state)];
    }

    public function isTransitionAllowed(
        string|DevelopmentRequestStatus $fromState,
        string|DevelopmentRequestStatus $toState
    ): bool {
        try {
            $from = $this->normalizeState($fromState);
            $to = $this->normalizeState($toState);
        } catch (InvalidArgumentException) {
            return false;
        }

        return in_array($to, self::TRANSITION_MAP[$from], true);
    }

    public function reconstructStateFromHistory(DevelopmentRequest $request): string
    {
        $latestEntry = $request->statusHistory()
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $latestEntry?->new_state ?? DevelopmentRequestStatus::Draft->value;
    }

    public function getTransitionHistory(DevelopmentRequest $request): array
    {
        return $request->statusHistory()
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (DevelopmentRequestStatusHistory $entry): array => [
                'old_state' => $entry->old_state,
                'new_state' => $entry->new_state,
                'actor' => $entry->actor?->name ?? $entry->actor_label,
                'actor_type' => $entry->actor_type,
                'reason' => $entry->reason,
                'correlation_identifier' => $entry->correlation_identifier,
                'timestamp' => $entry->created_at->toIso8601String(),
            ])
            ->all();
    }

    public static function getTransitionMap(): array
    {
        return self::TRANSITION_MAP;
    }

    private function normalizeState(string|DevelopmentRequestStatus $state): string
    {
        if ($state instanceof DevelopmentRequestStatus) {
            return $state->value;
        }

        $stateEnum = DevelopmentRequestStatus::tryFrom($state);

        if ($stateEnum === null) {
            throw new InvalidArgumentException("Invalid development request state: {$state}");
        }

        return $stateEnum->value;
    }

    private function validateTransition(string $fromState, string $toState): void
    {
        if (in_array($fromState, DevelopmentRequestStatus::terminalValues(), true)) {
            throw new RuntimeException(
                "Cannot transition from terminal state {$fromState}. Create a new linked request instead."
            );
        }

        if (! in_array($toState, self::TRANSITION_MAP[$fromState], true)) {
            throw new RuntimeException(
                "Transition from {$fromState} to {$toState} is not allowed. Allowed transitions: "
                .implode(', ', self::TRANSITION_MAP[$fromState])
            );
        }
    }

    private function authorizeActor(
        DevelopmentRequest $request,
        string $fromState,
        string $toState,
        ?User $actor,
        ?string $actorType,
        ?string $actorLabel
    ): void {
        if ($actor === null) {
            if (! in_array($actorType, self::SYSTEM_ACTOR_TYPES, true) || blank($actorLabel)) {
                throw new AuthorizationException(
                    'A system transition requires a supported actor type and a non-empty actor label.'
                );
            }

            if (
                in_array($toState, ['approved', 'changes_requested', 'rejected'], true)
                || ($toState === 'queued' && in_array($fromState, ['draft', 'changes_requested'], true))
                || ($toState === 'cancelled' && in_array($fromState, ['draft', 'changes_requested'], true))
            ) {
                throw new AuthorizationException('This transition requires an authorized human actor.');
            }

            return;
        }

        $clientId = $request->client_id ?? $request->project?->client_id;
        $projectId = $request->project_id;

        if (in_array($toState, ['approved', 'changes_requested', 'rejected'], true)) {
            $this->requirePermission($actor, 'development_requests.approve', $clientId, $projectId);

            return;
        }

        if (in_array($toState, ['cancelling', 'cancelled'], true)) {
            $this->requirePermission($actor, 'development_requests.cancel', $clientId, $projectId);

            return;
        }

        if ($toState === 'queued' && in_array($fromState, ['draft', 'changes_requested'], true)) {
            if ((int) $actor->getKey() !== (int) $request->owner_user_id && ! $actor->isSuperAdmin()) {
                throw new AuthorizationException('Only the request owner may submit or resubmit this request.');
            }

            if (
                ! $actor->hasPermission('development_requests.update', $clientId, $projectId)
                && ! $actor->hasPermission('development_requests.create', $clientId, $projectId)
            ) {
                throw new AuthorizationException(
                    'The request owner lacks permission to submit or resubmit this request.'
                );
            }
        }
    }

    private function requirePermission(
        User $actor,
        string $permission,
        ?int $clientId,
        ?int $projectId
    ): void {
        if (! $actor->hasPermission($permission, $clientId, $projectId)) {
            throw new AuthorizationException("Missing required permission: {$permission}");
        }
    }

    private function isExactRetry(
        DevelopmentRequestStatusHistory $existing,
        string $targetState,
        ?User $actor,
        ?string $reason,
        ?string $correlationIdentifier,
        ?string $actorType,
        ?string $actorLabel,
        ?array $metadata
    ): bool {
        return $existing->new_state === $targetState
            && ($existing->actor_user_id === null ? null : (int) $existing->actor_user_id)
                === ($actor?->getKey() === null ? null : (int) $actor->getKey())
            && $existing->actor_type === $actorType
            && $existing->actor_label === $actorLabel
            && $existing->reason === $reason
            && $existing->correlation_identifier === $correlationIdentifier
            && $this->canonicalize($existing->metadata) === $this->canonicalize($metadata);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
