<x-filament-panels::page class="space-y-6">
    @php
        $targetUser = $this->getSelectedUser();
        $workPlanItems = $this->getWorkPlanItems($targetUser);
        $needsAttentionItems = $this->getNeedsAttentionItems($targetUser);
        $hoursData = $this->getMyHoursData($targetUser);
        $upcomingMeetings = $this->getUpcomingMeetingsData($targetUser);
        $projectHealthItems = $this->getProjectHealthItems($targetUser);
        $aiInsightText = $this->getAiInsightText($targetUser, $workPlanItems, $upcomingMeetings);
        $userOptions = $this->getUserOptions();
        $lastSyncedAt = $this->getLastSyncedAt();
        $nextDueItem = $this->getNextDueItem($targetUser, $workPlanItems, $needsAttentionItems, $upcomingMeetings);
    @endphp

    {{-- Top Header Section with Date Selector, Sync Info, and Engineer Switcher --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 -mt-2">
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                Engineer Dashboard
            </h1>
            <p class="text-xs md:text-sm text-slate-500 dark:text-slate-400 mt-0.5 font-normal">
                Your personalized work plan, action items, and project health
            </p>
        </div>

        <div class="flex items-center flex-wrap gap-2.5">
            {{-- Last Synced Pill with Refresh Button --}}
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg pl-3 pr-1.5 py-1 shadow-2xs flex items-center gap-2">
                <div class="flex items-center gap-1.5" title="{{ $lastSyncedAt ? 'Last synced: ' . $lastSyncedAt->format('M j, Y g:i A') : 'No sync recorded yet' }}">
                    @if ($lastSyncedAt)
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                    @else
                        <span class="w-2 h-2 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                    @endif
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300 whitespace-nowrap">
                        Synced {{ $lastSyncedAt ? $lastSyncedAt->diffForHumans() : 'Never' }}
                    </span>
                </div>
                <div class="h-3.5 w-px bg-slate-200 dark:bg-slate-700"></div>
                <button
                    type="button"
                    wire:click="refreshDashboardData"
                    wire:loading.attr="disabled"
                    class="text-blue-600 hover:text-blue-700 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-slate-700 font-semibold text-xs flex items-center gap-1 px-2 py-0.5 rounded transition cursor-pointer disabled:opacity-50"
                    title="Refresh data from Jira & Calendar"
                >
                    <x-heroicon-m-arrow-path class="w-3.5 h-3.5 shrink-0" wire:loading.class="animate-spin" wire:target="refreshDashboardData" />
                    <span wire:loading.remove wire:target="refreshDashboardData">Refresh</span>
                    <span wire:loading wire:target="refreshDashboardData">Syncing...</span>
                </button>
            </div>

            {{-- Current Date Display --}}
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 shadow-2xs flex items-center gap-1.5" title="Today's Date">
                <x-heroicon-m-calendar-days class="w-3.5 h-3.5 text-blue-500 dark:text-blue-400 shrink-0" />
                <span class="text-xs font-semibold text-slate-800 dark:text-slate-200">
                    {{ now()->format('D, M j, Y') }}
                </span>
            </div>

            {{-- Engineer Switcher Profile Card --}}
            <div 
                x-data="{ open: false }" 
                @click.outside="open = false" 
                class="relative"
            >
                <div 
                    @click="open = !open"
                    class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-slate-300 rounded-xl px-2.5 py-1.5 shadow-xs flex items-center gap-2.5 cursor-pointer transition select-none"
                    title="Switch engineer view"
                >
                    <div class="w-8 h-8 rounded-full overflow-hidden shrink-0 border border-indigo-200 dark:border-indigo-800 bg-gradient-to-tr from-indigo-500 via-indigo-600 to-purple-600 flex items-center justify-center text-white font-bold text-xs shadow-xs">
                        {{ substr($targetUser?->name ?? 'Alex Chen', 0, 2) }}
                    </div>
                    <div class="text-left pr-1">
                        <div class="text-xs font-bold text-slate-900 dark:text-slate-100 leading-tight truncate max-w-[120px]">
                            {{ $targetUser?->name ?? 'Alex Chen' }}
                        </div>
                        <div class="text-[10px] text-slate-400 font-medium leading-tight">
                            Engineer
                        </div>
                    </div>
                    <x-heroicon-m-chevron-down class="w-3.5 h-3.5 text-slate-400 shrink-0" />
                </div>

                {{-- Dropdown Menu --}}
                <div 
                    x-show="open" 
                    x-cloak 
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="transform opacity-0 scale-95"
                    x-transition:enter-end="transform opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="transform opacity-100 scale-100"
                    x-transition:leave-end="transform opacity-0 scale-95"
                    class="absolute right-0 mt-2 w-64 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl py-2 z-50 text-xs"
                >
                    <div class="px-3 py-1.5 border-b border-slate-100 dark:border-slate-700/60 font-semibold text-slate-500 uppercase tracking-wider text-[10px]">
                        Switch Team Member
                    </div>
                    <div class="max-h-56 overflow-y-auto py-1">
                        @foreach ($userOptions as $uId => $uName)
                            <button
                                type="button"
                                wire:click="selectEngineer({{ $uId }})"
                                @click="open = false"
                                class="w-full text-left px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-700 flex items-center justify-between text-slate-700 dark:text-slate-200 {{ $targetUser?->id == $uId ? 'font-bold bg-primary-50/50 dark:bg-primary-950/40 text-primary-600' : '' }}"
                            >
                                <span class="truncate">{{ $uName }}</span>
                                @if ($targetUser?->id == $uId)
                                    <x-heroicon-m-check class="w-4 h-4 text-primary-600" />
                                @endif
                            </button>
                        @endforeach
                    </div>

                    @if ($this->selected_user_id && $this->selected_user_id !== auth()->id())
                        <div class="p-2 border-t border-slate-100 dark:border-slate-700">
                            <button
                                type="button"
                                wire:click="resetToMe"
                                @click="open = false"
                                class="w-full px-3 py-1.5 text-center text-xs font-semibold text-slate-700 dark:text-slate-200 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 rounded-lg transition"
                            >
                                Reset to My View
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- STYLES FOR COMPACT COMMAND BAR --}}
    <style>
        .compact-cmd-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        @media (min-width: 1024px) {
            .compact-cmd-grid {
                grid-template-columns: 1.1fr 0.9fr;
                gap: 0.875rem;
            }
        }
        .compact-cmd-card {
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 0.875rem;
            padding: 0.75rem 1rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 100px;
        }
        .dark .compact-cmd-card {
            background: #0f172a;
            border-color: #1e293b;
        }
        .compact-cmd-chips {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        @media (max-width: 640px) {
            .compact-cmd-chips {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        .compact-cmd-chip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.4rem 0.6rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.15s ease;
            text-align: left;
        }
        .dark .compact-cmd-chip {
            background: #1e293b;
            border-color: #334155;
        }
        .compact-cmd-chip:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }
        .compact-spotlight-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 0.875rem;
            padding: 0.75rem 1rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 100px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .dark .compact-spotlight-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-color: #1e293b;
        }
        .compact-spotlight-card:hover {
            box-shadow: 0 4px 14px -2px rgba(59, 130, 246, 0.12);
            transform: translateY(-1px);
        }
        .dark .compact-spotlight-card:hover {
            box-shadow: 0 4px 14px -2px rgba(59, 130, 246, 0.25);
        }
    </style>

    {{-- TOP DASHBOARD SUMMARY & NEXT DUE ITEM COMMAND BAR --}}
    <div class="compact-cmd-grid">
        {{-- Card 1: Today at a Glance & Quick-Jump Counters --}}
        <div class="compact-cmd-card">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-md bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-400 shrink-0 shadow-2xs">
                        <x-heroicon-m-sparkles class="w-3.5 h-3.5" />
                    </span>
                    <span class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-wider">
                        Today's Overview
                    </span>
                    <span class="text-slate-300 dark:text-slate-600">•</span>
                    <span class="text-xs text-slate-500 dark:text-slate-400 truncate">
                        @if (count($needsAttentionItems) > 0)
                            <strong class="text-rose-600 dark:text-rose-400">{{ count($needsAttentionItems) }} item(s)</strong> need attention
                        @elseif (count($upcomingMeetings) > 0)
                            <strong class="text-amber-600 dark:text-amber-400">{{ count($upcomingMeetings) }} meeting(s)</strong> need prep or follow-up
                        @else
                            All clear &bull; <strong>{{ count($workPlanItems) }} task(s)</strong> scheduled
                        @endif
                    </span>
                </div>
            </div>

            {{-- 4 Stat Chips --}}
            <div class="compact-cmd-chips">
                <button 
                    type="button" 
                    onclick="jumpToSection('section-work-plan')"
                    class="compact-cmd-chip hover:border-indigo-300 dark:hover:border-indigo-600 hover:bg-indigo-50/40"
                    title="Jump to Work Plan"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-1">
                            <x-heroicon-m-clipboard-document-list class="w-3 h-3 text-indigo-500 shrink-0" />
                            <span class="text-[10px] uppercase font-bold text-indigo-600 dark:text-indigo-400 tracking-wider leading-none">Work Plan</span>
                        </div>
                        <div class="text-sm font-extrabold text-slate-900 dark:text-slate-100 leading-tight mt-0.5">{{ count($workPlanItems) }}</div>
                    </div>
                    <span class="text-[11px] text-slate-400">&darr;</span>
                </button>

                <button 
                    type="button" 
                    onclick="jumpToSection('section-needs-attention')"
                    class="compact-cmd-chip {{ count($needsAttentionItems) > 0 ? 'border-rose-200 bg-rose-50/70 dark:bg-rose-950/40 hover:bg-rose-100/60' : 'hover:border-emerald-300 hover:bg-emerald-50/30' }}"
                    title="Jump to Needs Attention"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-1">
                            @if (count($needsAttentionItems) > 0)
                                <x-heroicon-m-exclamation-triangle class="w-3 h-3 text-rose-500 shrink-0" />
                                <span class="text-[10px] uppercase font-bold text-rose-600 dark:text-rose-400 tracking-wider leading-none">Attention</span>
                            @else
                                <x-heroicon-m-check-circle class="w-3 h-3 text-emerald-500 shrink-0" />
                                <span class="text-[10px] uppercase font-bold text-emerald-600 dark:text-emerald-400 tracking-wider leading-none">Attention</span>
                            @endif
                        </div>
                        <div class="text-sm font-extrabold {{ count($needsAttentionItems) > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100' }} leading-tight mt-0.5">{{ count($needsAttentionItems) }}</div>
                    </div>
                    <span class="text-[11px] {{ count($needsAttentionItems) > 0 ? 'text-rose-500' : 'text-slate-400' }}">&darr;</span>
                </button>

                <button 
                    type="button" 
                    onclick="jumpToSection('section-upcoming-meetings')"
                    class="compact-cmd-chip {{ count($upcomingMeetings) > 0 ? 'border-amber-200 bg-amber-50/70 dark:bg-amber-950/40 hover:bg-amber-100/60' : 'hover:border-blue-300 hover:bg-blue-50/30' }}"
                    title="Jump to Upcoming Meetings"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-1">
                            <x-heroicon-m-calendar-days class="w-3 h-3 {{ count($upcomingMeetings) > 0 ? 'text-amber-500' : 'text-blue-500' }} shrink-0" />
                            <span class="text-[10px] uppercase font-bold {{ count($upcomingMeetings) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-blue-600 dark:text-blue-400' }} tracking-wider leading-none">Meetings</span>
                        </div>
                        <div class="text-sm font-extrabold {{ count($upcomingMeetings) > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-slate-900 dark:text-slate-100' }} leading-tight mt-0.5">{{ count($upcomingMeetings) }}</div>
                    </div>
                    <span class="text-[11px] {{ count($upcomingMeetings) > 0 ? 'text-amber-500' : 'text-slate-400' }}">&darr;</span>
                </button>

                <button 
                    type="button" 
                    onclick="jumpToSection('section-hours')"
                    class="compact-cmd-chip hover:border-emerald-300 dark:hover:border-emerald-600 hover:bg-emerald-50/40"
                    title="Jump to Hours"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-1">
                            <x-heroicon-m-clock class="w-3 h-3 text-emerald-500 shrink-0" />
                            <span class="text-[10px] uppercase font-bold text-emerald-600 dark:text-emerald-400 tracking-wider leading-none">Hours</span>
                        </div>
                        <div class="text-sm font-extrabold text-slate-900 dark:text-slate-100 leading-tight mt-0.5">{{ $hoursData['total_hours'] }}h</div>
                    </div>
                    <span class="text-[11px] text-slate-400">&darr;</span>
                </button>
            </div>
        </div>

        {{-- Card 2: Next Due Item Spotlight --}}
        @php
            $spotlightBorder = match($nextDueItem['type'] ?? '') {
                'meeting_prep' => 'border-l-4 border-l-amber-500',
                'meeting_followup' => 'border-l-4 border-l-purple-500',
                'blocker' => 'border-l-4 border-l-rose-500',
                'work_plan' => 'border-l-4 border-l-blue-500',
                default => 'border-l-4 border-l-emerald-500',
            };
        @endphp
        <div 
            onclick="jumpToSection('{{ $nextDueItem['target_id'] }}')"
            class="compact-spotlight-card group {{ $spotlightBorder }}"
            title="Click to jump to {{ $nextDueItem['title'] }}"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider {{ $nextDueItem['badge_color'] }}">
                        {{ $nextDueItem['badge_text'] }}
                    </span>
                </div>
                <div class="flex items-center gap-1 text-[11px] font-medium text-slate-500 dark:text-slate-400 shrink-0">
                    <x-heroicon-m-clock class="w-3.5 h-3.5 text-slate-400 shrink-0" />
                    <span>{{ $nextDueItem['due_label'] }}</span>
                </div>
            </div>

            <div class="my-1 min-w-0">
                <div class="text-xs md:text-sm font-bold text-slate-900 dark:text-slate-100 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition truncate leading-snug">
                    {{ $nextDueItem['title'] }}
                </div>
                <div class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5">
                    {{ $nextDueItem['subtitle'] }}
                </div>
            </div>

            <div class="flex items-center justify-between pt-1.5 border-t border-slate-100 dark:border-slate-800 text-xs mt-0.5">
                <div class="flex items-center gap-2">
                    @if (! empty($nextDueItem['action_url']))
                        <a
                            href="{{ $nextDueItem['action_url'] }}"
                            onclick="event.stopPropagation();"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold {{ in_array($nextDueItem['type'] ?? '', ['meeting_prep', 'meeting_followup'], true) ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-2xs' : 'bg-slate-100 hover:bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-200' }} transition cursor-pointer"
                            @if (! str_starts_with($nextDueItem['action_url'], '/')) target="_blank" rel="noopener" @endif
                        >
                            @if (($nextDueItem['type'] ?? '') === 'meeting_prep')
                                <x-heroicon-m-document-text class="w-3.5 h-3.5 shrink-0" />
                                <span>Create prep</span>
                            @elseif (($nextDueItem['type'] ?? '') === 'meeting_followup')
                                <x-heroicon-m-clipboard-document-check class="w-3.5 h-3.5 shrink-0" />
                                <span>Create follow up</span>
                            @else
                                <span>{{ $nextDueItem['action_label'] }}</span>
                            @endif
                            <span class="text-[10px]">&rarr;</span>
                        </a>

                        <button
                            type="button"
                            onclick="event.stopPropagation(); jumpToSection('{{ $nextDueItem['target_id'] }}')"
                            class="text-[11px] font-medium text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 transition inline-flex items-center gap-0.5 cursor-pointer"
                            title="Scroll down to meetings"
                        >
                            <span>Jump to meeting</span>
                            <span>&darr;</span>
                        </button>
                    @else
                        <button
                            type="button"
                            onclick="event.stopPropagation(); jumpToSection('{{ $nextDueItem['target_id'] }}')"
                            class="font-semibold text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-1 cursor-pointer"
                        >
                            <span>{{ $nextDueItem['action_label'] }}</span>
                            <span class="transition-transform group-hover:translate-y-0.5">&darr;</span>
                        </button>
                    @endif
                </div>

                <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider shrink-0">
                    Next Due Item
                </span>
            </div>
        </div>
    </div>

    {{-- SECTION 1: My Recommended Work Plan --}}
    <div id="section-work-plan" class="scroll-mt-6 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl p-6 shadow-xs">
        <div class="flex items-center gap-3 mb-4">
            <div class="flex items-center gap-2">
                <span class="w-2 h-5 rounded-full bg-indigo-600 dark:bg-indigo-500 inline-block"></span>
                <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                    1. My Recommended Work Plan
                </h2>
            </div>
            <span class="bg-purple-100 text-purple-700 dark:bg-purple-950/70 dark:text-purple-300 font-semibold text-xs px-3 py-1 rounded-full flex items-center gap-1.5 border border-purple-200/80 dark:border-purple-800/80 shadow-2xs">
                <x-heroicon-m-sparkles class="w-3.5 h-3.5 text-purple-600 dark:text-purple-400" />
                <span>AI prioritized for today</span>
            </span>
        </div>

        <div class="overflow-x-auto -mx-6 px-6">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-100 dark:border-slate-800">
                        <th class="py-2.5 px-3 w-12 font-bold text-slate-500 dark:text-slate-400">#</th>
                        <th class="py-2.5 px-3">Task</th>
                        <th class="py-2.5 px-3">Project</th>
                        <th class="py-2.5 px-3">Priority</th>
                        <th class="py-2.5 px-3">Est. time</th>
                        <th class="py-2.5 px-3">Why this matters</th>
                        <th class="py-2.5 px-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 dark:divide-slate-800/60">
                    @forelse ($workPlanItems as $item)
                        @php
                            $iconBg = match($item['icon_type'] ?? '') {
                                'pr' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/80 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
                                'doc' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800',
                                'search' => 'bg-purple-100 text-purple-700 dark:bg-purple-950/80 dark:text-purple-300 border border-purple-200 dark:border-purple-800',
                                'branch' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/80 dark:text-rose-300 border border-rose-200 dark:border-rose-800',
                                default => 'bg-blue-100 text-blue-700 dark:bg-blue-950/80 dark:text-blue-300 border border-blue-200 dark:border-blue-800',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                            {{-- Order Number Badge --}}
                            <td class="py-3 px-3 align-middle">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center font-bold text-xs border {{ $item['order_style']['bg'] }}">
                                    {{ $item['order'] }}
                                </div>
                            </td>

                            {{-- Task Icon & Title & Key --}}
                            <td class="py-3 px-3 align-middle">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 shadow-2xs {{ $iconBg }}">
                                        @if ($item['icon_type'] === 'pr')
                                            <x-heroicon-m-arrows-right-left class="w-4 h-4" />
                                        @elseif ($item['icon_type'] === 'doc')
                                            <x-heroicon-m-document-text class="w-4 h-4" />
                                        @elseif ($item['icon_type'] === 'search')
                                            <x-heroicon-m-magnifying-glass class="w-4 h-4" />
                                        @elseif ($item['icon_type'] === 'task')
                                            <x-heroicon-m-clipboard-document-check class="w-4 h-4" />
                                        @else
                                            <x-heroicon-m-code-bracket class="w-4 h-4" />
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-bold text-xs md:text-sm text-slate-900 dark:text-slate-100 leading-snug">
                                            {{ $item['title'] }}
                                        </div>
                                        @if (!empty($item['key']))
                                            <a 
                                                href="{{ $item['jira_url'] }}" 
                                                target="_blank" 
                                                rel="noopener noreferrer" 
                                                class="text-[11px] font-mono font-semibold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/60 px-1.5 py-0.5 rounded border border-blue-200/60 dark:border-blue-900/60 hover:bg-blue-100 dark:hover:bg-blue-900/80 mt-1 inline-block transition"
                                            >
                                                {{ $item['key'] }}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            {{-- Project --}}
                            <td class="py-3 px-3 align-middle">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700/80 whitespace-nowrap">
                                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                    {{ $item['project'] }}
                                </span>
                            </td>

                            {{-- Priority Pill --}}
                            <td class="py-3 px-3 align-middle">
                                <span class="text-xs font-bold px-2.5 py-0.5 rounded-full border {{ $item['priority_class'] }}">
                                    {{ $item['priority'] }}
                                </span>
                            </td>

                            {{-- Est. Time --}}
                            <td class="py-3 px-3 align-middle">
                                <span class="inline-flex items-center gap-1 text-xs font-mono font-medium text-slate-700 dark:text-slate-300 bg-slate-100/90 dark:bg-slate-800/80 px-2 py-0.5 rounded border border-slate-200/60 dark:border-slate-700/60 whitespace-nowrap">
                                    <x-heroicon-m-clock class="w-3.5 h-3.5 text-slate-400 shrink-0" />
                                    {{ $item['est_time'] }}
                                </span>
                            </td>

                            {{-- Why this matters --}}
                            <td class="py-3 px-3 align-middle max-w-sm">
                                <div class="border-l-2 border-indigo-400 dark:border-indigo-500 pl-2.5 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                    {{ $item['why_matters'] }}
                                </div>
                            </td>

                            {{-- Action: Start Button linking to Jira --}}
                            <td class="py-3 px-3 align-middle text-right">
                                <a 
                                    href="{{ $item['jira_url'] }}" 
                                    target="_blank" 
                                    rel="noopener noreferrer"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs px-3.5 py-1.5 rounded-lg shadow-2xs hover:shadow-xs transition inline-flex items-center gap-1"
                                >
                                    <span>Start</span>
                                    <span class="text-[10px]">&rarr;</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-10 text-center">
                                <div class="flex flex-col items-center justify-center text-slate-400">
                                    <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-2">
                                        <x-heroicon-m-check class="w-5 h-5" />
                                    </div>
                                    <div class="font-bold text-sm text-slate-800 dark:text-slate-200">
                                        All caught up!
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        No active tasks currently require your attention in the work plan.
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- MIDDLE GRID: Needs My Attention & My Hours This Month --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- SECTION 2: Needs My Attention --}}
        <div id="section-needs-attention" class="scroll-mt-6 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-5 rounded-full bg-rose-500 dark:bg-rose-400 inline-block"></span>
                        <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                            2. Needs My Attention
                        </h2>
                        <span class="{{ count($needsAttentionItems) > 0 ? 'bg-rose-100 text-rose-700 border border-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-800' : 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800' }} font-bold text-xs px-2.5 py-0.5 rounded-full shadow-2xs">
                            {{ count($needsAttentionItems) }} {{ count($needsAttentionItems) === 1 ? 'item' : 'items' }}
                        </span>
                    </div>
                </div>

                <div class="divide-y divide-slate-50 dark:divide-slate-800/60">
                    @forelse ($needsAttentionItems as $item)
                        @php
                            $attIconBg = match($item['icon'] ?? '') {
                                'alert' => 'bg-rose-100 text-rose-600 border border-rose-200 dark:bg-rose-950/70 dark:text-rose-400 dark:border-rose-800',
                                'meeting' => 'bg-amber-100 text-amber-600 border border-amber-200 dark:bg-amber-950/70 dark:text-amber-400 dark:border-amber-800',
                                'chat' => 'bg-purple-100 text-purple-600 border border-purple-200 dark:bg-purple-950/70 dark:text-purple-400 dark:border-purple-800',
                                'timer' => 'bg-blue-100 text-blue-600 border border-blue-200 dark:bg-blue-950/70 dark:text-blue-400 dark:border-blue-800',
                                default => 'bg-amber-100 text-amber-600 border border-amber-200 dark:bg-amber-950/70 dark:text-amber-400 dark:border-amber-800',
                            };
                        @endphp
                        <div class="py-3 flex items-center justify-between gap-3 group">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 shadow-2xs {{ $attIconBg }}">
                                    @if ($item['icon'] === 'chat')
                                        <x-heroicon-m-chat-bubble-left-right class="w-4 h-4" />
                                    @elseif ($item['icon'] === 'meeting')
                                        <x-heroicon-m-calendar-days class="w-4 h-4" />
                                    @elseif ($item['icon'] === 'alert')
                                        <x-heroicon-m-exclamation-circle class="w-4 h-4" />
                                    @elseif ($item['icon'] === 'timer')
                                        <x-heroicon-m-clock class="w-4 h-4" />
                                    @else
                                        <x-heroicon-m-clock class="w-4 h-4" />
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="font-bold text-xs md:text-sm text-slate-900 dark:text-slate-100 truncate">
                                        {{ $item['title'] }}
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-1">
                                        {{ $item['description'] }}
                                    </div>
                                    @if (!empty($item['key']))
                                        <a 
                                             href="{{ $item['jira_url'] }}" 
                                            target="_blank" 
                                            rel="noopener noreferrer" 
                                            class="text-[11px] font-mono font-semibold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/60 px-1.5 py-0.5 rounded border border-blue-200/60 dark:border-blue-900/60 hover:bg-blue-100 dark:hover:bg-blue-900/80 mt-1 inline-block transition"
                                        >
                                            {{ $item['key'] }}
                                        </a>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-2.5 shrink-0">
                                <span class="text-xs font-bold px-2 py-0.5 rounded-full border {{ $item['priority_class'] }}">
                                    {{ $item['priority'] }}
                                </span>
                                @if (!empty($item['jira_url']))
                                    <a href="{{ $item['jira_url'] }}" target="_blank" rel="noopener noreferrer" class="p-1 rounded hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 transition" title="Open in Jira">
                                        <x-heroicon-m-arrow-top-right-on-square class="w-4 h-4" />
                                    </a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-10 flex flex-col items-center justify-center text-center">
                            <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-2">
                                <x-heroicon-m-shield-check class="w-5 h-5" />
                            </div>
                            <div class="font-bold text-sm text-slate-800 dark:text-slate-200">
                                No Blockers or Action Items
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                None of your tasks are currently blocked or waiting on intervention.
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- SECTION 3: My Hours This Month --}}
        <div id="section-hours" class="scroll-mt-6 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl p-5 md:p-6 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-5 rounded-full bg-emerald-500 dark:bg-emerald-400 inline-block"></span>
                        <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                            3. My Hours This Month
                        </h2>
                    </div>
                    <span class="text-xs font-semibold text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-2.5 py-1 rounded-md border border-slate-200/80 dark:border-slate-700/80">
                        {{ now()->format('F Y') }}
                    </span>
                </div>

                {{-- Compact Stat and Progress Bar --}}
                <div class="mb-3.5">
                    <div class="flex items-baseline justify-between mb-1.5">
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight">
                                {{ $hoursData['total_hours'] }}h
                            </span>
                            <span class="text-xs font-medium text-slate-400 dark:text-slate-500">
                                of {{ $hoursData['allocated_hours'] }}h allocation
                            </span>
                        </div>
                        <span class="text-xs font-bold {{ $hoursData['progress_pct'] >= 80 ? 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800' : 'text-blue-600 bg-blue-50 border-blue-200 dark:bg-blue-950/60 dark:text-blue-400 dark:border-blue-900/60' }} px-2 py-0.5 rounded-md border shadow-2xs">
                            {{ $hoursData['progress_pct'] }}%
                        </span>
                    </div>

                    {{-- High-contrast Gradient Progress Bar --}}
                    <div class="w-full bg-slate-100 dark:bg-slate-800 h-2.5 rounded-full overflow-hidden border border-slate-200/60 dark:border-slate-700/60">
                        <div 
                            class="bg-gradient-to-r from-blue-600 via-indigo-600 to-emerald-500 h-2.5 rounded-full transition-all duration-500" 
                            style="width: {{ min(100, $hoursData['progress_pct']) }}%"
                        ></div>
                    </div>
                </div>

                {{-- Customer Breakdown Table (Compact & Sharp) --}}
                <div class="overflow-x-auto mb-3.5">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider border-b border-slate-100 dark:border-slate-800 text-left">
                                <th class="pb-1.5 font-semibold">Customer</th>
                                <th class="pb-1.5 text-right font-semibold pr-2">Hours</th>
                                <th class="pb-1.5 text-right font-semibold pr-2">Allocation</th>
                                <th class="pb-1.5 text-right font-semibold w-24">%</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50 dark:divide-slate-800/60">
                            @forelse ($hoursData['customers'] as $c)
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition">
                                    <td class="py-1.5 pr-2">
                                        <div class="flex items-center gap-2">
                                            <span class="w-2.5 h-2.5 rounded-xs shrink-0 ring-1 ring-black/10 dark:ring-white/10" style="background-color: {{ $c['color'] }}"></span>
                                            <span class="font-medium text-slate-800 dark:text-slate-200 truncate max-w-[150px] sm:max-w-none">
                                                {{ $c['name'] }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="py-1.5 pr-2 text-right font-bold text-slate-900 dark:text-slate-100 whitespace-nowrap">
                                        {{ $c['hours'] }}
                                    </td>
                                    <td class="py-1.5 pr-2 text-right text-slate-400 dark:text-slate-500 whitespace-nowrap">
                                        {{ $c['allocation'] }}
                                    </td>
                                    <td class="py-1.5 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <span class="font-semibold text-slate-700 dark:text-slate-300 w-7 text-right text-[11px]">
                                                {{ $c['pct'] }}%
                                            </span>
                                            <div class="w-12 bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden shrink-0">
                                                <div 
                                                    class="h-1.5 rounded-full" 
                                                    style="width: {{ min(100, $c['pct']) }}%; background-color: {{ $c['color'] }}"
                                                ></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-3 text-center text-slate-400 text-xs">
                                        No customer spaces tracked yet this month.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Missing Time Info Card --}}
            @if ($hoursData['missing_time_count'] > 0)
                <div class="bg-blue-50/90 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900/60 rounded-xl p-3 flex flex-col gap-2 mt-auto">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-2 min-w-0">
                            <x-heroicon-m-information-circle class="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                            <div class="text-xs text-slate-700 dark:text-slate-300 leading-snug">
                                You have <strong>{{ $hoursData['missing_time_count'] }} tasks completed with no time entries</strong>. Consider adding time to keep your hours up to date.
                            </div>
                        </div>

                        @if (! empty($hoursData['missing_time_jira_url']))
                            <a 
                                href="{{ $hoursData['missing_time_jira_url'] }}" 
                                target="_blank" 
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-xs font-semibold shadow-2xs hover:shadow-xs transition shrink-0 cursor-pointer"
                                title="Open filtered search in Jira"
                            >
                                <span>View in Jira</span>
                                <span class="text-[10px]">&rarr;</span>
                            </a>
                        @endif
                    </div>

                    @if (! empty($hoursData['missing_time_keys']))
                        <div class="flex items-center gap-1.5 pl-6 flex-wrap">
                            <span class="text-[11px] text-slate-400 font-medium">Tasks:</span>
                            @foreach ($hoursData['missing_time_keys'] as $mKey)
                                <a 
                                    href="https://technopath.atlassian.net/browse/{{ $mKey }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-block px-1.5 py-0.5 bg-white dark:bg-slate-800 border border-blue-200 dark:border-blue-800 rounded text-[10px] font-mono font-semibold text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/40 transition"
                                    title="Open {{ $mKey }} in Jira"
                                >
                                    {{ $mKey }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                <div class="bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/40 rounded-xl p-2.5 flex items-center gap-2 mt-auto text-xs text-emerald-800 dark:text-emerald-300">
                    <x-heroicon-m-check-circle class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                    <span>All completed tasks have time entries logged.</span>
                </div>
            @endif
        </div>
    </div>

    {{-- LOWER GRID: Upcoming Meetings & My Project Health --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- SECTION 4: Upcoming Meetings --}}
        <div id="section-upcoming-meetings" class="scroll-mt-6 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-5 rounded-full bg-blue-600 dark:bg-blue-500 inline-block"></span>
                        <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                            4. Upcoming Meetings
                        </h2>
                        <span class="{{ count($upcomingMeetings) > 0 ? 'bg-amber-100 text-amber-800 border border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }} font-bold text-xs px-2.5 py-0.5 rounded-full shadow-2xs">
                            {{ count($upcomingMeetings) }} upcoming
                        </span>
                    </div>
                    <a href="{{ \App\Filament\Resources\ClientMeetingResource::getUrl('index') }}" class="text-blue-600 dark:text-blue-400 hover:underline text-xs font-semibold">
                        View calendar
                    </a>
                </div>

                <div class="space-y-4">
                    @forelse ($upcomingMeetings as $meeting)
                        <div class="flex items-start justify-between gap-3 p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/40 transition border border-transparent hover:border-slate-100 dark:hover:border-slate-800">
                            <div class="flex items-start gap-3 min-w-0 flex-1">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 border 
                                    {{ ($meeting['action_type'] ?? '') === 'prep' ? 'bg-blue-100 text-blue-600 border-blue-200 dark:bg-blue-950/80 dark:text-blue-400 dark:border-blue-900 shadow-2xs' : 'bg-purple-100 text-purple-600 border-purple-200 dark:bg-purple-950/80 dark:text-purple-400 dark:border-purple-900 shadow-2xs' }}">
                                    @if (($meeting['action_type'] ?? '') === 'prep')
                                        <x-heroicon-m-calendar-days class="w-5 h-5" />
                                    @else
                                        <x-heroicon-m-chat-bubble-left-right class="w-5 h-5" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <a href="{{ $meeting['view_url'] }}" class="text-sm font-bold text-slate-900 dark:text-slate-100 hover:text-blue-600 dark:hover:text-blue-400 transition truncate">
                                            {{ $meeting['title'] }}
                                        </a>
                                        @if (! empty($meeting['badge_label']))
                                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border
                                                {{ ($meeting['badge_color'] ?? '') === 'amber' ? 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:border-amber-800' : 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/50 dark:text-purple-300 dark:border-purple-800' }}">
                                                {{ $meeting['badge_label'] }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400 font-medium mt-0.5">
                                        {{ $meeting['time_label'] }}
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400 mt-1 line-clamp-1">
                                        {{ $meeting['description'] }}
                                    </div>
                                </div>
                            </div>

                            @if (($meeting['action_type'] ?? '') === 'prep')
                                <a
                                    href="{{ $meeting['action_url'] }}"
                                    class="inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3.5 py-1.5 rounded-lg whitespace-nowrap shadow-2xs hover:shadow-xs transition shrink-0 cursor-pointer"
                                >
                                    <x-heroicon-m-document-text class="w-3.5 h-3.5 shrink-0" />
                                    <span>Create prep</span>
                                    <span class="text-[10px]">&rarr;</span>
                                </a>
                            @else
                                <a
                                    href="{{ $meeting['action_url'] }}"
                                    class="inline-flex items-center gap-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold px-3.5 py-1.5 rounded-lg whitespace-nowrap shadow-2xs hover:shadow-xs transition shrink-0 cursor-pointer"
                                >
                                    <x-heroicon-m-clipboard-document-check class="w-3.5 h-3.5 shrink-0" />
                                    <span>Create follow up</span>
                                    <span class="text-[10px]">&rarr;</span>
                                </a>
                            @endif
                        </div>
                    @empty
                        <div class="py-10 flex flex-col items-center justify-center text-center">
                            <div class="w-10 h-10 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mb-2">
                                <x-heroicon-m-calendar class="w-5 h-5" />
                            </div>
                            <div class="font-bold text-sm text-slate-800 dark:text-slate-200">
                                All Upcoming Meetings Prepared
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                No meetings are missing preparation or follow-up.
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- SECTION 5: My Project Health --}}
        <div id="section-project-health" class="scroll-mt-6 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-5 rounded-full bg-violet-600 dark:bg-violet-500 inline-block"></span>
                        <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                            5. My Project Health
                        </h2>
                        <span class="bg-violet-100 text-violet-800 border border-violet-200 dark:bg-violet-950/60 dark:text-violet-300 dark:border-violet-800 font-bold text-xs px-2.5 py-0.5 rounded-full shadow-2xs">
                            {{ count($projectHealthItems) }} projects
                        </span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-100 dark:border-slate-800">
                                <th class="py-2 px-3">Project</th>
                                <th class="py-2 px-3">Health</th>
                                <th class="py-2 px-3">Upcoming</th>
                                <th class="py-2 px-3 text-right">My Tasks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50 dark:divide-slate-800/60">
                            @forelse ($projectHealthItems as $p)
                                <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                                    <td class="py-3 px-3">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-slate-100 to-slate-200 dark:from-slate-800 dark:to-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700/80 font-bold text-xs flex items-center justify-center shrink-0 shadow-2xs">
                                                {{ strtoupper(substr($p['name'], 0, 2)) }}
                                            </div>
                                            <span class="font-bold text-xs md:text-sm text-slate-900 dark:text-slate-100">
                                                {{ $p['name'] }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-0.5 rounded-full border {{ $p['health_class'] }}">
                                            @if ($p['health'] === 'Healthy')
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            @elseif ($p['health'] === 'Watch')
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                            @else
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                            @endif
                                            <span>{{ $p['health'] }}</span>
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 text-xs {{ $p['is_urgent'] ? 'font-bold text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-400' }}">
                                        {{ $p['upcoming'] }}
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <span class="inline-flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200/80 dark:border-slate-700/80">
                                            <span>{{ $p['tasks_count'] }}</span>
                                            <span class="text-[10px] text-slate-400 font-normal">tasks</span>
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-8 text-center text-xs text-slate-400">
                                        No active client projects found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- SECTION 6: AI Insight Banner --}}
    @if ($showAiInsight)
        <div class="bg-gradient-to-r from-purple-50 via-indigo-50/50 to-purple-50 dark:from-purple-950/40 dark:via-indigo-950/30 dark:to-purple-950/40 border border-purple-200 dark:border-purple-800/80 rounded-2xl p-4 flex items-center justify-between shadow-2xs transition">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-600 to-indigo-600 text-white flex items-center justify-center shrink-0 shadow-2xs">
                    <x-heroicon-m-sparkles class="w-5 h-5" />
                </div>
                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3">
                    <span class="text-xs font-bold uppercase tracking-wider text-purple-700 dark:text-purple-300 bg-purple-100/80 dark:bg-purple-900/60 px-2 py-0.5 rounded border border-purple-200/60 dark:border-purple-800/60 whitespace-nowrap">
                        AI Insight
                    </span>
                    <span class="text-xs md:text-sm font-medium text-slate-700 dark:text-slate-300 leading-relaxed">
                        {{ $aiInsightText }}
                    </span>
                </div>
            </div>

            <button 
                type="button" 
                wire:click="dismissAiInsight" 
                class="text-slate-400 hover:text-slate-600 p-1 rounded-md transition shrink-0 ml-4 cursor-pointer"
                title="Dismiss insight"
            >
                <x-heroicon-m-x-mark class="w-4 h-4" />
            </button>
        </div>
    @endif

    <script>
        function jumpToSection(sectionId) {
            const el = document.getElementById(sectionId);
            if (!el) return;
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            el.classList.add('ring-2', 'ring-blue-500', 'ring-offset-4', 'transition-all', 'duration-500');
            setTimeout(() => {
                el.classList.remove('ring-2', 'ring-blue-500', 'ring-offset-4');
            }, 2000);
        }
    </script>
</x-filament-panels::page>
