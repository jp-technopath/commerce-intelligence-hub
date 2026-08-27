<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\DevelopmentRequestStatusHistory;
use App\Models\Project;
use App\Models\User;
use App\Services\DevelopmentRequestLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class DevelopmentRequestIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private DevelopmentRequestLifecycleService $service;

    private Project $project;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DevelopmentRequestLifecycleService;
        $client = Client::query()->create([
            'name' => fake()->unique()->company(),
            'status' => 'active',
        ]);
        $this->project = Project::query()->create([
            'client_id' => $client->id,
            'name' => fake()->unique()->words(3, true),
            'status' => 'active',
        ]);
        $this->actor = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function exact_retry_returns_the_original_history_without_a_duplicate(): void
    {
        $request = $this->createRequest('queued');
        $payload = [
            'request' => $request,
            'newState' => 'starting_vm',
            'actor' => $this->actor,
            'reason' => 'Start VM',
            'idempotencyKey' => 'event-1',
            'correlationIdentifier' => 'run-1',
            'actorType' => 'user',
            'actorLabel' => 'Request owner',
            'metadata' => ['zone' => 'us-central1-a', 'machine' => ['type' => 'e2-medium']],
        ];

        $first = $this->service->transitionState(...$payload);
        $retry = $this->service->transitionState(...$payload);

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('development_request_status_histories', 1);
        $this->assertSame('starting_vm', $request->fresh()->state->value);
    }

    #[Test]
    public function metadata_key_order_does_not_make_an_exact_retry_different(): void
    {
        $request = $this->createRequest('queued');

        $first = $this->service->transitionState(
            $request,
            'starting_vm',
            $this->actor,
            'Start VM',
            'event-1',
            'run-1',
            'user',
            'Request owner',
            ['a' => 1, 'nested' => ['b' => 2, 'a' => 1]]
        );

        $retry = $this->service->transitionState(
            $request,
            'starting_vm',
            $this->actor,
            'Start VM',
            'event-1',
            'run-1',
            'user',
            'Request owner',
            ['nested' => ['a' => 1, 'b' => 2], 'a' => 1]
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('development_request_status_histories', 1);
    }

    #[Test]
    public function retry_remains_safe_after_the_request_has_advanced(): void
    {
        $request = $this->createRequest('waiting_for_worker');

        $original = $this->service->transitionState(
            $request,
            'running',
            reason: 'Worker started',
            idempotencyKey: 'worker-started-1',
            correlationIdentifier: 'run-1',
            actorType: 'webhook',
            actorLabel: 'Worker callback'
        );

        $this->service->transitionState(
            $request,
            'awaiting_approval',
            reason: 'Work finished',
            idempotencyKey: 'worker-finished-1',
            correlationIdentifier: 'run-1',
            actorType: 'webhook',
            actorLabel: 'Worker callback'
        );

        $retry = $this->service->transitionState(
            $request,
            'running',
            reason: 'Worker started',
            idempotencyKey: 'worker-started-1',
            correlationIdentifier: 'run-1',
            actorType: 'webhook',
            actorLabel: 'Worker callback'
        );

        $this->assertSame($original->id, $retry->id);
        $this->assertSame('awaiting_approval', $request->fresh()->state->value);
        $this->assertDatabaseCount('development_request_status_histories', 2);
    }

    #[Test]
    public function reusing_a_key_with_a_different_payload_is_rejected_without_state_change(): void
    {
        $request = $this->createRequest('queued');

        $this->service->transitionState(
            $request,
            'starting_vm',
            $this->actor,
            'Start VM',
            'event-1'
        );

        try {
            $this->service->transitionState(
                $request,
                'starting_vm',
                $this->actor,
                'Changed reason',
                'event-1'
            );
            $this->fail('Expected idempotency-key reuse to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('different transition payload', $exception->getMessage());
            $this->assertSame('starting_vm', $request->fresh()->state->value);
            $this->assertDatabaseCount('development_request_status_histories', 1);
        }
    }

    #[Test]
    public function duplicate_worker_callbacks_are_idempotent(): void
    {
        $request = $this->createRequest('waiting_for_worker');

        $first = $this->service->transitionState(
            $request,
            'running',
            reason: 'Worker assigned',
            idempotencyKey: 'callback-123',
            correlationIdentifier: 'run-123',
            actorType: 'callback',
            actorLabel: 'Commerce Hub worker'
        );
        $second = $this->service->transitionState(
            $request,
            'running',
            reason: 'Worker assigned',
            idempotencyKey: 'callback-123',
            correlationIdentifier: 'run-123',
            actorType: 'callback',
            actorLabel: 'Commerce Hub worker'
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('development_request_status_histories', 1);
    }

    #[Test]
    public function stale_out_of_order_callback_is_rejected_without_corrupting_state(): void
    {
        $request = $this->createRequest('starting_vm');
        $this->service->transitionState(
            $request,
            'waiting_for_worker',
            idempotencyKey: 'vm-ready',
            actorType: 'callback',
            actorLabel: 'VM callback'
        );
        $this->service->transitionState(
            $request,
            'running',
            idempotencyKey: 'worker-ready',
            actorType: 'callback',
            actorLabel: 'Worker callback'
        );

        try {
            $this->service->transitionState(
                $request,
                'waiting_for_worker',
                idempotencyKey: 'stale-vm-ready',
                actorType: 'callback',
                actorLabel: 'VM callback'
            );
            $this->fail('Expected the stale callback to be rejected.');
        } catch (RuntimeException) {
            $this->assertSame('running', $request->fresh()->state->value);
            $this->assertDatabaseCount('development_request_status_histories', 2);
        }
    }

    #[Test]
    public function database_constraint_enforces_durable_idempotency(): void
    {
        $request = $this->createRequest('queued');
        $this->service->transitionState(
            $request,
            'starting_vm',
            $this->actor,
            idempotencyKey: 'db-unique-key'
        );

        $this->expectException(QueryException::class);

        DevelopmentRequestStatusHistory::query()->create([
            'development_request_id' => $request->id,
            'old_state' => 'queued',
            'new_state' => 'starting_vm',
            'actor_user_id' => $this->actor->id,
            'idempotency_key' => 'db-unique-key',
        ]);
    }

    private function createRequest(string $state): DevelopmentRequest
    {
        return DevelopmentRequest::query()->create([
            'client_id' => $this->project->client_id,
            'project_id' => $this->project->id,
            'owner_user_id' => $this->actor->id,
            'request_type' => 'investigation',
            'state' => $state,
            'title' => 'Idempotency test request',
            'environment_key' => 'test',
            'source_type' => 'callback',
            'correlation_identifier' => fake()->uuid(),
        ]);
    }
}
