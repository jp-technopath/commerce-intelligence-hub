<?php

namespace App\Filament\Widgets\Engineer;

use App\Services\Intelligence\TimeTrackingService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class EngineerMonthlyHoursWidget extends Widget
{
    protected static string $view = 'filament.widgets.engineer.engineer-monthly-hours-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public ?int $userId = null;

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

    public function getHoursData(): array
    {
        /** @var \App\Models\User|null $user */
        $user = $this->getTargetUser();
        if (! $user) {
            return [];
        }

        /** @var TimeTrackingService $service */
        $service = app(TimeTrackingService::class);

        return $service->getUserMonthlyHours($user);
    }
}
