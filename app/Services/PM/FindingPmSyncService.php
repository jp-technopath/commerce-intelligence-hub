<?php

namespace App\Services\PM;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Models\Finding;
use App\Models\PmWorkItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

class FindingPmSyncService
{
    /**
     * Synchronize a PM work item's status to its linked Finding.
     * Prevents loops and does not automatically resolve commercial outcomes.
     */
    public function syncWorkItemToFinding(PmWorkItem $workItem, string $source = 'pm_sync'): ?Finding
    {
        $finding = Finding::where('pm_work_item_id', $workItem->id)->first();

        if (! $finding) {
            return null;
        }

        // Verify client consistency
        if ((int) $finding->client_id !== (int) $workItem->client_id) {
            Log::warning("FindingPmSyncService: Client mismatch between Finding [{$finding->id}] ({$finding->client_id}) and PmWorkItem [{$workItem->id}] ({$workItem->client_id})");
            return $finding;
        }

        $pmStatus = strtolower(trim((string) $workItem->status));
        $pmCategory = strtolower(trim((string) $workItem->status_category));

        $isDone = in_array($pmCategory, ['done', 'complete', 'closed', 'resolved'], true)
            || in_array($pmStatus, ['done', 'completed', 'closed', 'resolved'], true);

        $isInProgress = in_array($pmCategory, ['in progress', 'indeterminate'], true)
            || in_array($pmStatus, ['in progress', 'in review', 'in development'], true);

        if ($isDone) {
            if ($finding->execution_status !== FindingExecutionStatus::ActionCompleted
                && $finding->execution_status !== FindingExecutionStatus::Closed) {
                Log::info("FindingPmSyncService: PM item {$workItem->external_item_key} marked done. Updating Finding #{$finding->id} to action_completed.");
                $finding->transitionExecutionState(
                    FindingExecutionStatus::ActionCompleted,
                    $source,
                    null,
                    "Action completed via PM work item {$workItem->external_item_key} ({$workItem->status})."
                );
            }
        } elseif ($isInProgress) {
            if (in_array($finding->execution_status, [
                FindingExecutionStatus::New,
                FindingExecutionStatus::UnderReview,
                FindingExecutionStatus::Approved,
                FindingExecutionStatus::Assigned,
            ], true)) {
                Log::info("FindingPmSyncService: PM item {$workItem->external_item_key} in progress. Updating Finding #{$finding->id} to work_in_progress.");
                $finding->transitionExecutionState(
                    FindingExecutionStatus::WorkInProgress,
                    $source,
                    null,
                    "Work in progress in PM item {$workItem->external_item_key}."
                );
            }
        }

        return $finding->fresh();
    }

    /**
     * Link an approved or active Finding to an existing PM Work Item.
     */
    public function linkFindingToWorkItem(Finding $finding, PmWorkItem $workItem, ?User $actor = null): void
    {
        if ((int) $finding->client_id !== (int) $workItem->client_id) {
            throw new AuthorizationException(
                "Cannot link Finding #{$finding->id} (Client {$finding->client_id}) to PM Work Item #{$workItem->id} (Client {$workItem->client_id}): Client mismatch."
            );
        }

        if ($actor && ! $actor->canAccessClient($finding->client_id)) {
            throw new AuthorizationException(
                "User {$actor->name} is not authorized for client {$finding->client_id}."
            );
        }

        $finding->update([
            'pm_work_item_id' => $workItem->id,
        ]);

        $this->syncWorkItemToFinding($workItem, 'pm_link');
    }
}
