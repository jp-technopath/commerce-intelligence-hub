<?php

namespace App\Filament\Widgets\Manager;

use App\Models\ClientMeeting;
use App\Models\User;
use App\Services\Intelligence\MeetingIntelligenceService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class ManagerMeetingIntelligenceWidget extends Widget
{
    protected static string $view = 'filament.widgets.manager.manager-meeting-intelligence-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    public function getMeetingsData(): array
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $clientIds = $user ? $user->getAssignedClientIds() : [];

        /** @var MeetingIntelligenceService $service */
        $service = app(MeetingIntelligenceService::class);

        $query = ClientMeeting::with(['client', 'internalOwner', 'prep', 'followUp'])
            ->where('meeting_start_at', '>=', now()->subDays(1))
            ->where('meeting_start_at', '<=', now()->addDays(7))
            ->orderBy('meeting_start_at', 'asc');

        if (! empty($clientIds) && $clientIds !== ['*']) {
            $query->whereIn('client_id', $clientIds);
        }

        $meetings = $query->get()->map(function ($m) use ($service) {
            $m->computed_prep_stage = $service->getMeetingPrepStage($m);
            $m->computed_followup_stage = $service->getMeetingFollowUpStage($m);
            return $m;
        });

        $unassignedCount = $meetings->filter(fn ($m) => ! $m->internal_owner_id)->count();

        return [
            'meetings'         => $meetings,
            'unassigned_count' => $unassignedCount,
        ];
    }

    public function syncMeetingIntelligence(): void
    {
        /** @var MeetingIntelligenceService $service */
        $service = app(MeetingIntelligenceService::class);
        $count = $service->syncMeetingFindings();

        Notification::make()
            ->title('Meeting Intelligence Synced')
            ->body("Meeting readiness evaluated across portfolio ({$count} action items verified).")
            ->success()
            ->send();
    }

    public function assignOwner(int $meetingId, int $userId): void
    {
        $meeting = ClientMeeting::find($meetingId);
        if ($meeting) {
            $meeting->update(['internal_owner_id' => $userId]);

            Notification::make()
                ->title('Owner Assigned')
                ->body("Assigned meeting to internal owner.")
                ->success()
                ->send();
        }
    }
}
