<?php

namespace App\Filament\Pages;

use App\Jobs\SyncPmProjectJob;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\Intelligence\MeetingIntelligenceService;
use App\Services\Intelligence\ProjectDeliveryHealthService;
use App\Services\Intelligence\TimeTrackingService;
use App\Services\Intelligence\WorkPrioritizationEngine;
use App\Services\PM\IdentityResolverService;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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

    public function getLastSyncedAt(): ?Carbon
    {
        $lastPm = PmWorkItem::max('last_synced_at');
        $lastConn = PmConnection::max('last_synced_at');
        $lastMeeting = ClientMeeting::max('updated_at');

        $latest = collect([$lastPm, $lastConn, $lastMeeting])
            ->filter()
            ->map(fn ($ts) => Carbon::parse($ts))
            ->max();

        return $latest;
    }

    public function refreshDashboardData(): void
    {
        try {
            $activeProjects = PmProject::where('is_active', true)->get();
            foreach ($activeProjects as $project) {
                SyncPmProjectJob::dispatch($project, 14);
            }

            if (class_exists(\App\Jobs\MeetingAgent\ScanGoogleCalendarJob::class)) {
                \App\Jobs\MeetingAgent\ScanGoogleCalendarJob::dispatch();
            }

            Notification::make()
                ->title('Refresh Queued')
                ->body('Sync queued for ' . $activeProjects->count() . ' active project(s) and calendar meetings.')
                ->success()
                ->send();

            $this->dispatch('$refresh');
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Refresh Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getNextDueItem(
        ?User $targetUser,
        array $workPlanItems,
        array $needsAttentionItems,
        array $upcomingMeetings
    ): array {
        // 1. Check for upcoming meetings today missing prep
        $meetingMissingPrepToday = collect($upcomingMeetings)->first(function ($m) {
            return ($m['action_type'] ?? '') === 'prep' &&
                isset($m['raw_start']) &&
                Carbon::parse($m['raw_start'])->isToday();
        });

        if ($meetingMissingPrepToday) {
            return [
                'type'         => 'meeting_prep',
                'badge_text'   => 'Meeting Prep Required',
                'badge_color'  => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
                'title'        => $meetingMissingPrepToday['title'],
                'subtitle'     => 'Starts at ' . Carbon::parse($meetingMissingPrepToday['raw_start'])->format('g:i A') . ' • Missing preparation brief & Jira context',
                'due_label'    => $meetingMissingPrepToday['time_label'],
                'target_id'    => 'section-upcoming-meetings',
                'action_label' => 'Jump to meeting',
                'action_url'   => $meetingMissingPrepToday['action_url'] ?? null,
            ];
        }

        // 2. Check for high-priority blocker / attention items
        $highAttention = collect($needsAttentionItems)->first(function ($item) {
            return ($item['priority'] ?? '') === 'High';
        });

        if ($highAttention) {
            $keyPrefix = ! empty($highAttention['key']) ? "{$highAttention['key']}: " : '';
            return [
                'type'         => 'blocker',
                'badge_text'   => 'Needs Immediate Attention',
                'badge_color'  => 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800',
                'title'        => $keyPrefix . $highAttention['title'],
                'subtitle'     => $highAttention['description'],
                'due_label'    => 'Urgent attention required',
                'target_id'    => 'section-needs-attention',
                'action_label' => 'Jump to blocker',
                'action_url'   => $highAttention['jira_url'] ?? null,
            ];
        }

        // 3. Check for any upcoming meeting missing prep (tomorrow/next days)
        $meetingMissingPrep = collect($upcomingMeetings)->first(function ($m) {
            return ($m['action_type'] ?? '') === 'prep';
        });

        if ($meetingMissingPrep) {
            return [
                'type'         => 'meeting_prep',
                'badge_text'   => 'Meeting Prep Pending',
                'badge_color'  => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
                'title'        => $meetingMissingPrep['title'],
                'subtitle'     => 'Scheduled ' . $meetingMissingPrep['time_label'] . ' • Needs briefing generated',
                'due_label'    => $meetingMissingPrep['time_label'],
                'target_id'    => 'section-upcoming-meetings',
                'action_label' => 'Jump to meeting',
                'action_url'   => $meetingMissingPrep['action_url'] ?? null,
            ];
        }

        // 4. Check for meetings missing follow-up
        $meetingMissingFollowUp = collect($upcomingMeetings)->first(function ($m) {
            return ($m['action_type'] ?? '') === 'follow_up';
        });

        if ($meetingMissingFollowUp) {
            return [
                'type'         => 'meeting_followup',
                'badge_text'   => 'Meeting Follow-up Needed',
                'badge_color'  => 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200 dark:border-purple-800',
                'title'        => $meetingMissingFollowUp['title'],
                'subtitle'     => 'Completed ' . $meetingMissingFollowUp['time_label'] . ' • Client summary & action items pending',
                'due_label'    => 'Follow-up pending',
                'target_id'    => 'section-upcoming-meetings',
                'action_label' => 'Jump to meeting',
                'action_url'   => $meetingMissingFollowUp['action_url'] ?? null,
            ];
        }

        // 5. Check for any remaining attention item
        $anyAttention = collect($needsAttentionItems)->first();
        if ($anyAttention) {
            $keyPrefix = ! empty($anyAttention['key']) ? "{$anyAttention['key']}: " : '';
            return [
                'type'         => 'attention',
                'badge_text'   => 'Action Required',
                'badge_color'  => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
                'title'        => $keyPrefix . $anyAttention['title'],
                'subtitle'     => $anyAttention['description'],
                'due_label'    => 'Action required today',
                'target_id'    => 'section-needs-attention',
                'action_label' => 'Jump to item',
                'action_url'   => $anyAttention['jira_url'] ?? null,
            ];
        }

        // 6. Check for #1 task in Recommended Work Plan
        $topWorkItem = collect($workPlanItems)->first();
        if ($topWorkItem) {
            $keyPrefix = ! empty($topWorkItem['key']) ? "{$topWorkItem['key']}: " : '';
            return [
                'type'         => 'work_plan',
                'badge_text'   => 'Top Priority Task (#1)',
                'badge_color'  => 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800',
                'title'        => $keyPrefix . $topWorkItem['title'],
                'subtitle'     => $topWorkItem['project'] . ' • Est. ' . $topWorkItem['est_time'] . ' • ' . $topWorkItem['why_matters'],
                'due_label'    => 'Recommended next',
                'target_id'    => 'section-work-plan',
                'action_label' => 'Jump to task',
                'action_url'   => $topWorkItem['jira_url'] ?? null,
            ];
        }

        // 7. Caught up
        return [
            'type'         => 'none',
            'badge_text'   => 'All Clear',
            'badge_color'  => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800',
            'title'        => 'You are all caught up!',
            'subtitle'     => 'No immediate blockers, upcoming meeting prep, or pending tasks requiring immediate action.',
            'due_label'    => 'Up to date',
            'target_id'    => 'section-work-plan',
            'action_label' => 'View work plan',
            'action_url'   => null,
        ];
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
                $taskCount = PmWorkItem::where('user_id', $u->id)->where('normalized_delivery_status', '!=', 'completed')->count();
                $countStr = $taskCount > 0 ? " ({$taskCount} tasks)" : "";
                return [$u->id => "{$u->name}{$countStr}{$suffix}"];
            })
            ->toArray();
    }

    public function getSelectedUser(): ?User
    {
        $id = $this->selected_user_id ?? session('engineer_dashboard_user_id');

        if ($id) {
            $user = User::find($id);
            if ($user) {
                return IdentityResolverService::resolveEffectiveUser($user);
            }
        }

        $authUser = Auth::user();
        if ($authUser) {
            // If logged in user is Google alias or PO alias, resolve to primary engineer
            $effective = IdentityResolverService::resolveEffectiveUser($authUser);
            if ($effective->id !== $authUser->id) {
                return $effective;
            }

            // If Admin or user with 0 active tasks, default to Jean Paul (User 2) or first engineer with active tasks
            $hasTasks = PmWorkItem::where('user_id', $authUser->id)
                ->where('normalized_delivery_status', '!=', 'completed')
                ->exists();

            if (! $hasTasks) {
                $defaultUser = User::where('email', 'jp@technopath.co')->first() ?? User::find(2);
                if ($defaultUser) {
                    return $defaultUser;
                }

                $firstWithTasksId = PmWorkItem::whereNotNull('user_id')
                    ->where('normalized_delivery_status', '!=', 'completed')
                    ->value('user_id');

                if ($firstWithTasksId) {
                    return User::find($firstWithTasksId) ?? $authUser;
                }
            }

            return $authUser;
        }

        return null;
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
            // Look up to 7 days ahead and back 24 hours to include recent meetings needing follow-up
            $meetings = $meetingService->getUserUpcomingMeetings($targetUser, 7, 24);

            if ($meetings->isEmpty()) {
                return [];
            }

            $mapped = [];
            foreach ($meetings as $m) {
                // Determine whether prep is complete (exists, has content/briefing/reviewed/completed, no AI error)
                $hasPrep = false;
                if ($m->prep && empty($m->prep->ai_error)) {
                    if (
                        ! empty($m->prep->internal_summary) ||
                        ! empty($m->prep->generated_status_email_body) ||
                        ! empty($m->prep->recommended_agenda) ||
                        ! empty($m->prep->prep_content) ||
                        ! empty($m->prep->executive_briefing) ||
                        ! empty($m->prep->email_sent_at) ||
                        ! empty($m->prep->generated_at) ||
                        ($m->prep->status ?? null) === 'completed' ||
                        ! empty($m->prep->metadata['is_reviewed'])
                    ) {
                        $hasPrep = true;
                    }
                }
                if (
                    $m->status === \App\Enums\MeetingStatus::PrepGenerated ||
                    $m->status === \App\Enums\MeetingStatus::DraftCreated ||
                    $m->status === \App\Enums\MeetingStatus::FollowUpPending ||
                    $m->status === \App\Enums\MeetingStatus::FollowUpGenerated ||
                    $m->status === \App\Enums\MeetingStatus::Completed ||
                    in_array($m->metadata['prep_stage'] ?? null, ['draft_generated', 'reviewed_ready', 'completed'], true)
                ) {
                    $hasPrep = true;
                }

                // Determine whether follow-up is complete (exists, has content/summary/email_sent/completed, no AI error)
                $hasFollowUp = false;
                if ($m->followUp && empty($m->followUp->ai_error)) {
                    if (
                        ! empty($m->followUp->summary) ||
                        ! empty($m->followUp->generated_followup_email_body) ||
                        ! empty($m->followUp->follow_up_content) ||
                        ! empty($m->followUp->executive_summary) ||
                        ! empty($m->followUp->email_sent_at) ||
                        ! empty($m->followUp->generated_at) ||
                        ($m->followUp->status ?? null) === 'completed' ||
                        ! empty($m->followUp->metadata['is_completed'])
                    ) {
                        $hasFollowUp = true;
                    }
                }
                if (
                    $m->status === \App\Enums\MeetingStatus::FollowUpGenerated ||
                    $m->status === \App\Enums\MeetingStatus::Completed ||
                    in_array($m->metadata['followup_stage'] ?? null, ['draft_generated', 'reviewed_ready', 'completed'], true)
                ) {
                    $hasFollowUp = true;
                }

                // ONLY show meetings missing prep OR missing follow up.
                // If both are completed, exclude it!
                if ($hasPrep && $hasFollowUp) {
                    continue;
                }

                $start = $m->meeting_start_at ? Carbon::parse($m->meeting_start_at) : now()->addDay();
                $end = $m->meeting_end_at ? Carbon::parse($m->meeting_end_at) : $start->copy()->addHour();

                $datePrefix = $start->isToday() ? 'Today, ' : ($start->isTomorrow() ? 'Tomorrow, ' : ($start->isYesterday() ? 'Yesterday, ' : $start->format('D, M j, ')));
                $timeString = $datePrefix . $start->format('g:i') . ' – ' . $end->format('g:i A');

                $isPast = $start->isPast();

                // If missing prep and meeting hasn't already concluded, button creates prep
                if (! $hasPrep && ! $isPast) {
                    $actionLabel = 'Create prep';
                    $actionType = 'prep';
                    $actionUrl = route('filament.admin.resources.client-meetings.view', [
                        'record' => $m->id,
                        'tab'    => 'prep',
                        'action' => 'generatePrep',
                    ]);
                    $badgeLabel = 'Missing prep';
                    $badgeColor = 'amber';
                } else {
                    // Prep is completed or meeting already occurred, but follow up is missing
                    $actionLabel = 'Create follow up';
                    $actionType = 'follow_up';
                    $actionUrl = route('filament.admin.resources.client-meetings.view', [
                        'record' => $m->id,
                        'tab'    => 'follow-up',
                        'action' => 'generateFollowUp',
                    ]);
                    $badgeLabel = 'Missing follow-up';
                    $badgeColor = 'indigo';
                }

                $mapped[] = [
                    'id'           => $m->id,
                    'title'        => $m->title,
                    'time_label'   => $timeString,
                    'description'  => $m->client?->name ? "Meeting with {$m->client->name} team on milestone delivery." : 'Prepare project update and discuss next sprint.',
                    'action_label' => $actionLabel,
                    'action_type'  => $actionType,
                    'action_url'   => $actionUrl,
                    'badge_label'  => $badgeLabel,
                    'badge_color'  => $badgeColor,
                    'view_url'     => route('filament.admin.resources.client-meetings.view', ['record' => $m->id]),
                    'icon_color'   => $actionType === 'prep' ? 'blue' : 'amber',
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
