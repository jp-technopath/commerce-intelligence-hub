<?php

namespace Tests\Unit;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use App\Models\FindingStatusTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingStatusTransitionImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function createFinding(): Finding
    {
        $client = Client::create([
            'name'   => 'Test Client',
            'status' => 'active',
        ]);

        return Finding::create([
            'client_id'              => $client->id,
            'title'                  => 'Test Finding',
            'finding_type'           => 'opportunity',
            'finding_category'       => \App\Enums\FindingCategory::Customer,
            'severity'               => 'medium',
            'execution_status'       => FindingExecutionStatus::New,
            'description'            => 'Test description',
            'first_detected_at'      => now(),
            'incident_fingerprint'   => 'fp-' . uniqid(),
            'opportunity_score'      => 80,
            'estimated_effort_hours' => 5,
        ]);
    }

    private function createTransition(Finding $finding, array $overrides = []): FindingStatusTransition
    {
        return FindingStatusTransition::create(array_merge([
            'finding_id'            => $finding->id,
            'from_status'           => FindingStatus::New,
            'to_status'             => FindingStatus::UnderReview,
            'from_execution_status' => FindingExecutionStatus::New,
            'to_execution_status'   => FindingExecutionStatus::UnderReview,
            'from_outcome_status'   => null,
            'to_outcome_status'     => FindingOutcomeStatus::PendingExecution,
            'notes'                 => 'Initial test transition',
            'transition_source'     => 'test',
            'created_at'            => now(),
        ], $overrides));
    }

    public function test_finding_status_transitions_cannot_be_updated_in_place(): void
    {
        $finding = $this->createFinding();
        $transition = $this->createTransition($finding);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FindingStatusTransition audit records are immutable and cannot be modified.');

        $transition->update([
            'notes' => 'Attempting to rewrite historical audit notes',
        ]);
    }

    public function test_finding_status_transitions_cannot_be_deleted(): void
    {
        $finding = $this->createFinding();
        $transition = $this->createTransition($finding);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FindingStatusTransition audit records are immutable and cannot be deleted.');

        $transition->delete();
    }

    public function test_corrections_must_append_new_explanatory_audit_records(): void
    {
        $finding = $this->createFinding();

        $initial = $this->createTransition($finding, [
            'notes'      => 'Original pilot event',
            'created_at' => now()->subHour(),
        ]);

        // Explanatory correction appended instead of rewriting
        $correction = $this->createTransition($finding, [
            'from_status'           => FindingStatus::UnderReview,
            'to_status'             => FindingStatus::UnderReview,
            'from_execution_status' => FindingExecutionStatus::UnderReview,
            'to_execution_status'   => FindingExecutionStatus::UnderReview,
            'notes'                 => 'Correction notice: Note for previous event should clarify simulated pilot test context.',
            'transition_source'     => 'audit_correction',
            'created_at'            => now(),
        ]);

        $allTransitions = FindingStatusTransition::where('finding_id', $finding->id)->orderBy('id')->get();

        $this->assertCount(2, $allTransitions);
        $this->assertEquals('Original pilot event', $allTransitions[0]->notes);
        $this->assertEquals('audit_correction', $allTransitions[1]->transition_source);
        $this->assertEquals('Correction notice: Note for previous event should clarify simulated pilot test context.', $allTransitions[1]->notes);
    }
}
