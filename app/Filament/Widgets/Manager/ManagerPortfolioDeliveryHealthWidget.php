<?php

namespace App\Filament\Widgets\Manager;

use App\Models\Client;
use App\Services\Intelligence\DeliveryFindingEvaluator;
use App\Services\Intelligence\ProjectDeliveryHealthService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class ManagerPortfolioDeliveryHealthWidget extends Widget
{
    protected static string $view = 'filament.widgets.manager.manager-portfolio-delivery-health-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public function getPortfolioData(): array
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $clientIds = $user ? $user->getAssignedClientIds() : [];

        $clientsQuery = Client::query();
        $filteredIds = array_diff($clientIds, ['*']);
        if (! empty($filteredIds)) {
            $clientsQuery->whereIn('id', $filteredIds);
        }
        $clients = $clientsQuery->where('status', 'active')->orderBy('name')->get();

        /** @var ProjectDeliveryHealthService $healthService */
        $healthService = app(ProjectDeliveryHealthService::class);

        $results = [];
        $counts = [
            'Healthy'   => 0,
            'Watch'     => 0,
            'At Risk'   => 0,
            'Unknown'   => 0,
        ];

        foreach ($clients as $client) {
            $eval = $healthService->evaluateClientHealth($client);
            $status = $eval['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            } else {
                $counts['Unknown']++;
            }

            $results[] = [
                'client_id'   => $client->id,
                'name'        => $client->name,
                'status'      => $status,
                'badge_color' => $eval['badge_color'],
                'summary'     => $eval['summary'],
                'reasons'     => $eval['reasons'],
                'metrics'     => $eval['metrics'],
            ];
        }

        // Sort: At Risk first, then Watch, then Healthy, then Unknown
        $statusWeight = ['At Risk' => 1, 'Watch' => 2, 'Healthy' => 3, 'Unknown' => 4];
        usort($results, fn ($a, $b) => ($statusWeight[$a['status']] ?? 5) <=> ($statusWeight[$b['status']] ?? 5));

        return [
            'counts'  => $counts,
            'clients' => $results,
        ];
    }

    public function triggerDeliveryScan(): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $clientIds = $user ? $user->getAssignedClientIds() : [];

        $clientsQuery = Client::query();
        if (! empty($clientIds) && $clientIds !== ['*']) {
            $clientsQuery->whereIn('id', $clientIds);
        }
        $clients = $clientsQuery->where('status', 'active')->get();

        /** @var DeliveryFindingEvaluator $evaluator */
        $evaluator = app(DeliveryFindingEvaluator::class);
        $totalFindings = 0;
        foreach ($clients as $c) {
            $totalFindings += $evaluator->evaluateClientFindings($c);
        }

        Notification::make()
            ->title('Portfolio Delivery Health Evaluated')
            ->body("Delivery scan completed across {$clients->count()} client accounts ({$totalFindings} operational signals checked).")
            ->success()
            ->send();
    }
}
