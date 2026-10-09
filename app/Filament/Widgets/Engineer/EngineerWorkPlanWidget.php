<?php

namespace App\Filament\Widgets\Engineer;

use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Services\Intelligence\WorkPrioritizationEngine;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class EngineerWorkPlanWidget extends Widget
{
    protected static string $view = 'filament.widgets.engineer.engineer-work-plan-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public ?int $userId = null;

    public string $activeTab = 'plan';

    protected $listeners = [
        'engineer-user-changed' => 'handleEngineerUserChanged',
    ];

    public function handleEngineerUserChanged(?int $userId = null): void
    {
        $this->userId = $userId;
    }

    public function getTargetUser(): ?\App\Models\User
    {
        $id = $this->userId ?? session('engineer_dashboard_user_id') ?? Auth::id();

        if (! $id) {
            return Auth::user();
        }

        return \App\Models\User::find($id) ?? Auth::user();
    }

    public function getPlanData(): array
    {
        /** @var \App\Models\User|null $user */
        $user = $this->getTargetUser();
        if (! $user) {
            return [];
        }

        /** @var WorkPrioritizationEngine $engine */
        $engine = app(WorkPrioritizationEngine::class);

        return $engine->getPrioritizedPlan($user);
    }

    public function refreshWorkPlan(): void
    {
        /** @var \App\Models\User|null $user */
        $user = $this->getTargetUser();
        if (! $user) {
            return;
        }

        /** @var WorkPrioritizationEngine $engine */
        $engine = app(WorkPrioritizationEngine::class);
        $engine->getPrioritizedPlan($user, true);

        Notification::make()
            ->title('AI Work Plan Refreshed')
            ->body('Priorities recalculated against latest task signals, deadlines, and blocker statuses.')
            ->success()
            ->send();
    }

    public function markInProgress(int $itemId): void
    {
        $item = PmWorkItem::find($itemId);
        if (! $item) {
            return;
        }

        $item->update([
            'normalized_delivery_status' => 'in_progress',
            'external_status'            => 'In Progress',
        ]);

        Notification::make()
            ->title('Task Started')
            ->body("[{$item->external_item_key}] is now In Progress.")
            ->success()
            ->send();
    }

    public function clearBlocker(int $itemId): void
    {
        $item = PmWorkItem::find($itemId);
        if (! $item) {
            return;
        }

        $item->update([
            'is_blocked'                 => false,
            'blocked_reason'             => null,
            'normalized_delivery_status' => 'in_progress',
        ]);

        Notification::make()
            ->title('Blocker Cleared')
            ->body("[{$item->external_item_key}] has been unblocked and moved to executable plan.")
            ->success()
            ->send();
    }
}
