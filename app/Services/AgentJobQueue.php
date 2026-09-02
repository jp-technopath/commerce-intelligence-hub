<?php

namespace App\Services;

use App\Enums\AgentJobStatus;
use App\Exceptions\AgentJobOperationException;
use App\Models\AgentJob;
use App\Models\AgentJobEvent;
use App\Models\DevelopmentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentJobQueue
{
    public function __construct(private readonly AgentJobPayloadFactory $payloads) {}

    public function enqueueForRequest(DevelopmentRequest $request): AgentJob
    {
        return DB::transaction(function () use ($request): AgentJob {
            $request = DevelopmentRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $snapshot = $request->routing_snapshot ?? [];
            $role = $this->roleFor($request->request_type);
            $allowedRoles = $snapshot['allowed_agent_roles'] ?? [];
            $workerIdentity = strtolower((string) data_get($snapshot, 'gcp.worker_service_account_email'));

            if ($workerIdentity === '' || filter_var($workerIdentity, FILTER_VALIDATE_EMAIL) === false) {
                throw new AgentJobOperationException(
                    'The request routing snapshot does not contain a valid worker identity.',
                    422
                );
            }

            if (! in_array($role, $allowedRoles, true)) {
                throw new AgentJobOperationException(
                    "The {$role} role is not allowed by the request routing snapshot.",
                    422
                );
            }

            $existing = AgentJob::query()
                ->where('development_request_id', $request->getKey())
                ->where('role', $role)
                ->where('attempt', 1)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $payload = $this->payloads->build($request, $role);
            $payloadHash = $this->hash($payload);
            $job = AgentJob::query()->create([
                'job_identifier' => (string) Str::uuid(),
                'development_request_id' => $request->getKey(),
                'project_environment_mapping_id' => $request->project_environment_mapping_id,
                'correlation_identifier' => $request->active_run_correlation_id
                    ?: $request->correlation_identifier,
                'role' => $role,
                'status' => AgentJobStatus::Queued,
                'worker_service_account_email' => $workerIdentity,
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'attempt' => 1,
                'available_at' => now(),
            ]);

            AgentJobEvent::query()->create([
                'agent_job_id' => $job->getKey(),
                'operation' => 'queue',
                'event_type' => 'queued',
                'request_payload_hash' => $payloadHash,
                'metadata' => [
                    'role' => $role,
                    'mapping_id' => $snapshot['mapping_id'] ?? null,
                    'mapping_version' => $snapshot['mapping_version'] ?? null,
                ],
            ]);

            return $job->refresh();
        });
    }

    public function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    private function roleFor(string $requestType): string
    {
        return match ($requestType) {
            'investigation' => 'research_collector',
            'development' => 'developer',
            'qa' => 'qa',
            default => throw new AgentJobOperationException(
                "Development Request type {$requestType} cannot be dispatched to an agent.",
                422
            ),
        };
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
