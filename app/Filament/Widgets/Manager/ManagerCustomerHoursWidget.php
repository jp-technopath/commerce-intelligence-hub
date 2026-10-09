<?php

namespace App\Filament\Widgets\Manager;

use App\Services\Intelligence\TimeTrackingService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class ManagerCustomerHoursWidget extends Widget
{
    protected static string $view = 'filament.widgets.manager.manager-customer-hours-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function getHoursData(): array
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $clientIds = $user ? $user->getAssignedClientIds() : [];

        /** @var TimeTrackingService $service */
        $service = app(TimeTrackingService::class);

        return $service->getManagerMonthlyHours($clientIds);
    }
}
