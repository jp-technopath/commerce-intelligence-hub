<?php

namespace App\Filament\Pages;

use App\Models\Client;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\Intelligence\MeetingIntelligenceService;
use App\Services\Intelligence\ProjectDeliveryHealthService;
use App\Services\Intelligence\TimeTrackingService;
use App\Services\Intelligence\WorkPrioritizationEngine;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class EngineerDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';
    protected static ?string $navigationLabel = 'Engineer Dashboard';
    protected static ?string $title = 'Engineer Dashboard';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.engineer-dashboard';

    public ?int $selected_user_id = null;
    public bool $showAiInsight = true;

    public function mount(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if ($user && $user->isClientOnly()) {
            $this->redirect(CustomerDashboard::getUrl());
            return;
        }

        $sessionUserId = session('engineer_dashboard_user_id');
        $this->selected_user_id = $sessionUserId ? (int) $sessionUserId : (int) Auth::id();

        $this->form->fill([
            'selected_user_id' => $this->selected_user_id,
        ]);
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selected_user_id')
                    ->label('')
                    ->placeholder('Switch engineer...')
                    ->options($this->getUserOptions())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        $userId = $state ? (int) $state : (int) Auth::id();
                        session(['engineer_dashboard_user_id' => $userId]);
                        $this->selected_user_id = $userId;
                        $this->dispatch('engineer-user-changed', userId: $userId);
                    }),
            ]);
    }

    public function selectEngineer(int $userId): void
    {
        session(['engineer_dashboard_user_id' => $userId]);
        $this->selected_user_id = $userId;
        $this->form->fill(['selected_user_id' => $userId]);
        $this->dispatch('engineer-user-changed', userId: $userId);
    }

    public function resetToMe(): void
    {
        $myId = (int) Auth::id();
        session(['engineer_dashboard_user_id' => $myId]);
        $this->selected_user_id = $myId;
        $this->form->fill(['selected_user_id' => $myId]);
        $this->dispatch('engineer-user-changed', userId: $myId);
    }

    public function dismissAiInsight(): void
    {
        $this->showAiInsight = false;
    }

    public function getUserOptions(): array
    {
        $currentAuthId = Auth::id();
        $assignedUserIds = PmWorkItem::forCustomerSpacesWithJiraCode()->whereNotNull('user_id')->distinct()->pluck('user_id')->all();

        return User::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => ! $u->isClientOnly() || in_array($u->id, $assignedUserIds, true))
            ->mapWithKeys(function (User $u) use ($currentAuthId) {
                $suffix = ($u->id === $currentAuthId) ? ' (You)' : '';
                return [$u->id => "{$u->name}{$suffix}"];
            })
            ->toArray();
    }

    public function getSelectedUser(): ?User
    {
        $id = $this->selected_user_id ?? session('engineer_dashboard_user_id') ?? Auth::id();

        if (! $id) {
            return Auth::user();
        }

        return User::find($id) ?? Auth::user();
    }

    public function getWorkPlanItems(?User $targetUser): array
    {
        if (! $targetUser) {
            return [];
        }

        try {
            $engine = app(WorkPrioritizationEngine::class);
            $plan = $engine->getPrioritizedPlan($targetUser);
            $recommended = $plan['recommended_order'] ?? [];

            if (empty($recommended)) {
                return [];
            }

            $orderStyles = [
                1 => ['bg' => 'bg-rose-50 text-rose-500 border-rose-100', 'num' => 1],
                2 => ['bg' => 'bg-amber-50 text-amber-600 border-amber-100', 'num' => 2],
                3 => ['bg' => 'bg-amber-50 text-amber-600 border-amber-100', 'num' => 3],
                4 => ['bg' => 'bg-blue-50 text-blue-600 border-blue-100', 'num' => 4],
                5 => ['bg' => 'bg-emerald-50 text-emerald-600 border-emerald-100', 'num' => 5],
            ];

            $mapped = [];
            foreach (array_slice($recommended, 0, 5) as $index => $item) {
                $pos = $index + 1;
                $priority = $item['priority'] ?: 'Medium';
                $priorityClass = match (strtolower($priority)) {
                    'highest', 'critical', 'high' => 'bg-rose-50 text-rose-600 border-rose-100',
                    'medium' => 'bg-amber-50 text-amber-700 border-amber-100',
                    default => 'bg-emerald-50 text-emerald-700 border-emerald-100',
                };

                $iconType = 'task';
                $summaryLower = strtolower($item['title'] ?? '');
                if (str_contains($summaryLower, 'pr') || str_contains($summaryLower, 'pull request') || str_contains($summaryLower, 'review')) {
                    $iconType = 'pr';
                } elseif (str_contains($summaryLower, 'api') || str_contains($summaryLower, 'doc') || str_contains($summaryLower, 'readme')) {
                    $iconType = 'doc';
                } elseif (str_contains($summaryLower, 'error') || str_contains($summaryLower, 'checkout') || str_contains($summaryLower, 'bug') || str_contains($summaryLower, 'fix')) {
                    $iconType = 'branch';
                } elseif (str_contains($summaryLower, 'investigate') || str_contains($summaryLower, 'analytics') || str_contains($summaryLower, 'discrepancy')) {
                    $iconType = 'search';
                }

                $estTime = ($item['estimated_hours'] ?? 0) > 0 ? "{$item['estimated_hours']}h" : '2h';

                $mapped[] = [
                    'order'          => $pos,
                    'order_style'    => $orderStyles[$pos] ?? $orderStyles[5],
                    'icon_type'      => $iconType,
                    'title'          => $item['title'] ?? 'Operational task',
                    'key'            => $item['key'] ?? null,
                    'project'        => $item['client_name'] ?? 'Internal',
                    'priority'       => ucfirst(strtolower($priority)),
                    'priority_class' => $priorityClass,
                    'est_time'       => $estTime,
                    'why_matters'    => $item['reasoning'] ?? 'Keep project aligned for upcoming milestone.',
                    'jira_url'       => $item['jira_url'] ?: (! empty($item['key']) ? 'https://technopath.atlassian.net/browse/' . $item['key'] : null),
                ];
            }

            return $mapped;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getNeedsAttentionItems(?User $targetUser): array
    {
        if (! $targetUser) {
            return [];
        }

        try {
            $engine = app(WorkPrioritizationEngine::class);
            $plan = $engine->getPrioritizedPlan($targetUser);
            $attention = $plan['needs_attention'] ?? [];

            if (empty($attention)) {
                return [];
            }

            $items = [];
            foreach (array_slice($attention, 0, 5) as $raw) {
                // Ensure resolved or completed items are never displayed
                if (! empty($raw['delivery_status']) && in_array(strtolower($raw['delivery_status']), ['completed', 'resolved', 'closed', 'cancelled'], true)) {
                    continue;
                }

                $priority = $raw['priority'] ?: 'Medium';
                $isHigh = in_array(strtolower($priority), ['highest', 'critical', 'high'], true);
                
                $reasonLower = strtolower($raw['reason'] ?? '');
                $icon = 'clock';
                $typeTitle = 'Blocked';

                if (str_contains($reasonLower, 'meeting')) {
                    $icon = 'meeting';
                    $typeTitle = 'Meeting preparation';
                } elseif (str_contains($reasonLower, 'response') || str_contains($reasonLower, 'question') || str_contains($reasonLower, 'customer')) {
                    $icon = 'chat';
                    $typeTitle = 'Response needed';
                } elseif (str_contains($reasonLower, 'time') || str_contains($reasonLower, 'hours') || str_contains($reasonLower, 'log')) {
                    $icon = 'timer';
                    $typeTitle = 'Time entry missing';
                } elseif (str_contains($reasonLower, 'qa') || str_contains($reasonLower, 'rework') || str_contains($reasonLower, 'fail')) {
                    $icon = 'alert';
                    $typeTitle = 'QA failure';
                }

                $items[] = [
                    'icon'           => $icon,
                    'title'          => $typeTitle,
                    'description'    => $raw['title'] ?? $raw['reason'] ?? 'Attention required',
                    'key'            => $raw['key'] ?? null,
                    'priority'       => $isHigh ? 'High' : 'Medium',
                    'priority_class' => $isHigh ? 'bg-rose-50 text-rose-600 border-rose-100' : 'bg-amber-50 text-amber-700 border-amber-100',
                    'jira_url'       => $raw['jira_url'] ?: (! empty($raw['key']) ? 'https://technopath.atlassian.net/browse/' . $raw['key'] : null),
                ];
            }

            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getMyHoursData(?User $targetUser): array
    {
        $defaultAllocation = 160;

        if (! $targetUser) {
            return [
                'total_hours'        => 0,
                'allocated_hours'    => $defaultAllocation,
                'progress_pct'       => 0,
                'customers'          => [],
                'missing_time_count' => 0,
            ];
        }

        try {
            $timeService = app(TimeTrackingService::class);
            $hoursInfo = $timeService->getUserMonthlyHours($targetUser);
            $totalHours = (float) ($hoursInfo['total_hours'] ?? 0);
            $allocation = (int) ($hoursInfo['working_capacity_hours'] ?? $defaultAllocation);

            $colors = ['#3B82F6', '#8B5CF6', '#F59E0B', '#10B981', '#EC4899'];
            $barColors = ['bg-blue-500', 'bg-purple-600', 'bg-amber-500', 'bg-emerald-500', 'bg-pink-500'];
            $customers = [];

            foreach ($hoursInfo['by_customer'] ?? [] as $idx => $cData) {
                $cHours = (float) $cData['hours'];
                $cAlloc = (int) ($cData['allocated_hours'] ?? 60);
                $cPct = $cAlloc > 0 ? min(100, (int) round(($cHours / $cAlloc) * 100)) : 0;

                $customers[] = [
                    'name'         => $cData['client_name'],
                    'color'        => $colors[$idx % count($colors)],
                    'hours'        => "{$cHours}h",
                    'allocation'   => "{$cAlloc}h",
                    'pct'          => $cPct,
                    'bar_color'    => $barColors[$idx % count($barColors)],
                ];
            }

            // If user has 0 hours logged, show real customer spaces from Forge with 0h
            if (empty($customers)) {
                $clientIds = PmWorkItem::forCustomerSpacesWithJiraCode()
                    ->where('user_id', $targetUser->id)
                    ->whereNotNull('client_id')
                    ->distinct()
                    ->pluck('client_id')
                    ->all();

                $assignedClients = Client::withJiraProjectKey()
                    ->whereIn('id', $clientIds)
                    ->take(3)
                    ->get();

                if ($assignedClients->isEmpty()) {
                    $assignedClients = Client::withJiraProjectKey()->take(3)->get();
                }

                foreach ($assignedClients as $idx => $client) {
                    $customers[] = [
                        'name'         => $client->name,
                        'color'        => $colors[$idx % count($colors)],
                        'hours'        => '0h',
                        'allocation'   => '40h',
                        'pct'          => 0,
                        'bar_color'    => $barColors[$idx % count($barColors)],
                    ];
                }
            }

            $progressPct = $allocation > 0 ? min(100, (int) round(($totalHours / $allocation) * 100)) : 0;

            return [
                'total_hours'        => round($totalHours, 1),
                'allocated_hours'    => $allocation,
                'progress_pct'       => $progressPct,
                'customers'          => $customers,
                'missing_time_count' => (int) ($hoursInfo['missing_time_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return [
                'total_hours'        => 0,
                'allocated_hours'    => $defaultAllocation,
                'progress_pct'       => 0,
                'customers'          => [],
                'missing_time_count' => 0,
            ];
        }
    }

    public function getUpcomingMeetingsData(?User $targetUser): array
    {
        if (! $targetUser) {
            return [];
        }

        try {
            $meetingService = app(MeetingIntelligenceService::class);
            $meetings = $meetingService->getUserUpcomingMeetings($targetUser, 5);

            if ($meetings->isEmpty()) {
                return [];
            }

            $mapped = [];
            foreach ($meetings->take(2) as $idx => $m) {
                $start = $m->meeting_start_at ? Carbon::parse($m->meeting_start_at) : now()->addDay();
                $end = $m->meeting_end_at ? Carbon::parse($m->meeting_end_at) : $start->copy()->addHour();
                $timeString = ($start->isTomorrow() ? 'Tomorrow, ' : $start->format('D, M j, ')) . $start->format('g:i') . ' – ' . $end->format('g:i A');

                $action = ($m->prep_stage ?? 'needed') === 'needed' ? 'View preparation brief' : 'Add to preparation list';

                $mapped[] = [
                    'id'           => $m->id,
                    'title'        => $m->title,
                    'time_label'   => $timeString,
                    'description'  => $m->client?->name ? "Meeting with {$m->client->name} team on milestone delivery." : 'Prepare project update and discuss next sprint.',
                    'action_label' => $action,
                    'icon_color'   => $idx === 0 ? 'blue' : 'amber',
                ];
            }

            return $mapped;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getProjectHealthItems(?User $targetUser): array
    {
        if (! $targetUser) {
            return [];
        }

        try {
            $clientIds = PmWorkItem::forCustomerSpacesWithJiraCode()
                ->where('user_id', $targetUser->id)
                ->whereNotNull('client_id')
                ->distinct()
                ->pluck('client_id')
                ->all();

            $clientsQuery = Client::withJiraProjectKey();
            if (! empty($clientIds)) {
                $clients = $clientsQuery->whereIn('id', $clientIds)->take(4)->get();
            } else {
                $clients = $clientsQuery->take(4)->get();
            }

            if ($clients->isEmpty()) {
                return [];
            }

            $healthService = app(ProjectDeliveryHealthService::class);
            $items = [];
            foreach ($clients as $client) {
                $eval = $healthService->evaluateClientHealth($client);
                $status = $eval['status'] ?? 'Healthy';
                $badgeClass = match (strtolower($status)) {
                    'healthy' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
                    'watch' => 'bg-amber-50 text-amber-700 border-amber-100',
                    default => 'bg-rose-50 text-rose-700 border-rose-100',
                };

                $taskCount = PmWorkItem::forCustomerSpacesWithJiraCode()
                    ->excludeInactive()
                    ->excludeCompletedAndDone()
                    ->where('client_id', $client->id)
                    ->when($targetUser, fn ($q) => $q->where('user_id', $targetUser->id))
                    ->count();

                $items[] = [
                    'name'         => $client->name,
                    'health'       => ucfirst(strtolower($status)),
                    'health_class' => $badgeClass,
                    'upcoming'     => now()->addDays(rand(4, 20))->format('M j'),
                    'is_urgent'    => strtolower($status) === 'watch',
                    'tasks_count'  => "{$taskCount} active",
                ];
            }

            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getAiInsightText(?User $targetUser, array $workPlan, array $meetings): string
    {
        $topTask = $workPlan[0] ?? null;
        $topMeeting = $meetings[0] ?? null;

        if ($topTask && $topMeeting) {
            $client = $topTask['project'] ?? 'assigned client';
            return "Your focus today should be on \"{$topTask['title']}\" ({$client}) and preparing for {$topMeeting['title']}. Completing these will keep sprint delivery on schedule.";
        }

        if ($topTask) {
            $client = $topTask['project'] ?? 'assigned client';
            return "Your primary focus today is on \"{$topTask['title']}\" ({$client}). Resolving this will maintain sprint momentum.";
        }

        if ($topMeeting) {
            return "You have an upcoming meeting: {$topMeeting['title']}. Make sure your milestone updates and questions are prepared.";
        }

        return "You're all caught up on scheduled tasks and meetings today! Feel free to review the backlog or coordinate with your team.";
    }

    public function getHeaderWidgets(): array
    {
        return [];
    }

    public function getFooterWidgets(): array
    {
        return [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return ! $user->isClientOnly();
    }

    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return ! $user->isClientOnly();
    }
}
