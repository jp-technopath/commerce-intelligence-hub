<?php

namespace App\Services;

use App\Enums\AgentJobStatus;
use App\Enums\DevelopmentRequestStatus;
use App\Exceptions\AgentJobOperationException;
use App\Exceptions\WorkerAuthorizationException;
use App\Models\AgentJob;
use App\Models\AgentJobEvent;
use App\Models\DevelopmentRequest;
use App\Models\ProjectEnvironmentMapping;
use App\Models\User;
use App\ValueObjects\WorkerApiResponse;
use App\ValueObjects\WorkerIdentity;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentJobService
{
    public function __construct(
        private readonly DevelopmentRequestLifecycleService $lifecycle,
        private readonly VmWorkerRegistry $workers,
        private readonly PayloadRedactor $redactor
    ) {}

    public function workerHeartbeat(
        WorkerIdentity $identity,
        int $mappingId,
        int $mappingVersion,
        string $workerIdentifier,
        string $state,
        string $requestIdentifier,
        string $payloadHash
    ): WorkerApiResponse {
        $mapping = ProjectEnvironmentMapping::query()
            ->whereKey($mappingId)
            ->where('version', $mappingVersion)
            ->where('is_active', true)
            ->first();

        if ($mapping === null || ! hash_equals(
            strtolower((string) $mapping->worker_service_account_email),
            $identity->email
        )) {
            throw new WorkerAuthorizationException(
                'This worker identity is not authorized for the selected project environment.'
            );
        }

        $runtime = $this->workers->heartbeat(
            $mapping->snapshot(),
            $workerIdentifier,
            $state
        );

        return new WorkerApiResponse([
            'data' => [
                'mapping_id' => $mapping->getKey(),
                'mapping_version' => $mapping->version,
                'worker_state' => $runtime->worker_state,
                'heartbeat_at' => $runtime->last_worker_heartbeat_at?->toIso8601String(),
                'request_identifier' => $requestIdentifier,
                'payload_hash' => $payloadHash,
            ],
        ]);
    }

    public function claim(
        WorkerIdentity $identity,
        array $roles,
        string $requestIdentifier,
        string $payloadHash
    ): WorkerApiResponse {
        $job = AgentJob::query()
            ->where('worker_service_account_email', $identity->email)
            ->where('status', AgentJobStatus::Queued->value)
            ->where('available_at', '<=', now())
            ->whereNull('cancellation_requested_at')
            ->when($roles !== [], fn ($query) => $query->whereIn('role', $roles))
            ->orderBy('available_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($job === null) {
            return new WorkerApiResponse(['data' => null]);
        }

        $leaseToken = Str::random(64);
        $leaseExpiresAt = now()->addSeconds(config('devforge.agent_job_lease_seconds'));
        $job->forceFill([
            'status' => AgentJobStatus::Claimed,
            'claimed_at' => now(),
            'claimed_by_worker_identity' => $identity->email,
            'claim_request_identifier' => $requestIdentifier,
            'lease_token_hash' => hash('sha256', $leaseToken),
            'lease_token_ciphertext' => Crypt::encryptString($leaseToken),
            'lease_expires_at' => $leaseExpiresAt,
            'last_heartbeat_at' => now(),
        ])->save();

        $this->recordEvent(
            $job,
            'claim',
            'claimed',
            $identity,
            $requestIdentifier,
            $payloadHash,
            ['lease_expires_at' => $leaseExpiresAt->toIso8601String()]
        );
        $this->moveRequestToRunning($job->developmentRequest, $requestIdentifier);

        return new WorkerApiResponse([
            'data' => [
                'job_identifier' => $job->job_identifier,
                'correlation_identifier' => $job->correlation_identifier,
                'role' => $job->role,
                'attempt' => $job->attempt,
                'status' => AgentJobStatus::Claimed->value,
                'lease_token' => $leaseToken,
                'lease_expires_at' => $leaseExpiresAt->toIso8601String(),
                'payload' => $job->payload,
                'payload_hash' => $job->payload_hash,
            ],
        ]);
    }

    public function heartbeat(
        AgentJob $job,
        WorkerIdentity $identity,
        string $leaseToken,
        string $requestIdentifier,
        string $payloadHash
    ): WorkerApiResponse {
        $job = $this->lockAndAuthorize($job, $identity, $leaseToken);
        $leaseExpiresAt = now()->addSeconds(config('devforge.agent_job_lease_seconds'));
        $job->forceFill([
            'status' => $job->status === AgentJobStatus::Claimed
                ? AgentJobStatus::Running
                : $job->status,
            'last_heartbeat_at' => now(),
            'lease_expires_at' => $leaseExpiresAt,
        ])->save();

        $this->recordEvent(
            $job,
            'heartbeat',
            'heartbeat',
            $identity,
            $requestIdentifier,
            $payloadHash,
            ['lease_expires_at' => $leaseExpiresAt->toIso8601String()]
        );

        return $this->jobStateResponse($job->refresh());
    }

    public function progress(
        AgentJob $job,
        WorkerIdentity $identity,
        string $leaseToken,
        int $percent,
        string $stage,
        string $message,
        string $requestIdentifier,
        string $payloadHash
    ): WorkerApiResponse {
        $job = $this->lockAndAuthorize($job, $identity, $leaseToken);
        $job->forceFill([
            'status' => AgentJobStatus::Running,
            'progress_percent' => $percent,
            'progress_stage' => $stage,
            'progress_message' => $message,
            'last_heartbeat_at' => now(),
            'lease_expires_at' => now()->addSeconds(config('devforge.agent_job_lease_seconds')),
        ])->save();

        $this->recordEvent(
            $job,
            'progress',
            'progress',
            $identity,
            $requestIdentifier,
            $payloadHash,
            ['percent' => $percent, 'stage' => $stage, 'message' => $message]
        );

        return $this->jobStateResponse($job->refresh());
    }

    public function result(
        AgentJob $job,
        WorkerIdentity $identity,
        string $leaseToken,
        array $result,
        string $requestIdentifier,
        string $payloadHash
    ): WorkerApiResponse {
        $job = $this->lockAndAuthorize($job, $identity, $leaseToken);
        $result = $this->redactor->redact($result);
        $job->forceFill([
            'status' => AgentJobStatus::ResultReceived,
            'result' => $result,
            'progress_percent' => 100,
            'last_heartbeat_at' => now(),
        ])->save();

        $this->recordEvent(
            $job,
            'result',
            'result_received',
            $identity,
            $requestIdentifier,
            $payloadHash,
            ['summary' => $result['summary'] ?? null, 'artifact_count' => count($result['artifacts'] ?? [])]
        );

        return $this->jobStateResponse($job->refresh());
    }

    public function failure(
        AgentJob $job,
        WorkerIdentity $identity,
        string $leaseToken,
        array $failure,
        string $requestIdentifier,
        string $payloadHash
    ): WorkerApiResponse {
        $job = $this->lockAndAuthorize($job, $identity, $leaseToken);
        $failure = $this->redactor->redact($failure);
        $job->forceFill([
            'status' => AgentJobStatus::Failed,
            'failure' => $failure,
            'completed_at' => now(),
        ])->save();

        $this->recordEvent(
            $job,
            'failure',
            'failed',
            $identity,
            $requestIdentifier,
            $payloadHash,
            $failure
        );
        $this->moveRequestToFailed($job->developmentRequest, $failure, $requestIdentifier);

        return $this->jobStateResponse($job->refresh());
    }

    public function complete(
        AgentJob $job,
        WorkerIdentity $identity,
        string $leaseToken,
        string $requestIdentifier,
        string $payloadHash
    ): WorkerApiResponse {
        $job = $this->lockAndAuthorize(
            $job,
            $identity,
            $leaseToken,
            [AgentJobStatus::Running, AgentJobStatus::ResultReceived]
        );
        $job->forceFill([
            'status' => AgentJobStatus::Completed,
            'progress_percent' => 100,
            'completed_at' => now(),
        ])->save();

        $this->recordEvent(
            $job,
            'complete',
            'completed',
            $identity,
            $requestIdentifier,
            $payloadHash
        );
        $this->moveRequestToAwaitingApproval($job->developmentRequest, $requestIdentifier);

        return $this->jobStateResponse($job->refresh());
    }

    public function cancellation(
        AgentJob $job,
        WorkerIdentity $identity,
        string $leaseToken
    ): WorkerApiResponse {
        $job = $this->lockAndAuthorize(
            $job,
            $identity,
            $leaseToken,
            [AgentJobStatus::Claimed, AgentJobStatus::Running, AgentJobStatus::ResultReceived, AgentJobStatus::Cancelling],
            allowExpiredLease: true
        );

        return new WorkerApiResponse([
            'data' => [
                'job_identifier' => $job->job_identifier,
                'status' => $job->status->value,
                'cancellation_requested' => $job->cancellation_requested_at !== null,
                'cancellation_requested_at' => $job->cancellation_requested_at?->toIso8601String(),
                'cancellation_reason' => $job->cancellation_reason,
            ],
        ]);
    }

    public function acknowledgeCancellation(
        AgentJob $job,
        WorkerIdentity $identity,
        string $leaseToken,
        string $requestIdentifier,
        string $payloadHash
    ): WorkerApiResponse {
        $job = $this->lockAndAuthorize(
            $job,
            $identity,
            $leaseToken,
            [AgentJobStatus::Cancelling],
            allowExpiredLease: true
        );
        $job->forceFill([
            'status' => AgentJobStatus::Cancelled,
            'cancelled_at' => now(),
            'completed_at' => now(),
        ])->save();
        $this->recordEvent(
            $job,
            'cancelled',
            'cancelled',
            $identity,
            $requestIdentifier,
            $payloadHash
        );
        $this->moveRequestToCancelled($job->developmentRequest, $requestIdentifier);

        return $this->jobStateResponse($job->refresh());
    }

    public function requestCancellation(AgentJob $job, string $reason, ?User $actor = null): AgentJob
    {
        return DB::transaction(function () use ($job, $reason, $actor): AgentJob {
            $job = AgentJob::query()->lockForUpdate()->findOrFail($job->getKey());
            if ($job->status->isTerminal()) {
                return $job;
            }

            if ($job->status === AgentJobStatus::Queued) {
                $job->forceFill([
                    'status' => AgentJobStatus::Cancelled,
                    'cancellation_requested_at' => now(),
                    'cancellation_reason' => $reason,
                    'cancelled_at' => now(),
                    'completed_at' => now(),
                ])->save();
                $this->moveRequestToCancelled($job->developmentRequest, "cancel:{$job->job_identifier}", $actor);
            } else {
                $job->forceFill([
                    'status' => AgentJobStatus::Cancelling,
                    'cancellation_requested_at' => now(),
                    'cancellation_reason' => $reason,
                ])->save();
                $request = $job->developmentRequest;
                if ($request->state === DevelopmentRequestStatus::Running) {
                    $this->lifecycle->transitionState(
                        $request,
                        DevelopmentRequestStatus::Cancelling,
                        $actor,
                        $reason,
                        "cancel-requested:{$job->job_identifier}",
                        $job->correlation_identifier,
                        $actor === null ? 'system' : null,
                        $actor === null ? 'Agent job controller' : null
                    );
                }
            }

            $this->recordEvent(
                $job,
                'cancel',
                'cancellation_requested',
                metadata: ['reason' => $reason]
            );

            return $job->refresh();
        });
    }

    private function lockAndAuthorize(
        AgentJob $job,
        WorkerIdentity $identity,
        string $leaseToken,
        array $allowedStatuses = [AgentJobStatus::Claimed, AgentJobStatus::Running],
        bool $allowExpiredLease = false
    ): AgentJob {
        $job = AgentJob::query()->lockForUpdate()->findOrFail($job->getKey());
        if (
            ! hash_equals($job->worker_service_account_email, $identity->email)
            || ! hash_equals((string) $job->claimed_by_worker_identity, $identity->email)
        ) {
            throw new WorkerAuthorizationException('This worker identity does not own the job lease.');
        }

        if ($job->lease_token_hash === null || ! hash_equals($job->lease_token_hash, hash('sha256', $leaseToken))) {
            throw new WorkerAuthorizationException('The job lease token is invalid.');
        }

        if (! $allowExpiredLease && $job->lease_expires_at?->isPast()) {
            throw new AgentJobOperationException('The job lease has expired.', 409);
        }

        if (! in_array($job->status, $allowedStatuses, true)) {
            throw new AgentJobOperationException(
                "The operation is not allowed while the job is {$job->status->value}."
            );
        }

        return $job;
    }

    private function recordEvent(
        AgentJob $job,
        string $operation,
        string $eventType,
        ?WorkerIdentity $identity = null,
        ?string $requestIdentifier = null,
        ?string $payloadHash = null,
        ?array $metadata = null
    ): void {
        AgentJobEvent::query()->create([
            'agent_job_id' => $job->getKey(),
            'operation' => $operation,
            'event_type' => $eventType,
            'worker_identity' => $identity?->email,
            'request_identifier' => $requestIdentifier,
            'request_payload_hash' => $payloadHash,
            'metadata' => $this->redactor->redact($metadata),
        ]);
    }

    private function jobStateResponse(AgentJob $job): WorkerApiResponse
    {
        return new WorkerApiResponse([
            'data' => [
                'job_identifier' => $job->job_identifier,
                'status' => $job->status->value,
                'progress_percent' => $job->progress_percent,
                'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
                'cancellation_requested' => $job->cancellation_requested_at !== null,
            ],
        ]);
    }

    private function moveRequestToRunning(DevelopmentRequest $request, string $requestIdentifier): void
    {
        $request->refresh();
        if ($request->state !== DevelopmentRequestStatus::WaitingForWorker) {
            return;
        }

        $this->lifecycle->transitionState(
            $request,
            DevelopmentRequestStatus::Running,
            reason: 'The mapped worker claimed the agent job.',
            idempotencyKey: "agent-job-claimed:{$requestIdentifier}",
            correlationIdentifier: $request->active_run_correlation_id ?: $request->correlation_identifier,
            actorType: 'agent',
            actorLabel: 'Mapped VM worker'
        );
    }

    private function moveRequestToFailed(
        DevelopmentRequest $request,
        array $failure,
        string $requestIdentifier
    ): void {
        $request->refresh();
        if ($request->state !== DevelopmentRequestStatus::Running) {
            return;
        }

        $this->lifecycle->transitionState(
            $request,
            DevelopmentRequestStatus::Failed,
            reason: (string) ($failure['message'] ?? 'The agent job failed.'),
            idempotencyKey: "agent-job-failed:{$requestIdentifier}",
            correlationIdentifier: $request->active_run_correlation_id ?: $request->correlation_identifier,
            actorType: 'agent',
            actorLabel: 'Mapped VM worker',
            metadata: ['error_code' => $failure['code'] ?? 'agent_job_failed']
        );
    }

    private function moveRequestToAwaitingApproval(
        DevelopmentRequest $request,
        string $requestIdentifier
    ): void {
        $request->refresh();
        if ($request->state !== DevelopmentRequestStatus::Running) {
            return;
        }

        $this->lifecycle->transitionState(
            $request,
            DevelopmentRequestStatus::AwaitingApproval,
            reason: 'The agent job completed and is ready for human review.',
            idempotencyKey: "agent-job-completed:{$requestIdentifier}",
            correlationIdentifier: $request->active_run_correlation_id ?: $request->correlation_identifier,
            actorType: 'agent',
            actorLabel: 'Mapped VM worker'
        );
    }

    private function moveRequestToCancelled(
        DevelopmentRequest $request,
        string $requestIdentifier,
        ?User $actor = null
    ): void {
        $request->refresh();
        if ($request->state === DevelopmentRequestStatus::WaitingForWorker) {
            $this->lifecycle->transitionState(
                $request,
                DevelopmentRequestStatus::Cancelling,
                $actor,
                'The queued agent job was cancelled.',
                "agent-job-cancelling:{$requestIdentifier}",
                $request->active_run_correlation_id ?: $request->correlation_identifier,
                $actor === null ? 'system' : null,
                $actor === null ? 'Agent job controller' : null
            );
            $request->refresh();
        }

        if ($request->state === DevelopmentRequestStatus::Cancelling) {
            $this->lifecycle->transitionState(
                $request,
                DevelopmentRequestStatus::Cancelled,
                $actor,
                'The mapped worker confirmed cancellation.',
                "agent-job-cancelled:{$requestIdentifier}",
                $request->active_run_correlation_id ?: $request->correlation_identifier,
                $actor === null ? 'agent' : null,
                $actor === null ? 'Mapped VM worker' : null
            );
        }
    }
}
