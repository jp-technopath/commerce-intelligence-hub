<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AgentJobOperationException;
use App\Exceptions\WorkerAuthorizationException;
use App\Exceptions\WorkerRequestConflictException;
use App\Http\Controllers\Controller;
use App\Models\AgentJob;
use App\Services\AgentJobService;
use App\Services\WorkerApiRequestLedger;
use App\ValueObjects\WorkerApiResponse;
use App\ValueObjects\WorkerIdentity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class WorkerAgentJobController extends Controller
{
    private const ROLES = ['research_collector', 'lead_investigator', 'developer', 'qa'];

    public function __construct(
        private readonly AgentJobService $jobs,
        private readonly WorkerApiRequestLedger $ledger
    ) {}

    public function workerHeartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mapping_id' => ['required', 'integer', 'min:1'],
            'mapping_version' => ['required', 'integer', 'min:1'],
            'worker_identifier' => ['required', 'string', 'max:255'],
            'state' => ['required', Rule::in(['ready', 'busy', 'draining', 'offline'])],
        ]);

        return $this->execute($request, 'worker.heartbeat', null, function ($payloadHash) use ($validated, $request) {
            return $this->jobs->workerHeartbeat(
                $this->identity($request),
                $validated['mapping_id'],
                $validated['mapping_version'],
                $validated['worker_identifier'],
                $validated['state'],
                $this->requestIdentifier($request),
                $payloadHash
            );
        });
    }

    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['sometimes', 'array', 'max:4'],
            'roles.*' => ['string', Rule::in(self::ROLES)],
        ]);

        return $this->execute($request, 'jobs.claim', null, function ($payloadHash) use ($validated, $request) {
            return $this->jobs->claim(
                $this->identity($request),
                array_values(array_unique($validated['roles'] ?? [])),
                $this->requestIdentifier($request),
                $payloadHash
            );
        });
    }

    public function heartbeat(Request $request, AgentJob $agentJob): JsonResponse
    {
        return $this->execute($request, 'jobs.heartbeat', $agentJob, function ($payloadHash) use ($request, $agentJob) {
            return $this->jobs->heartbeat(
                $agentJob,
                $this->identity($request),
                $this->leaseToken($request),
                $this->requestIdentifier($request),
                $payloadHash
            );
        });
    }

    public function progress(Request $request, AgentJob $agentJob): JsonResponse
    {
        $validated = $request->validate([
            'percent' => ['required', 'integer', 'between:0,100'],
            'stage' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return $this->execute($request, 'jobs.progress', $agentJob, function ($payloadHash) use ($validated, $request, $agentJob) {
            return $this->jobs->progress(
                $agentJob,
                $this->identity($request),
                $this->leaseToken($request),
                $validated['percent'],
                $validated['stage'],
                $validated['message'],
                $this->requestIdentifier($request),
                $payloadHash
            );
        });
    }

    public function result(Request $request, AgentJob $agentJob): JsonResponse
    {
        $validated = $request->validate([
            'summary' => ['required', 'string', 'max:10000'],
            'output' => ['sometimes', 'array'],
            'artifacts' => ['present', 'array', 'max:50'],
            'artifacts.*.type' => ['required', 'string', 'max:100'],
            'artifacts.*.path' => ['required', 'string', 'max:2000'],
            'artifacts.*.hash' => ['sometimes', 'string', 'max:255'],
            'warnings' => ['present', 'array', 'max:50'],
            'warnings.*' => ['string', 'max:2000'],
        ]);

        return $this->execute($request, 'jobs.result', $agentJob, function ($payloadHash) use ($validated, $request, $agentJob) {
            return $this->jobs->result(
                $agentJob,
                $this->identity($request),
                $this->leaseToken($request),
                $validated,
                $this->requestIdentifier($request),
                $payloadHash
            );
        });
    }

    public function failure(Request $request, AgentJob $agentJob): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'retryable' => ['required', 'boolean'],
            'details' => ['sometimes', 'array'],
        ]);

        return $this->execute($request, 'jobs.failure', $agentJob, function ($payloadHash) use ($validated, $request, $agentJob) {
            return $this->jobs->failure(
                $agentJob,
                $this->identity($request),
                $this->leaseToken($request),
                $validated,
                $this->requestIdentifier($request),
                $payloadHash
            );
        });
    }

    public function complete(Request $request, AgentJob $agentJob): JsonResponse
    {
        return $this->execute($request, 'jobs.complete', $agentJob, function ($payloadHash) use ($request, $agentJob) {
            return $this->jobs->complete(
                $agentJob,
                $this->identity($request),
                $this->leaseToken($request),
                $this->requestIdentifier($request),
                $payloadHash
            );
        });
    }

    public function cancellation(Request $request, AgentJob $agentJob): JsonResponse
    {
        return $this->execute($request, 'jobs.cancellation', $agentJob, function () use ($request, $agentJob) {
            return $this->jobs->cancellation(
                $agentJob,
                $this->identity($request),
                $this->leaseToken($request)
            );
        });
    }

    public function cancelled(Request $request, AgentJob $agentJob): JsonResponse
    {
        return $this->execute($request, 'jobs.cancelled', $agentJob, function ($payloadHash) use ($request, $agentJob) {
            return $this->jobs->acknowledgeCancellation(
                $agentJob,
                $this->identity($request),
                $this->leaseToken($request),
                $this->requestIdentifier($request),
                $payloadHash
            );
        });
    }

    private function execute(
        Request $request,
        string $operation,
        ?AgentJob $job,
        Closure $callback
    ): JsonResponse {
        try {
            $payload = $request->all();
            if ($job !== null) {
                $payload['_job_identifier'] = $job->job_identifier;
                $payload['_lease_token_hash'] = hash('sha256', $this->leaseToken($request));
                $operation .= ':'.$job->job_identifier;
            }

            $result = $this->ledger->execute(
                $this->requestIdentifier($request),
                $this->identity($request),
                $operation,
                $this->requestedAt($request),
                $payload,
                $callback
            );

            $response = response()->json($result->body, $result->status);
            if ($result->replayed) {
                $response->headers->set('X-DevForge-Idempotent-Replay', 'true');
            }

            return $response;
        } catch (WorkerAuthorizationException $exception) {
            return $this->error('worker_not_authorized', $exception->getMessage(), 403);
        } catch (WorkerRequestConflictException $exception) {
            return $this->error('request_replay_conflict', $exception->getMessage(), 409);
        } catch (AgentJobOperationException $exception) {
            return $this->error('job_operation_rejected', $exception->getMessage(), $exception->httpStatus);
        } catch (ModelNotFoundException) {
            return $this->error('job_not_found', 'The agent job was not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('worker_api_error', 'The worker request could not be completed.', 500);
        }
    }

    private function identity(Request $request): WorkerIdentity
    {
        return $request->attributes->get('worker_identity');
    }

    private function requestIdentifier(Request $request): string
    {
        return $request->attributes->get('worker_request_identifier');
    }

    private function requestedAt(Request $request): CarbonImmutable
    {
        return $request->attributes->get('worker_requested_at');
    }

    private function leaseToken(Request $request): string
    {
        $token = trim((string) $request->header('X-DevForge-Lease-Token'));
        if ($token === '' || strlen($token) > 512) {
            throw new WorkerAuthorizationException('A valid job lease token is required.');
        }

        return $token;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
