<?php

namespace App\Services;

use App\Exceptions\WorkerRequestConflictException;
use App\Models\WorkerApiRequest;
use App\ValueObjects\WorkerApiResponse;
use App\ValueObjects\WorkerIdentity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

class WorkerApiRequestLedger
{
    public function __construct(
        private readonly AgentJobQueue $jobs,
        private readonly WorkerSecurityAuditor $auditor
    ) {}

    public function execute(
        string $requestIdentifier,
        WorkerIdentity $identity,
        string $operation,
        CarbonImmutable $requestedAt,
        array $payload,
        Closure $callback
    ): WorkerApiResponse {
        $payloadHash = $this->jobs->hash($payload);

        try {
            return DB::transaction(function () use (
                $requestIdentifier,
                $identity,
                $operation,
                $requestedAt,
                $payloadHash,
                $callback
            ): WorkerApiResponse {
                $existing = WorkerApiRequest::query()
                    ->where('request_identifier', $requestIdentifier)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->replay($existing, $identity, $operation, $payloadHash, $requestedAt);
                }

                $ledger = WorkerApiRequest::query()->create([
                    'request_identifier' => $requestIdentifier,
                    'worker_identity' => $identity->email,
                    'operation' => $operation,
                    'request_payload_hash' => $payloadHash,
                    'requested_at' => $requestedAt,
                    'expires_at' => now()->addHours(
                        config('devforge.worker_api_request_retention_hours')
                    ),
                ]);

                $response = $callback($payloadHash);
                if (! $response instanceof WorkerApiResponse) {
                    throw new WorkerRequestConflictException('Worker API operations must return a structured response.');
                }

                $ledger->forceFill([
                    'response_status' => $response->status,
                    'response_body_ciphertext' => Crypt::encryptString(
                        json_encode($response->body, JSON_THROW_ON_ERROR)
                    ),
                ])->save();

                return $response;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            $existing = WorkerApiRequest::query()
                ->where('request_identifier', $requestIdentifier)
                ->firstOrFail();

            return $this->replay($existing, $identity, $operation, $payloadHash, $requestedAt);
        }
    }

    private function replay(
        WorkerApiRequest $existing,
        WorkerIdentity $identity,
        string $operation,
        string $payloadHash,
        CarbonImmutable $requestedAt
    ): WorkerApiResponse {
        if (
            ! hash_equals($existing->worker_identity, $identity->email)
            || ! hash_equals($existing->operation, $operation)
            || ! hash_equals($existing->request_payload_hash, $payloadHash)
            || ! $existing->requested_at->equalTo($requestedAt)
        ) {
            $this->auditor->record(
                'replay_rejected',
                'request_replay_conflict',
                $identity->email,
                $existing->request_identifier,
                $operation
            );
            throw new WorkerRequestConflictException(
                'The worker request identifier was already used for a different request.'
            );
        }

        if ($existing->response_body_ciphertext === null || $existing->response_status === null) {
            $this->auditor->record(
                'replay_rejected',
                'request_still_processing',
                $identity->email,
                $existing->request_identifier,
                $operation
            );
            throw new WorkerRequestConflictException('The original worker request is still being processed.');
        }

        try {
            $body = json_decode(
                Crypt::decryptString($existing->response_body_ciphertext),
                true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new WorkerRequestConflictException(
                'The stored worker response cannot be replayed.',
                previous: $exception
            );
        }

        return (new WorkerApiResponse($body, $existing->response_status))->asReplay();
    }
}
