<?php

namespace App\Services;

use App\Models\CustomerAttentionItem;
use App\Models\ForgeApprovalEvent;
use App\Models\ForgeEstimateVersion;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EstimateApprovalService
{
    public function __construct(
        protected JiraProvider $jiraProvider
    ) {}

    /**
     * Submit a new estimate version for customer approval.
     */
    public function submitEstimate(
        PmWorkItem $workItem,
        int $estimatedSeconds,
        ?string $poNotes = null,
        ?User $submitter = null,
        ?float $costImpactAmount = null
    ): ForgeEstimateVersion {
        return DB::transaction(function () use ($workItem, $estimatedSeconds, $poNotes, $submitter, $costImpactAmount) {
            $nextVersionNumber = ($workItem->estimateVersions()->max('version') ?? 0) + 1;

            $version = ForgeEstimateVersion::create([
                'pm_work_item_id'                  => $workItem->id,
                'version'                          => $nextVersionNumber,
                'estimated_seconds'                => $estimatedSeconds,
                'external_estimate_at_submission' => $workItem->estimated_seconds,
                'submitted_by_user_id'             => $submitter?->id,
                'submitted_at'                     => now(),
                'po_notes'                         => $poNotes,
                'cost_impact_amount'               => $costImpactAmount,
            ]);

            ForgeApprovalEvent::create([
                'estimate_version_id' => $version->id,
                'event_type'          => 'submitted',
                'actor_user_id'       => $submitter?->id,
                'notes'               => $poNotes,
            ]);

            // Create Attention Item for Customer
            CustomerAttentionItem::create([
                'client_id'   => $workItem->client_id,
                'category'    => 'estimate_approval',
                'title'       => "Estimate Approval Required: {$workItem->external_item_key}",
                'description' => "Proposed estimate v{$nextVersionNumber}: " . round($estimatedSeconds / 3600, 1) . ' hrs for ' . $workItem->summary,
                'severity'    => 'urgent',
                'source_type' => 'jira',
                'source_id'   => (string) $version->id,
            ]);

            return $version;
        });
    }

    /**
     * Approve an estimate version as a Customer Admin / Client User.
     */
    public function approveEstimate(ForgeEstimateVersion $version, User $approver, ?string $notes = null): ForgeApprovalEvent
    {
        return DB::transaction(function () use ($version, $approver, $notes) {
            $event = ForgeApprovalEvent::create([
                'estimate_version_id' => $version->id,
                'event_type'          => 'approved',
                'actor_user_id'       => $approver->id,
                'notes'               => $notes ?? 'Estimate approved by customer.',
            ]);

            // Resolve corresponding Customer Attention Item
            CustomerAttentionItem::where('client_id', $version->workItem->client_id)
                ->where('source_type', 'jira')
                ->where('source_id', (string) $version->id)
                ->update([
                    'is_resolved' => true,
                    'resolved_at' => now(),
                ]);

            // Remove approval-needed label from Jira
            try {
                $this->jiraProvider->removeLabel($version->workItem, 'approval-needed', $approver);
                $this->jiraProvider->removeLabel($version->workItem, 'approval_needed', $approver);
            } catch (\Exception $e) {
                Log::info("EstimateApprovalService: Failed to remove approval-needed label from Jira: " . $e->getMessage());
            }

            // 1. Try Jira status transition
            try {
                try {
                    $this->jiraProvider->transitionWorkItem($version->workItem, 'approve_estimate', $approver);
                } catch (\Exception $e) {
                    $this->jiraProvider->transitionWorkItem($version->workItem, 'approve_estimate', null);
                }
            } catch (\Exception $e) {
                Log::info("EstimateApprovalService: Jira transition skipped: " . $e->getMessage());
            }

            // 2. Add detailed Jira comment with assignee tag, date/time, and feedback
            try {
                $assigneeTag = $version->workItem->assignee_name ? "@{$version->workItem->assignee_name} " : '';
                $approvedAt = now()->format('F d, Y \a\t H:i:s T');
                $feedbackText = ! empty($notes) ? "\n\nCustomer Feedback / Notes:\n> {$notes}" : '';

                $commentBody = "{$assigneeTag}✅ Estimate v{$version->version} ({$version->estimated_hours} hrs) has been APPROVED.\n\n"
                    . "• Approved By: {$approver->name} ({$approver->email})\n"
                    . "• Date & Time: {$approvedAt}"
                    . $feedbackText;

                try {
                    $this->jiraProvider->addComment($version->workItem, $commentBody, $approver);
                } catch (\Exception $e) {
                    $this->jiraProvider->addComment($version->workItem, $commentBody, null);
                }
            } catch (\Exception $e) {
                Log::error("EstimateApprovalService: Failed to post approval comment to Jira: " . $e->getMessage());
            }

            return $event;
        });
    }

    /**
     * Request an estimate revision with required customer feedback.
     */
    public function requestRevision(ForgeEstimateVersion $version, User $user, string $notes): ForgeApprovalEvent
    {
        return DB::transaction(function () use ($version, $user, $notes) {
            $event = ForgeApprovalEvent::create([
                'estimate_version_id' => $version->id,
                'event_type'          => 'revision_requested',
                'actor_user_id'       => $user->id,
                'notes'               => $notes,
            ]);

            // Resolve customer approval item and create item for PO attention
            CustomerAttentionItem::where('client_id', $version->workItem->client_id)
                ->where('source_type', 'jira')
                ->where('source_id', (string) $version->id)
                ->update([
                    'is_resolved' => true,
                    'resolved_at' => now(),
                ]);

            try {
                try {
                    $this->jiraProvider->transitionWorkItem($version->workItem, 'request_estimate_revision', $user);
                } catch (\Exception $e) {
                    $this->jiraProvider->transitionWorkItem($version->workItem, 'request_estimate_revision', null);
                }
            } catch (\Exception $e) {
                Log::info("EstimateApprovalService: Jira revision transition skipped: " . $e->getMessage());
            }

            try {
                $assigneeTag = $version->workItem->assignee_name ? "@{$version->workItem->assignee_name} " : '';
                $requestedAt = now()->format('F d, Y \a\t H:i:s T');

                $commentBody = "{$assigneeTag}⚠️ Estimate Revision Requested for v{$version->version} ({$version->estimated_hours} hrs).\n\n"
                    . "• Requested By: {$user->name} ({$user->email})\n"
                    . "• Date & Time: {$requestedAt}\n\n"
                    . "Customer Revision Feedback:\n> {$notes}";

                try {
                    $this->jiraProvider->addComment($version->workItem, $commentBody, $user);
                } catch (\Exception $e) {
                    $this->jiraProvider->addComment($version->workItem, $commentBody, null);
                }
            } catch (\Exception $e) {
                Log::error("EstimateApprovalService: Failed to post revision comment to Jira: " . $e->getMessage());
            }

            return $event;
        });
    }

    /**
     * Check if an external estimate modification requires reapproval.
     */
    public function checkEstimateReapprovalNeeded(PmWorkItem $workItem, int $newOriginalEstimateSeconds): ?ForgeEstimateVersion
    {
        $latestVersion = $workItem->latestEstimateVersion;
        if (! $latestVersion) {
            return null;
        }

        $latestEvent = $latestVersion->latestEvent;

        // If the latest version was approved, but the estimate in Jira was changed:
        if ($latestEvent && $latestEvent->event_type === 'approved' && $latestVersion->estimated_seconds !== $newOriginalEstimateSeconds) {
            return DB::transaction(function () use ($workItem, $latestVersion, $newOriginalEstimateSeconds) {
                // Record reapproval_required event on previous version
                ForgeApprovalEvent::create([
                    'estimate_version_id' => $latestVersion->id,
                    'event_type'          => 'reapproval_required',
                    'notes'               => 'Jira estimate changed from ' . $latestVersion->estimated_hours . 'h to ' . round($newOriginalEstimateSeconds / 3600, 1) . 'h.',
                ]);

                // Create next version
                $nextVersionNumber = $latestVersion->version + 1;
                $newVersion = ForgeEstimateVersion::create([
                    'pm_work_item_id'                  => $workItem->id,
                    'version'                          => $nextVersionNumber,
                    'estimated_seconds'                => $newOriginalEstimateSeconds,
                    'external_estimate_at_submission' => $newOriginalEstimateSeconds,
                    'submitted_by_user_id'             => null,
                    'submitted_at'                     => now(),
                    'po_notes'                         => 'Automatic reapproval triggered by Jira estimate update.',
                ]);

                ForgeApprovalEvent::create([
                    'estimate_version_id' => $newVersion->id,
                    'event_type'          => 'submitted',
                    'notes'               => 'Reapproval required due to Jira estimate change.',
                ]);

                // Raise urgent Customer Attention Item
                CustomerAttentionItem::create([
                    'client_id'   => $workItem->client_id,
                    'category'    => 'estimate_reapproval',
                    'title'       => "Reapproval Required: {$workItem->external_item_key}",
                    'description' => "Jira estimate updated to " . round($newOriginalEstimateSeconds / 3600, 1) . " hrs (Previously approved: {$latestVersion->estimated_hours} hrs).",
                    'severity'    => 'urgent',
                    'source_type' => 'jira',
                    'source_id'   => (string) $newVersion->id,
                ]);

                return $newVersion;
            });
        }

        return null;
    }

    /**
     * Check if an initial estimate in Jira needs customer approval in Forge.
     */
    public function checkInitialEstimateApprovalNeeded(PmWorkItem $workItem): ?ForgeEstimateVersion
    {
        $hasApprovalLabel = $workItem->hasLabel('approval-needed') || $workItem->hasLabel('approval_needed');

        if (! $hasApprovalLabel) {
            return null;
        }

        if ($workItem->estimateVersions()->count() === 0) {
            return $this->submitEstimate(
                $workItem,
                $workItem->estimated_seconds > 0 ? $workItem->estimated_seconds : 0,
                'Estimate approval required (Label: approval-needed).'
            );
        }

        return null;
    }
}
