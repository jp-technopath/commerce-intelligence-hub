<?php

namespace Tests\Feature;

use App\Contracts\WorkerIdentityVerifier;
use App\Enums\AgentJobStatus;
use App\Enums\DevelopmentRequestStatus;
use App\Exceptions\WorkerAuthenticationException;
use App\Models\AgentJob;
use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\Repository;
use App\Models\WorkerApiRequest;
use App\Services\AgentJobQueue;
use App\Services\AgentJobService;
use App\ValueObjects\WorkerIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkerAgentJobApiTest extends TestCase
{
    use RefreshDatabase;

    public const WORKER_EMAIL = 'agent-commerce-hub@development-project.iam.gserviceaccount.com';

    public const OTHER_EMAIL = 'agent-other@development-project.iam.gserviceaccount.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('devforge.worker_api_audience', 'https://forge-worker-api.example.test');
        config()->set('devforge.worker_api_request_skew_seconds', 300);
        config()->set('devforge.agent_job_lease_seconds', 120);
        $this->app->instance(WorkerIdentityVerifier::class, new FakeWorkerIdentityVerifier);
    }

    #[Test]
    public function authentication_timestamp_and_schema_failures_are_rejected_and_audited(): void
    {
        $headers = $this->headers(token: '');
        unset($headers['Authorization']);

        $this->withHeaders($headers)
            ->postJson('/api/internal/v1/worker/jobs/claim', [])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'authentication_required');

        $this->withHeaders($this->headers(timestamp: now()->subMinutes(10)->toIso8601String()))
            ->postJson('/api/internal/v1/worker/jobs/claim', [])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'expired_request');

        $this->withHeaders($this->headers())
            ->postJson('/api/internal/v1/worker/jobs/claim', ['roles' => ['owner']])
            ->assertUnprocessable();

        $this->assertDatabaseHas('worker_api_security_events', [
            'event_type' => 'authentication_failed',
            'reason_code' => 'authentication_required',
        ]);
        $this->assertDatabaseHas('worker_api_security_events', [
            'event_type' => 'request_rejected',
            'reason_code' => 'expired_request',
        ]);
        $this->assertDatabaseHas('worker_api_security_events', [
            'event_type' => 'request_rejected',
            'reason_code' => 'http_422',
        ]);
    }

    #[Test]
    public function only_the_service_account_on_the_active_mapping_can_register_a_worker(): void
    {
        $mapping = $this->mapping();
        $payload = [
            'mapping_id' => $mapping->id,
            'mapping_version' => 1,
            'worker_identifier' => 'commerce-hub-worker-1',
            'state' => 'ready',
        ];

        $this->withHeaders($this->headers('other-worker'))
            ->postJson('/api/internal/v1/worker/heartbeat', $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'worker_not_authorized');

        $this->withHeaders($this->headers())
            ->postJson('/api/internal/v1/worker/heartbeat', $payload)
            ->assertOk()
            ->assertJsonPath('data.mapping_id', $mapping->id)
            ->assertJsonPath('data.worker_state', 'ready');

        $this->assertDatabaseHas('vm_runtime_states', [
            'vm_name' => 'agent-commerce-hub',
            'worker_identifier' => 'commerce-hub-worker-1',
            'worker_state' => 'ready',
        ]);
    }

    #[Test]
    public function a_queued_job_is_durable_claimable_only_by_its_mapped_identity_and_claim_is_idempotent(): void
    {
        $job = $this->queuedJob();

        $this->withHeaders($this->headers('other-worker'))
            ->postJson('/api/internal/v1/worker/jobs/claim', ['roles' => ['developer']])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('agent_jobs', [
            'job_identifier' => $job->job_identifier,
            'status' => AgentJobStatus::Queued->value,
        ]);

        $requestId = (string) Str::uuid();
        $timestamp = now()->toIso8601String();
        $headers = $this->headers(requestId: $requestId, timestamp: $timestamp);
        $first = $this->withHeaders($headers)
            ->postJson('/api/internal/v1/worker/jobs/claim', ['roles' => ['developer']])
            ->assertOk()
            ->assertJsonPath('data.job_identifier', $job->job_identifier);

        $leaseToken = $first->json('data.lease_token');
        $this->assertNotEmpty($leaseToken);

        $this->withHeaders($headers)
            ->postJson('/api/internal/v1/worker/jobs/claim', ['roles' => ['developer']])
            ->assertOk()
            ->assertHeader('X-DevForge-Idempotent-Replay', 'true')
            ->assertJsonPath('data.lease_token', $leaseToken);

        $this->assertDatabaseCount('agent_jobs', 1);
        $this->assertDatabaseHas('development_requests', [
            'id' => $job->development_request_id,
            'state' => DevelopmentRequestStatus::Running->value,
        ]);
        $this->assertSame(
            1,
            $job->events()->where('event_type', 'claimed')->count()
        );
    }

    #[Test]
    public function heartbeat_progress_and_duplicate_callbacks_preserve_one_side_effect(): void
    {
        [$job, $leaseToken] = $this->claimJob();

        $this->withHeaders($this->headers(leaseToken: $leaseToken))
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/heartbeat", [])
            ->assertOk()
            ->assertJsonPath('data.status', AgentJobStatus::Running->value);

        $requestId = (string) Str::uuid();
        $timestamp = now()->toIso8601String();
        $headers = $this->headers(
            requestId: $requestId,
            timestamp: $timestamp,
            leaseToken: $leaseToken
        );
        $payload = ['percent' => 35, 'stage' => 'analysis', 'message' => 'Reviewing the code paths.'];

        $this->withHeaders($headers)
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/progress", $payload)
            ->assertOk()
            ->assertJsonPath('data.progress_percent', 35);

        $this->withHeaders($headers)
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/progress", $payload)
            ->assertOk()
            ->assertHeader('X-DevForge-Idempotent-Replay', 'true');

        $this->withHeaders($headers)
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/progress", [
                'percent' => 36,
                'stage' => 'analysis',
                'message' => 'Changed replay payload.',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'request_replay_conflict');

        $this->assertSame(
            1,
            $job->events()->where('event_type', 'progress')->count()
        );
        $this->assertDatabaseHas('worker_api_security_events', [
            'event_type' => 'replay_rejected',
            'reason_code' => 'request_replay_conflict',
        ]);
    }

    #[Test]
    public function another_authorized_google_identity_cannot_update_a_claimed_job(): void
    {
        [$job, $leaseToken] = $this->claimJob();

        $this->withHeaders($this->headers('other-worker', leaseToken: $leaseToken))
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/progress", [
                'percent' => 10,
                'stage' => 'start',
                'message' => 'Attempted cross-project update.',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'worker_not_authorized');

        $this->assertNull($job->fresh()->progress_percent);
    }

    #[Test]
    public function structured_results_are_redacted_and_completion_moves_the_request_to_review(): void
    {
        [$job, $leaseToken] = $this->claimJob();
        $result = [
            'summary' => 'Implementation finished.',
            'output' => ['api_token' => 'must-not-be-retained', 'tests' => ['focused' => 'passed']],
            'artifacts' => [[
                'type' => 'commit',
                'path' => 'TPF-8-secure-agent-job-api',
                'hash' => str_repeat('a', 40),
            ]],
            'warnings' => [],
        ];

        $this->withHeaders($this->headers(leaseToken: $leaseToken))
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/result", $result)
            ->assertOk()
            ->assertJsonPath('data.status', AgentJobStatus::ResultReceived->value);

        $this->assertSame('[redacted]', $job->fresh()->result['output']['api_token']);

        $this->withHeaders($this->headers(leaseToken: $leaseToken))
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/complete", [])
            ->assertOk()
            ->assertJsonPath('data.status', AgentJobStatus::Completed->value);

        $this->assertSame(
            DevelopmentRequestStatus::AwaitingApproval,
            $job->developmentRequest->fresh()->state
        );
    }

    #[Test]
    public function failure_is_terminal_visible_and_preserves_a_safe_error_record(): void
    {
        [$job, $leaseToken] = $this->claimJob();

        $this->withHeaders($this->headers(leaseToken: $leaseToken))
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/failure", [
                'code' => 'tool_timeout',
                'message' => 'The code-analysis tool timed out.',
                'retryable' => true,
                'details' => ['authorization' => 'must-not-be-retained'],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AgentJobStatus::Failed->value);

        $job->refresh();
        $this->assertSame('[redacted]', $job->failure['details']['authorization']);
        $this->assertSame(DevelopmentRequestStatus::Failed, $job->developmentRequest->fresh()->state);
    }

    #[Test]
    public function cancellation_is_visible_to_the_worker_and_can_be_acknowledged(): void
    {
        [$job, $leaseToken] = $this->claimJob();
        $this->app->make(AgentJobService::class)
            ->requestCancellation($job, 'The engineer stopped the run.');

        $this->withHeaders($this->headers(leaseToken: $leaseToken))
            ->getJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.cancellation_requested', true)
            ->assertJsonPath('data.cancellation_reason', 'The engineer stopped the run.');

        $this->withHeaders($this->headers(leaseToken: $leaseToken))
            ->postJson("/api/internal/v1/worker/jobs/{$job->job_identifier}/cancelled", [])
            ->assertOk()
            ->assertJsonPath('data.status', AgentJobStatus::Cancelled->value);

        $this->assertSame(
            DevelopmentRequestStatus::Cancelled,
            $job->developmentRequest->fresh()->state
        );
    }

    #[Test]
    public function expired_replay_records_are_deleted_and_old_terminal_job_details_are_redacted(): void
    {
        config()->set('devforge.agent_job_detail_retention_days', 30);

        $job = $this->queuedJob();
        $job->forceFill([
            'status' => AgentJobStatus::Completed,
            'result' => ['summary' => 'Sensitive result details.'],
            'failure' => ['message' => 'Sensitive failure details.'],
            'progress_message' => 'Sensitive progress details.',
            'lease_token_ciphertext' => 'encrypted-lease-token',
            'completed_at' => now()->subDays(31),
        ])->save();

        WorkerApiRequest::query()->create([
            'request_identifier' => (string) Str::uuid(),
            'worker_identity' => self::WORKER_EMAIL,
            'operation' => 'jobs.complete:'.$job->job_identifier,
            'request_payload_hash' => hash('sha256', 'request'),
            'response_status' => 200,
            'response_body_ciphertext' => 'encrypted-response',
            'requested_at' => now()->subHours(25),
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('devforge:prune-worker-api-data')->assertSuccessful();

        $this->assertDatabaseCount('worker_api_requests', 0);
        $job->refresh();
        $this->assertNull($job->payload);
        $this->assertNull($job->result);
        $this->assertNull($job->failure);
        $this->assertNull($job->progress_message);
        $this->assertNull($job->lease_token_ciphertext);
        $this->assertNotNull($job->details_pruned_at);
        $this->assertNotNull($job->payload_hash);
    }

    private function claimJob(): array
    {
        $job = $this->queuedJob();
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/internal/v1/worker/jobs/claim', ['roles' => ['developer']])
            ->assertOk();

        return [$job->fresh(), $response->json('data.lease_token')];
    }

    private function queuedJob(): AgentJob
    {
        $request = DevelopmentRequest::query()->create([
            'request_type' => 'development',
            'state' => DevelopmentRequestStatus::WaitingForWorker,
            'title' => 'TPF-8 secure worker API test',
            'description' => 'Implement the approved secure worker communication contract.',
            'environment_key' => 'development',
            'source_type' => 'jira',
            'priority' => 'medium',
            'correlation_identifier' => (string) Str::uuid(),
            'jira_snapshot' => [
                'key' => 'TPF-8',
                'summary' => 'Create secure Forge agent-job API and asynchronous queue',
                'access_token' => 'must-not-be-in-worker-payload',
            ],
            'routing_snapshot' => [
                'mapping_id' => 42,
                'mapping_version' => 1,
                'repository_id' => 9,
                'environment_key' => 'development',
                'gcp' => [
                    'project_id' => 'development-project',
                    'zone' => 'us-central1-a',
                    'vm_name' => 'agent-commerce-hub',
                    'worker_service_account_email' => self::WORKER_EMAIL,
                ],
                'workspace_path' => '/workspaces/commerce-hub',
                'default_branch' => 'main',
                'allowed_agent_roles' => ['research_collector', 'lead_investigator', 'developer', 'qa'],
                'model_group_aliases' => ['intermediate' => 'forge-intermediate'],
            ],
            'selected_capability_tier' => 'intermediate',
        ]);

        $job = $this->app->make(AgentJobQueue::class)->enqueueForRequest($request);
        $this->assertArrayNotHasKey('access_token', $job->payload['request']['jira']);

        return $job;
    }

    private function mapping(): ProjectEnvironmentMapping
    {
        $client = Client::query()->create(['name' => 'Technopath', 'status' => 'active']);
        $project = Project::query()->create([
            'client_id' => $client->id,
            'name' => 'Commerce Hub',
            'code' => 'commerce-hub',
            'status' => 'active',
        ]);
        $repository = Repository::query()->create([
            'project_id' => $project->id,
            'client_id' => $client->id,
            'name' => 'commerce-intelligence-hub',
            'url' => 'https://github.com/jp-technopath/commerce-intelligence-hub.git',
            'provider' => 'github',
            'is_active' => true,
        ]);

        return ProjectEnvironmentMapping::query()->create([
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'environment_key' => 'development',
            'gcp_project_id' => 'development-project',
            'gcp_zone' => 'us-central1-a',
            'vm_name' => 'agent-commerce-hub',
            'worker_service_account_email' => self::WORKER_EMAIL,
            'workspace_path' => '/workspaces/commerce-hub',
            'default_branch' => 'main',
            'allowed_agent_roles' => ['research_collector', 'lead_investigator', 'developer', 'qa'],
            'allowed_capability_tiers' => ['intermediate'],
            'default_capability_tier' => 'intermediate',
            'tier_recommendation_policy' => [],
            'model_group_aliases' => ['intermediate' => 'forge-intermediate'],
            'version' => 1,
            'is_active' => true,
        ]);
    }

    private function headers(
        string $token = 'valid-worker',
        ?string $requestId = null,
        ?string $timestamp = null,
        ?string $leaseToken = null
    ): array {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-DevForge-Request-Id' => $requestId ?: (string) Str::uuid(),
            'X-DevForge-Timestamp' => $timestamp ?: now()->toIso8601String(),
        ];

        if ($leaseToken !== null) {
            $headers['X-DevForge-Lease-Token'] = $leaseToken;
        }

        return $headers;
    }
}

class FakeWorkerIdentityVerifier implements WorkerIdentityVerifier
{
    public function verify(string $token, string $audience): WorkerIdentity
    {
        $email = match ($token) {
            'valid-worker' => WorkerAgentJobApiTest::WORKER_EMAIL,
            'other-worker' => WorkerAgentJobApiTest::OTHER_EMAIL,
            default => throw new WorkerAuthenticationException('Invalid test token.'),
        };

        return new WorkerIdentity(
            subject: hash('sha256', $email),
            email: $email,
            audience: $audience,
            issuedAt: now()->subMinute()->timestamp,
            expiresAt: now()->addHour()->timestamp
        );
    }
}
