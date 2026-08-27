<?php

namespace Tests\Unit;

use App\Enums\DevelopmentRequestStatus;
use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\DevelopmentRequestLifecycleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class DevelopmentRequestLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private DevelopmentRequestLifecycleService $service;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DevelopmentRequestLifecycleService;
        $this->project = $this->createProject();
        $this->owner = User::factory()->create(['is_admin' => true]);
    }

    public static function allowedTransitionsProvider(): array
    {
        return array_map(
            fn (array $transition): array => [$transition[0], $transition[1]],
            self::allowedTransitions()
        );
    }

    public static function rejectedTransitionsProvider(): array
    {
        $allowed = array_map(
            fn (array $transition): string => implode('>', $transition),
            self::allowedTransitions()
        );
        $rejected = [];

        foreach (self::stateValues() as $fromState) {
            foreach (self::stateValues() as $toState) {
                if (! in_array("{$fromState}>{$toState}", $allowed, true)) {
                    $rejected["{$fromState} to {$toState}"] = [$fromState, $toState];
                }
            }
        }

        return $rejected;
    }

    #[DataProvider('allowedTransitionsProvider')]
    #[Test]
    public function every_approved_transition_succeeds(string $fromState, string $toState): void
    {
        $request = $this->createRequest($fromState);

        $history = $this->service->transitionState(
            $request,
            $toState,
            $this->owner,
            "{$fromState} to {$toState}"
        );

        $this->assertSame($fromState, $history->old_state);
        $this->assertSame($toState, $history->new_state);
        $this->assertSame($toState, $request->fresh()->state->value);
    }

    #[DataProvider('rejectedTransitionsProvider')]
    #[Test]
    public function every_unapproved_transition_is_rejected_without_changing_state(
        string $fromState,
        string $toState
    ): void {
        $request = $this->createRequest($fromState);

        try {
            $this->service->transitionState($request, $toState, $this->owner);
            $this->fail("Expected {$fromState} to {$toState} to be rejected.");
        } catch (RuntimeException) {
            $this->assertSame($fromState, $request->fresh()->state->value);
            $this->assertDatabaseCount('development_request_status_histories', 0);
        }
    }

    #[Test]
    public function enum_contains_all_thirteen_approved_states(): void
    {
        $this->assertSame(self::stateValues(), DevelopmentRequestStatus::allValues());
    }

    #[Test]
    public function terminal_states_are_immutable(): void
    {
        foreach (DevelopmentRequestStatus::terminalValues() as $terminalState) {
            $request = $this->createRequest($terminalState);

            try {
                $this->service->transitionState($request, 'queued', $this->owner);
                $this->fail("Expected {$terminalState} to be immutable.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('terminal state', $exception->getMessage());
                $this->assertSame($terminalState, $request->fresh()->state->value);
            }
        }
    }

    #[Test]
    public function transition_records_audit_context_and_updates_current_reason_and_run(): void
    {
        $request = $this->createRequest('queued');

        $history = $this->service->transitionState(
            $request,
            DevelopmentRequestStatus::StartingVm,
            $this->owner,
            'Provisioning requested',
            'transition-1',
            'run-42',
            'user',
            'Request owner',
            ['region' => 'us-central1']
        );

        $this->assertSame($this->owner->id, $history->actor_user_id);
        $this->assertSame('user', $history->actor_type);
        $this->assertSame('Request owner', $history->actor_label);
        $this->assertSame('Provisioning requested', $history->reason);
        $this->assertSame('run-42', $history->correlation_identifier);
        $this->assertSame(['region' => 'us-central1'], $history->metadata);
        $this->assertSame('Provisioning requested', $request->fresh()->status_reason);
        $this->assertSame('run-42', $request->fresh()->active_run_correlation_id);
    }

    #[Test]
    public function system_transition_requires_a_supported_type_and_label(): void
    {
        $request = $this->createRequest('starting_vm');

        $this->expectException(AuthorizationException::class);

        $this->service->transitionState($request, 'waiting_for_worker', actorType: 'system');
    }

    #[Test]
    public function system_transition_records_auditable_identity(): void
    {
        $request = $this->createRequest('starting_vm');

        $history = $this->service->transitionState(
            $request,
            'waiting_for_worker',
            actorType: 'system',
            actorLabel: 'VM orchestrator'
        );

        $this->assertNull($history->actor_user_id);
        $this->assertSame('system', $history->actor_type);
        $this->assertSame('VM orchestrator', $history->actor_label);
    }

    #[Test]
    public function human_approval_decisions_cannot_be_spoofed_by_a_system_actor(): void
    {
        $request = $this->createRequest('awaiting_approval');

        $this->expectException(AuthorizationException::class);

        $this->service->transitionState(
            $request,
            'approved',
            actorType: 'system',
            actorLabel: 'Orchestrator'
        );
    }

    #[Test]
    public function state_is_reconstructed_from_append_only_history(): void
    {
        $request = $this->createRequest('queued');
        $this->service->transitionState($request, 'starting_vm', $this->owner);
        $this->service->transitionState(
            $request,
            'waiting_for_worker',
            actorType: 'system',
            actorLabel: 'VM orchestrator'
        );

        $this->assertSame('waiting_for_worker', $this->service->reconstructStateFromHistory($request));
        $this->assertSame('draft', $this->service->reconstructStateFromHistory($this->createRequest()));
    }

    #[Test]
    public function status_history_rejects_updates_and_deletes(): void
    {
        $request = $this->createRequest('queued');
        $history = $this->service->transitionState($request, 'starting_vm', $this->owner);

        try {
            $history->update(['reason' => 'tampered']);
            $this->fail('Expected history update to be rejected.');
        } catch (LogicException) {
            $this->assertNull($history->fresh()->reason);
        }

        $this->expectException(LogicException::class);
        $history->delete();
    }

    #[Test]
    public function approval_requires_scoped_approve_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $productOwner = $this->createScopedUser(Role::ROLE_PRODUCT_OWNER);
        $engineer = $this->createScopedUser(Role::ROLE_ENGINEER);

        $approved = $this->service->transitionState(
            $this->createRequest('awaiting_approval', $productOwner),
            'approved',
            $productOwner
        );

        $this->assertSame('approved', $approved->new_state);

        $this->expectException(AuthorizationException::class);
        $this->service->transitionState(
            $this->createRequest('awaiting_approval', $engineer),
            'approved',
            $engineer
        );
    }

    #[Test]
    public function approval_permission_is_limited_to_the_assigned_scope(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $otherProject = $this->createProject();
        $productOwner = $this->createScopedUser(Role::ROLE_PRODUCT_OWNER, $otherProject);

        $this->expectException(AuthorizationException::class);
        $this->service->transitionState(
            $this->createRequest('awaiting_approval', $productOwner),
            'approved',
            $productOwner
        );
    }

    #[Test]
    public function human_cancellation_requires_scoped_cancel_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $engineer = $this->createScopedUser(Role::ROLE_ENGINEER);

        $history = $this->service->transitionState(
            $this->createRequest('queued', $engineer),
            'cancelling',
            $engineer
        );

        $this->assertSame('cancelling', $history->new_state);

        $unprivilegedUser = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        $this->service->transitionState(
            $this->createRequest('queued', $unprivilegedUser),
            'cancelling',
            $unprivilegedUser
        );
    }

    #[Test]
    public function system_can_complete_an_authorized_cancellation(): void
    {
        $request = $this->createRequest('cancelling');

        $history = $this->service->transitionState(
            $request,
            'cancelled',
            actorType: 'system',
            actorLabel: 'VM orchestrator'
        );

        $this->assertSame('cancelled', $history->new_state);
    }

    #[Test]
    public function only_an_authorized_owner_can_submit_or_resubmit(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $owner = $this->createScopedUser(Role::ROLE_ENGINEER);
        $otherEngineer = $this->createScopedUser(Role::ROLE_ENGINEER);

        $history = $this->service->transitionState(
            $this->createRequest('draft', $owner),
            'queued',
            $owner
        );

        $this->assertSame('queued', $history->new_state);

        $this->expectException(AuthorizationException::class);
        $this->service->transitionState(
            $this->createRequest('changes_requested', $owner),
            'queued',
            $otherEngineer
        );
    }

    private function createRequest(
        string $state = 'draft',
        ?User $owner = null,
        ?Project $project = null
    ): DevelopmentRequest {
        $project ??= $this->project;
        $owner ??= $this->owner;

        return DevelopmentRequest::query()->create([
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'owner_user_id' => $owner->id,
            'request_type' => 'investigation',
            'state' => $state,
            'title' => 'Lifecycle test request',
            'environment_key' => 'test',
            'source_type' => 'manual',
            'correlation_identifier' => fake()->uuid(),
        ]);
    }

    private function createProject(): Project
    {
        $client = Client::query()->create([
            'name' => fake()->unique()->company(),
            'status' => 'active',
        ]);

        return Project::query()->create([
            'client_id' => $client->id,
            'name' => fake()->unique()->words(3, true),
            'status' => 'active',
        ]);
    }

    private function createScopedUser(string $roleName, ?Project $project = null): User
    {
        $project ??= $this->project;
        $user = User::factory()->create();
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'is_active' => true,
        ]);

        return $user;
    }

    private static function allowedTransitions(): array
    {
        return [
            ['draft', 'queued'],
            ['draft', 'cancelled'],
            ['queued', 'starting_vm'],
            ['queued', 'cancelling'],
            ['starting_vm', 'waiting_for_worker'],
            ['starting_vm', 'failed'],
            ['starting_vm', 'cancelling'],
            ['waiting_for_worker', 'running'],
            ['waiting_for_worker', 'failed'],
            ['waiting_for_worker', 'cancelling'],
            ['running', 'awaiting_approval'],
            ['running', 'failed'],
            ['running', 'cancelling'],
            ['awaiting_approval', 'approved'],
            ['awaiting_approval', 'changes_requested'],
            ['awaiting_approval', 'rejected'],
            ['approved', 'completed'],
            ['approved', 'changes_requested'],
            ['changes_requested', 'queued'],
            ['changes_requested', 'cancelled'],
            ['cancelling', 'cancelled'],
            ['cancelling', 'failed'],
        ];
    }

    private static function stateValues(): array
    {
        return [
            'draft',
            'queued',
            'starting_vm',
            'waiting_for_worker',
            'running',
            'awaiting_approval',
            'approved',
            'changes_requested',
            'rejected',
            'cancelling',
            'cancelled',
            'failed',
            'completed',
        ];
    }
}
