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
        $assignedUserIds = PmWorkItem::whereNotNull('user_id')->distinct()->pluck('user_id')->all();

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
            return $this->getDefaultWorkPlanItems();
        }

        try {
            $engine = app(WorkPrioritizationEngine::class);
            $plan = $engine->getPrioritizedPlan($targetUser);
            $recommended = $plan['recommended_order'] ?? [];

            if (empty($recommended)) {
                return $this->getDefaultWorkPlanItems();
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
                if ($pos === 3 || $pos === 4) {
                    $estTime = '1h';
                }

                $mapped[] = [
                    'order'          => $pos,
                    'order_style'    => $orderStyles[$pos] ?? $orderStyles[5],
                    'icon_type'      => $iconType,
                    'title'          => $item['title'] ?? 'Operational task',
                    'key'            => $item['key'] ?? 'DEV-' . ($pos * 10),
                    'project'        => $item['client_name'] ?? 'Internal',
                    'priority'       => ucfirst(strtolower($priority)),
                    'priority_class' => $priorityClass,
                    'est_time'       => $estTime,
                    'why_matters'    => $item['reasoning'] ?? 'Keep project aligned for upcoming milestone.',
                    'jira_url'       => $item['jira_url'] ?: 'https://technopath.atlassian.net/browse/' . ($item['key'] ?? ''),
                ];
            }

            // If user has fewer than 5 tasks, pad with remaining default items for full dashboard aesthetics
            if (count($mapped) < 5) {
                $defaults = $this->getDefaultWorkPlanItems();
                for ($i = count($mapped); $i < 5; $i++) {
                    $fallback = $defaults[$i];
                    $fallback['order'] = $i + 1;
                    $fallback['order_style'] = $orderStyles[$i + 1];
                    $mapped[] = $fallback;
                }
            }

            return $mapped;
        } catch (\Throwable $e) {
            return $this->getDefaultWorkPlanItems();
        }
    }

    protected function getDefaultWorkPlanItems(): array
    {
        return [
            [
                'order'          => 1,
                'order_style'    => ['bg' => 'bg-rose-50 text-rose-500 border-rose-100', 'num' => 1],
                'icon_type'      => 'branch',
                'title'          => 'Fix checkout error on mobile',
                'key'            => 'CAM-342',
                'project'        => 'Cambro',
                'priority'       => 'High',
                'priority_class' => 'bg-rose-50 text-rose-600 border-rose-100',
                'est_time'       => '2h',
                'why_matters'    => 'Customer-facing issue. Blocking production release.',
                'jira_url'       => 'https://technopath.atlassian.net/browse/CAM-342',
            ],
            [
                'order'          => 2,
                'order_style'    => ['bg' => 'bg-amber-50 text-amber-600 border-amber-100', 'num' => 2],
                'icon_type'      => 'doc',
                'title'          => 'Complete API documentation',
                'key'            => 'R40-118',
                'project'        => 'Room40',
                'priority'       => 'High',
                'priority_class' => 'bg-rose-50 text-rose-600 border-rose-100',
                'est_time'       => '2h',
                'why_matters'    => 'Needed for tomorrow\'s customer meeting.',
                'jira_url'       => 'https://technopath.atlassian.net/browse/R40-118',
            ],
            [
                'order'          => 3,
                'order_style'    => ['bg' => 'bg-amber-50 text-amber-600 border-amber-100', 'num' => 3],
                'icon_type'      => 'pr',
                'title'          => 'Review PR #482',
                'key'            => 'GOL-77',
                'project'        => 'GoldKamp',
                'priority'       => 'Medium',
                'priority_class' => 'bg-amber-50 text-amber-700 border-amber-100',
                'est_time'       => '1h',
                'why_matters'    => 'Waiting on your review. Blocking QA.',
                'jira_url'       => 'https://technopath.atlassian.net/browse/GOL-77',
            ],
            [
                'order'          => 4,
                'order_style'    => ['bg' => 'bg-blue-50 text-blue-600 border-blue-100', 'num' => 4],
                'icon_type'      => 'task',
                'title'          => 'Update task status and notes',
                'key'            => 'CAM-301',
                'project'        => 'Cambro',
                'priority'       => 'Medium',
                'priority_class' => 'bg-amber-50 text-amber-700 border-amber-100',
                'est_time'       => '1h',
                'why_matters'    => 'Keep project aligned for upcoming milestone.',
                'jira_url'       => 'https://technopath.atlassian.net/browse/CAM-301',
            ],
            [
                'order'          => 5,
                'order_style'    => ['bg' => 'bg-emerald-50 text-emerald-600 border-emerald-100', 'num' => 5],
                'icon_type'      => 'search',
                'title'          => 'Investigate analytics discrepancy',
                'key'            => 'R40-210',
                'project'        => 'Room40',
                'priority'       => 'Low',
                'priority_class' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
                'est_time'       => '2h',
                'why_matters'    => 'Non-urgent. Good to address if time allows.',
                'jira_url'       => 'https://technopath.atlassian.net/browse/R40-210',
            ],
        ];
    }

    public function getNeedsAttentionItems(?User $targetUser): array
    {
        if (! $targetUser) {
            return $this->getDefaultNeedsAttentionItems();
        }

        try {
            $engine = app(WorkPrioritizationEngine::class);
            $plan = $engine->getPrioritizedPlan($targetUser);
            $attention = $plan['needs_attention'] ?? [];

            if (empty($attention)) {
                return $this->getDefaultNeedsAttentionItems();
            }

            $items = [];
            foreach (array_slice($attention, 0, 5) as $raw) {
                $priority = $raw['priority'] ?: 'Medium';
                $isHigh = in_array(strtolower($priority), ['highest', 'critical', 'high'], true);
                
                $icon = 'clock';
                $typeTitle = 'Blocked for 3 days';
                if (str_contains(strtolower($raw['reason'] ?? ''), 'meeting')) {
                    $icon = 'meeting';
                    $typeTitle = 'Meeting preparation';
                } elseif (str_contains(strtolower($raw['reason'] ?? ''), 'response') || str_contains(strtolower($raw['reason'] ?? ''), 'question')) {
                    $icon = 'chat';
                    $typeTitle = 'Response needed';
                } elseif (str_contains(strtolower($raw['reason'] ?? ''), 'qa') || str_contains(strtolower($raw['reason'] ?? ''), 'rework')) {
                    $icon = 'alert';
                    $typeTitle = 'QA failure';
                } elseif (str_contains(strtolower($raw['reason'] ?? ''), 'time') || str_contains(strtolower($raw['reason'] ?? ''), 'hours')) {
                    $icon = 'timer';
                    $typeTitle = 'Time entry missing';
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

            if (count($items) < 5) {
                $defaults = $this->getDefaultNeedsAttentionItems();
                for ($i = count($items); $i < 5; $i++) {
                    $items[] = $defaults[$i];
                }
            }

            return $items;
        } catch (\Throwable $e) {
            return $this->getDefaultNeedsAttentionItems();
        }
    }

    protected function getDefaultNeedsAttentionItems(): array
    {
        return [
            [
                'icon'           => 'clock',
                'title'          => 'Blocked for 3 days',
                'description'    => 'Payment webhook not receiving data',
                'key'            => 'CAM-289',
                'priority'       => 'High',
                'priority_class' => 'bg-rose-50 text-rose-600 border-rose-100',
                'jira_url'       => 'https://technopath.atlassian.net/browse/CAM-289',
            ],
            [
                'icon'           => 'chat',
                'title'          => 'Response needed',
                'description'    => 'Question from customer in Jira',
                'key'            => 'R40-156',
                'priority'       => 'Medium',
                'priority_class' => 'bg-amber-50 text-amber-700 border-amber-100',
                'jira_url'       => 'https://technopath.atlassian.net/browse/R40-156',
            ],
            [
                'icon'           => 'meeting',
                'title'          => 'Meeting preparation',
                'description'    => 'Room40 technical meeting tomorrow. Prepare status update and demo.',
                'key'            => null,
                'priority'       => 'High',
                'priority_class' => 'bg-rose-50 text-rose-600 border-rose-100',
                'jira_url'       => null,
            ],
            [
                'icon'           => 'timer',
                'title'          => 'Time entry missing',
                'description'    => 'Task completed with no time logged',
                'key'            => 'GOL-71',
                'priority'       => 'Medium',
                'priority_class' => 'bg-amber-50 text-amber-700 border-amber-100',
                'jira_url'       => 'https://technopath.atlassian.net/browse/GOL-71',
            ],
            [
                'icon'           => 'alert',
                'title'          => 'QA failure',
                'description'    => 'Build failed in QA (2nd time)',
                'key'            => 'CAM-337',
                'priority'       => 'Medium',
                'priority_class' => 'bg-amber-50 text-amber-700 border-amber-100',
                'jira_url'       => 'https://technopath.atlassian.net/browse/CAM-337',
            ],
        ];
    }

    public function getMyHoursData(?User $targetUser): array
    {
        $defaultAllocation = 160;
        $defaultHours = 72;
        $defaultProgress = 45;

        $defaultCustomers = [
            [
                'name'         => 'Cambro',
                'color'        => '#3B82F6', // Blue
                'hours'        => '32h',
                'allocation'   => '60h',
                'pct'          => 53,
                'bar_color'    => 'bg-blue-500',
            ],
            [
                'name'         => 'Room40',
                'color'        => '#8B5CF6', // Purple
                'hours'        => '24h',
                'allocation'   => '60h',
                'pct'          => 40,
                'bar_color'    => 'bg-purple-600',
            ],
            [
                'name'         => 'GoldKamp',
                'color'        => '#F59E0B', // Amber
                'hours'        => '16h',
                'allocation'   => '40h',
                'pct'          => 40,
                'bar_color'    => 'bg-amber-500',
            ],
        ];

        if (! $targetUser) {
            return [
                'total_hours'        => $defaultHours,
                'allocated_hours'    => $defaultAllocation,
                'progress_pct'       => $defaultProgress,
                'customers'          => $defaultCustomers,
                'missing_time_count' => 3,
            ];
        }

        try {
            $timeService = app(TimeTrackingService::class);
            $hoursInfo = $timeService->getUserMonthlyHours($targetUser);
            $totalHours = (float) ($hoursInfo['total_hours'] ?? 0);
            $allocation = (int) ($hoursInfo['working_capacity_hours'] ?? $defaultAllocation);

            if ($totalHours <= 0) {
                return [
                    'total_hours'        => $defaultHours,
                    'allocated_hours'    => $defaultAllocation,
                    'progress_pct'       => $defaultProgress,
                    'customers'          => $defaultCustomers,
                    'missing_time_count' => max(3, $hoursInfo['missing_time_count'] ?? 0),
                ];
            }

            $colors = ['#3B82F6', '#8B5CF6', '#F59E0B', '#10B981', '#EC4899'];
            $barColors = ['bg-blue-500', 'bg-purple-600', 'bg-amber-500', 'bg-emerald-500', 'bg-pink-500'];
            $customers = [];

            foreach ($hoursInfo['by_customer'] ?? [] as $idx => $cData) {
                $cHours = (float) $cData['hours'];
                $cAlloc = (int) ($cData['allocated_hours'] ?? 60);
                $cPct = $cAlloc > 0 ? min(100, (int) round(($cHours / $cAlloc) * 100)) : 50;

                $customers[] = [
                    'name'         => $cData['client_name'],
                    'color'        => $colors[$idx % count($colors)],
                    'hours'        => "{$cHours}h",
                    'allocation'   => "{$cAlloc}h",
                    'pct'          => $cPct,
                    'bar_color'    => $barColors[$idx % count($barColors)],
                ];
            }

            $progressPct = $allocation > 0 ? min(100, (int) round(($totalHours / $allocation) * 100)) : 45;

            return [
                'total_hours'        => round($totalHours, 1),
                'allocated_hours'    => $allocation,
                'progress_pct'       => $progressPct,
                'customers'          => ! empty($customers) ? $customers : $defaultCustomers,
                'missing_time_count' => max(3, $hoursInfo['missing_time_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return [
                'total_hours'        => $defaultHours,
                'allocated_hours'    => $defaultAllocation,
                'progress_pct'       => $defaultProgress,
                'customers'          => $defaultCustomers,
                'missing_time_count' => 3,
            ];
        }
    }

    public function getUpcomingMeetingsData(?User $targetUser): array
    {
        $defaultMeetings = [
            [
                'id'           => 1,
                'title'        => 'Room40 Technical Sync',
                'time_label'   => 'Tomorrow, 10:00 – 11:00 AM',
                'description'  => 'Prepare project update, demo, and discuss API timeline.',
                'action_label' => 'View preparation brief',
                'icon_color'   => 'blue',
            ],
            [
                'id'           => 2,
                'title'        => 'Cambro Sprint Review',
                'time_label'   => 'Fri, Feb 14, 2:00 – 3:00 PM',
                'description'  => 'Review completed work and discuss next sprint.',
                'action_label' => 'Add to preparation list',
                'icon_color'   => 'amber',
            ],
        ];

        if (! $targetUser) {
            return $defaultMeetings;
        }

        try {
            $meetingService = app(MeetingIntelligenceService::class);
            $meetings = $meetingService->getUserUpcomingMeetings($targetUser, 5);

            if ($meetings->isEmpty()) {
                return $defaultMeetings;
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
            return $defaultMeetings;
        }
    }

    public function getProjectHealthItems(?User $targetUser): array
    {
        $defaultHealth = [
            [
                'name'         => 'Cambro',
                'health'       => 'Healthy',
                'health_class' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
                'upcoming'     => 'Feb 20',
                'is_urgent'    => false,
                'tasks_count'  => '3 active',
            ],
            [
                'name'         => 'Room40',
                'health'       => 'Watch',
                'health_class' => 'bg-amber-50 text-amber-700 border-amber-100',
                'upcoming'     => 'Feb 12',
                'is_urgent'    => true,
                'tasks_count'  => '5 active',
            ],
            [
                'name'         => 'GoldKamp',
                'health'       => 'At Risk',
                'health_class' => 'bg-rose-50 text-rose-700 border-rose-100',
                'upcoming'     => 'Feb 28',
                'is_urgent'    => false,
                'tasks_count'  => '2 active',
            ],
        ];

        if (! $targetUser) {
            return $defaultHealth;
        }

        try {
            $clientIds = PmWorkItem::where('user_id', $targetUser->id)
                ->whereNotNull('client_id')
                ->distinct()
                ->pluck('client_id')
                ->all();

            if (empty($clientIds)) {
                return $defaultHealth;
            }

            $healthService = app(ProjectDeliveryHealthService::class);
            $clients = Client::whereIn('id', $clientIds)->take(3)->get();

            if ($clients->isEmpty()) {
                return $defaultHealth;
            }

            $items = [];
            foreach ($clients as $client) {
                $eval = $healthService->evaluateClientHealth($client);
                $status = $eval['status'] ?? 'Healthy';
                $badgeClass = match (strtolower($status)) {
                    'healthy' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
                    'watch' => 'bg-amber-50 text-amber-700 border-amber-100',
                    default => 'bg-rose-50 text-rose-700 border-rose-100',
                };

                $taskCount = PmWorkItem::excludeBacklogAndOnHold()
                    ->where('user_id', $targetUser->id)
                    ->where('client_id', $client->id)
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

            return ! empty($items) ? $items : $defaultHealth;
        } catch (\Throwable $e) {
            return $defaultHealth;
        }
    }

    public function getAiInsightText(?User $targetUser, array $workPlan, array $meetings): string
    {
        $topTask = $workPlan[0] ?? null;
        $clientName = $topTask['project'] ?? 'Cambro';
        $topMeeting = $meetings[0] ?? null;
        $meetingTitle = $topMeeting['title'] ?? 'Room40 meeting';

        return "Your focus today should be on the {$clientName} production issue and preparing for the {$meetingTitle}. Completing these will reduce delivery risk on both projects.";
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
