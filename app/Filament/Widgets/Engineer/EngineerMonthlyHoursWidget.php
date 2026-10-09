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

    public function getHoursData(): array
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        /** @var TimeTrackingService $service */
        $service = app(TimeTrackingService::class);

        return $service->getUserMonthlyHours($user);
    }
}
