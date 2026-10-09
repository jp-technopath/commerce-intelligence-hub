<?php

namespace App\Services\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\ClientMeeting;
use App\Models\Finding;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MeetingIntelligenceService
{
    /**
     * Stage 1: needed
     * Stage 2: draft_generated
     * Stage 3: reviewed_ready
     * Stage 4: completed
     */
    public function getMeetingPrepStage(ClientMeeting $meeting): string
    {
        $explicitStage = $meeting->metadata['prep_stage'] ?? null;
        if ($explicitStage && in_array($explicitStage, ['needed', 'draft_generated', 'reviewed_ready', 'completed'], true)) {
            return $explicitStage;
        }

        if ($meeting->meeting_start_at && $meeting->meeting_start_at->isPast()) {
            return 'completed';
        }

        $prep = $meeting->prep;
        if (! $prep) {
            return 'needed';
        }

        if ($prep->email_sent_at || ! empty($prep->metadata['is_reviewed'])) {
            return 'reviewed_ready';
        }

        if (! empty($prep->prep_content) || ! empty($prep->executive_briefing)) {
            return 'draft_generated';
        }

        return 'needed';
    }

    public function determinePrepStage(ClientMeeting $meeting): string
    {
        return $this->getMeetingPrepStage($meeting);
    }

    /**
     * Follow-up stage.
     */
    public function getMeetingFollowUpStage(ClientMeeting $meeting): string
    {
        if ($meeting->meeting_start_at && $meeting->meeting_start_at->isFuture()) {
            return 'pending_meeting';
        }

        $followUp = $meeting->followUp;
        if (! $followUp) {
            return 'needed';
        }

        if ($followUp->email_sent_at || ! empty($followUp->metadata['is_completed'])) {
            return 'completed';
        }

        if (! empty($followUp->metadata['is_reviewed'])) {
            return 'reviewed_ready';
        }

        if (! empty($followUp->follow_up_content) || ! empty($followUp->executive_summary)) {
            return 'draft_generated';
        }

        return 'needed';
    }

    /**
     * Get upcoming meetings owned by user or relevant to user's assigned clients.
     */
    public function getUserUpcomingMeetings(User $user, int $days = 3): Collection
    {
        $now = now();
        $cutoff = $now->copy()->addDays($days);

        $meetings = ClientMeeting::with(['client', 'prep', 'followUp'])
            ->whereBetween('meeting_start_at', [$now, $cutoff])
            ->where(function ($q) use ($user) {
                $q->where('internal_owner_id', $user->id)
                  ->orWhereJsonContains('internal_attendees', $user->email);
            })
            ->orderBy('meeting_start_at', 'asc')
            ->get();

        return $meetings->map(function ($m) {
            $m->prep_stage = $this->getMeetingPrepStage($m);
            return $m;
        });
    }

    /**
     * Get upcoming and recent meetings for manager dashboard.
     */
    public function getManagerMeetingsOverview(array $clientIds, int $daysAhead = 4, int $daysBehind = 3): array
    {
        $now = now();
        $from = $now->copy()->subDays($daysBehind);
        $to = $now->copy()->addDays($daysAhead);

        $meetings = ClientMeeting::with(['client', 'owner', 'prep', 'followUp'])
            ->whereIn('client_id', $clientIds)
            ->whereBetween('meeting_start_at', [$from, $to])
            ->orderBy('meeting_start_at', 'asc')
            ->get();

        $upcoming = [];
        $recent = [];

        foreach ($meetings as $meeting) {
            $isPast = $meeting->meeting_start_at->isPast();
            $prepStage = $this->getMeetingPrepStage($meeting);
            $followUpStage = $this->getMeetingFollowUpStage($meeting);

            $data = [
                'id'               => $meeting->id,
                'title'            => $meeting->title,
                'client_name'      => $meeting->client?->name ?? 'Unmapped Client',
                'owner_name'       => $meeting->owner?->name ?? 'Unassigned',
                'is_unassigned'    => $meeting->internal_owner_id === null,
                'meeting_start_at' => $meeting->meeting_start_at,
                'prep_stage'       => $prepStage,
                'follow_up_stage'  => $followUpStage,
            ];

            if ($isPast) {
                $recent[] = $data;
            } else {
                $upcoming[] = $data;
            }
        }

        return [
            'upcoming' => $upcoming,
            'recent'   => $recent,
        ];
    }

    /**
     * Evaluate and sync meeting findings into the Action Center (Finding model).
     */
    public function syncMeetingFindings(array $clientIds): int
    {
        $now = now();
        $meetings = ClientMeeting::with(['client', 'prep', 'followUp'])
            ->whereIn('client_id', $clientIds)
            ->whereBetween('meeting_start_at', [$now->copy()->subDays(2), $now->copy()->addDays(2)])
            ->get();

        $syncedCount = 0;

        foreach ($meetings as $m) {
            $isUpcoming = $m->meeting_start_at->isFuture();

            if ($isUpcoming) {
                $prepStage = $this->getMeetingPrepStage($m);

                if (in_array($prepStage, ['needed', 'draft_generated'], true)) {
                    $fingerprint = hash('sha256', "meeting_prep_{$m->client_id}_{$m->id}");
                    $isUnassigned = $m->internal_owner_id === null;

                    $title = $isUnassigned
                        ? "Unassigned Meeting Prep: {$m->title} requires an owner"
                        : ($prepStage === 'needed' ? "Meeting prep needed: {$m->title}" : "Meeting prep review needed: {$m->title}");

                    $hoursUntil = round($now->diffInHours($m->meeting_start_at), 1);
                    $severity = $hoursUntil <= 12 ? FindingSeverity::High : FindingSeverity::Medium;

                    Finding::updateOrCreate(
                        [
                            'fingerprint' => $fingerprint,
                        ],
                        [
                            'client_id'                  => $m->client_id,
                            'responsible_user_id'        => $m->internal_owner_id,
                            'source_type'                => 'client_meeting',
                            'source_id'                  => (string) $m->id,
                            'finding_type'               => $isUnassigned ? 'unassigned_meeting_owner' : 'meeting_prep_' . $prepStage,
                            'finding_category'           => FindingCategory::MeetingPreparation,
                            'title'                      => $title,
                            'description'                => "Meeting starts {$m->meeting_start_at->diffForHumans()}. Current preparation stage: {$prepStage}.",
                            'severity'                   => $severity,
                            'status'                     => FindingStatus::New,
                            'visibility_classification'  => 'internal',
                            'is_customer_visible'        => false,
                            'evidence_json'              => [
                                'meeting_id'  => $m->id,
                                'prep_stage'  => $prepStage,
                                'hours_until' => $hoursUntil,
                                'start_at'    => $m->meeting_start_at->toIso8601String(),
                            ],
                            'detected_at'                => now(),
                        ]
                    );
                    $syncedCount++;
                }
            } else {
                // Completed meeting follow-up
                $followUpStage = $this->getMeetingFollowUpStage($m);
                if (in_array($followUpStage, ['needed', 'draft_generated'], true)) {
                    $fingerprint = hash('sha256', "meeting_followup_{$m->client_id}_{$m->id}");

                    Finding::updateOrCreate(
                        [
                            'fingerprint' => $fingerprint,
                        ],
                        [
                            'client_id'                  => $m->client_id,
                            'responsible_user_id'        => $m->internal_owner_id,
                            'source_type'                => 'client_meeting',
                            'source_id'                  => (string) $m->id,
                            'finding_type'               => 'meeting_followup_' . $followUpStage,
                            'finding_category'           => FindingCategory::MeetingFollowUp,
                            'title'                      => "Meeting follow-up needed: {$m->title}",
                            'description'                => "Meeting concluded {$m->meeting_start_at->diffForHumans()}. Action items and summary pending.",
                            'severity'                   => FindingSeverity::Medium,
                            'status'                     => FindingStatus::New,
                            'visibility_classification'  => 'internal',
                            'is_customer_visible'        => false,
                            'evidence_json'              => [
                                'meeting_id'      => $m->id,
                                'follow_up_stage' => $followUpStage,
                            ],
                            'detected_at'                => now(),
                        ]
                    );
                    $syncedCount++;
                }
            }
        }

        return $syncedCount;
    }
}
