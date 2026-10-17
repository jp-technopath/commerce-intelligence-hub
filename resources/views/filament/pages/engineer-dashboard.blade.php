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
                    <span class="w-2 h-2 rounded-full {{ $lastSyncedAt ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600' }}"></span>
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

            {{-- Date Selector Pill --}}
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3.5 py-1.5 shadow-xs flex items-center gap-2 cursor-pointer hover:border-slate-300 transition">
                <span class="text-xs font-medium text-slate-700 dark:text-slate-200">
                    {{ now()->format('D, M j, Y') }}
                </span>
                <x-heroicon-m-chevron-down class="w-3.5 h-3.5 text-slate-400" />
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
                    <div class="w-8 h-8 rounded-full overflow-hidden shrink-0 border border-slate-200 dark:border-slate-700 bg-gradient-to-tr from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-xs shadow-xs">
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
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.02);
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
            padding: 0.35rem 0.6rem;
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
            background: #f1f5f9;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }
        .dark .compact-cmd-chip:hover {
            background: #334155;
            border-color: #475569;
        }
        .compact-spotlight-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 0.875rem;
            padding: 0.75rem 1rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.02);
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
            border-color: #93c5fd;
            box-shadow: 0 4px 12px -2px rgba(59, 130, 246, 0.1);
            transform: translateY(-1px);
        }
        .dark .compact-spotlight-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px -2px rgba(59, 130, 246, 0.25);
        }
    </style>

    {{-- TOP DASHBOARD SUMMARY & NEXT DUE ITEM COMMAND BAR --}}
    <div class="compact-cmd-grid">
        {{-- Card 1: Today at a Glance & Quick-Jump Counters --}}
        <div class="compact-cmd-card">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-400 shrink-0">
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
                    class="compact-cmd-chip"
                    title="Jump to Work Plan"
                >
                    <div class="min-w-0">
                        <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider leading-none">Work Plan</div>
                        <div class="text-sm font-extrabold text-slate-900 dark:text-slate-100 leading-tight mt-0.5">{{ count($workPlanItems) }}</div>
                    </div>
                    <span class="text-[11px] text-slate-400">&darr;</span>
                </button>

                <button 
                    type="button" 
                    onclick="jumpToSection('section-needs-attention')"
                    class="compact-cmd-chip {{ count($needsAttentionItems) > 0 ? 'border-rose-200 bg-rose-50/70 dark:bg-rose-950/30' : '' }}"
                    title="Jump to Needs Attention"
                >
                    <div class="min-w-0">
                        <div class="text-[10px] uppercase font-bold {{ count($needsAttentionItems) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-400' }} tracking-wider leading-none">Attention</div>
                        <div class="text-sm font-extrabold {{ count($needsAttentionItems) > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100' }} leading-tight mt-0.5">{{ count($needsAttentionItems) }}</div>
                    </div>
                    <span class="text-[11px] {{ count($needsAttentionItems) > 0 ? 'text-rose-500' : 'text-slate-400' }}">&darr;</span>
                </button>

                <button 
                    type="button" 
                    onclick="jumpToSection('section-upcoming-meetings')"
                    class="compact-cmd-chip {{ count($upcomingMeetings) > 0 ? 'border-amber-200 bg-amber-50/70 dark:bg-amber-950/30' : '' }}"
                    title="Jump to Upcoming Meetings"
                >
                    <div class="min-w-0">
                        <div class="text-[10px] uppercase font-bold {{ count($upcomingMeetings) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400' }} tracking-wider leading-none">Meetings</div>
                        <div class="text-sm font-extrabold {{ count($upcomingMeetings) > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-slate-900 dark:text-slate-100' }} leading-tight mt-0.5">{{ count($upcomingMeetings) }}</div>
                    </div>
                    <span class="text-[11px] {{ count($upcomingMeetings) > 0 ? 'text-amber-500' : 'text-slate-400' }}">&darr;</span>
                </button>

                <button 
                    type="button" 
                    onclick="jumpToSection('section-hours')"
                    class="compact-cmd-chip"
                    title="Jump to Hours"
                >
                    <div class="min-w-0">
                        <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider leading-none">Hours</div>
                        <div class="text-sm font-extrabold text-slate-900 dark:text-slate-100 leading-tight mt-0.5">{{ $hoursData['total_hours'] }}h</div>
                    </div>
                    <span class="text-[11px] text-slate-400">&darr;</span>
                </button>
            </div>
        </div>

        {{-- Card 2: Next Due Item Spotlight --}}
        <div 
            onclick="jumpToSection('{{ $nextDueItem['target_id'] }}')"
            class="compact-spotlight-card group"
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

            <div class="flex items-center justify-between pt-1 border-t border-slate-100 dark:border-slate-800 text-xs">
                <span class="font-semibold text-blue-600 dark:text-blue-400 group-hover:underline inline-flex items-center gap-1">
                    <span>{{ $nextDueItem['action_label'] }}</span>
                    <span class="transition-transform group-hover:translate-y-0.5">&darr;</span>
                </span>
                <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">
                    Next Due Item
                </span>
            </div>
        </div>
    </div>

    {{-- SECTION 1: My Recommended Work Plan --}}
    <div id="section-work-plan" class="scroll-mt-6 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl p-6 shadow-xs">
        <div class="flex items-center gap-3 mb-4">
            <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                1. My Recommended Work Plan
            </h2>
            <span class="bg-[#F3E8FF] text-[#7E22CE] dark:bg-purple-950/70 dark:text-purple-300 font-medium text-xs px-3 py-1 rounded-full flex items-center gap-1.5">
                AI prioritized for today
            </span>
        </div>

        <div class="overflow-x-auto -mx-6 px-6">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-100 dark:border-slate-800">
                        <th class="py-2.5 px-3 w-12">Oor</th>
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
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 
                                        {{ $item['icon_type'] === 'pr' ? 'bg-amber-50 text-amber-600' : ($item['icon_type'] === 'search' ? 'bg-purple-50 text-purple-600' : 'bg-blue-50 text-blue-600') }}">
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
                                                class="text-xs text-slate-400 hover:text-blue-600 font-medium mt-0.5 inline-block transition"
                                            >
                                                {{ $item['key'] }}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            {{-- Project --}}
                            <td class="py-3 px-3 align-middle text-xs md:text-sm font-medium text-slate-700 dark:text-slate-300">
                                {{ $item['project'] }}
                            </td>

                            {{-- Priority Pill --}}
                            <td class="py-3 px-3 align-middle">
                                <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full border {{ $item['priority_class'] }}">
                                    {{ $item['priority'] }}
                                </span>
                            </td>

                            {{-- Est. Time --}}
                            <td class="py-3 px-3 align-middle text-xs md:text-sm text-slate-600 dark:text-slate-400">
                                {{ $item['est_time'] }}
                            </td>

                            {{-- Why this matters --}}
                            <td class="py-3 px-3 align-middle text-xs text-slate-600 dark:text-slate-400 leading-relaxed max-w-sm">
                                {{ $item['why_matters'] }}
                            </td>

                            {{-- Action: Start Button linking to Jira --}}
                            <td class="py-3 px-3 align-middle text-right">
                                <a 
                                    href="{{ $item['jira_url'] }}" 
                                    target="_blank" 
                                    rel="noopener noreferrer"
                                    class="bg-[#2563EB] hover:bg-[#1D4ED8] text-white font-semibold text-xs px-4 py-1.5 rounded-lg shadow-2xs transition inline-flex items-center justify-center"
                                >
                                    Start
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
                    <div class="flex items-center gap-2.5">
                        <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                            2. Needs My Attention
                        </h2>
                        <span class="{{ count($needsAttentionItems) > 0 ? 'bg-rose-100 text-rose-700' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400' }} font-semibold text-xs px-2.5 py-0.5 rounded-full">
                            {{ count($needsAttentionItems) }} {{ count($needsAttentionItems) === 1 ? 'item' : 'items' }}
                        </span>
                    </div>
                    <a href="#" class="text-blue-600 hover:underline text-xs font-semibold">
                        View all
                    </a>
                </div>

                <div class="divide-y divide-slate-50 dark:divide-slate-800/60">
                    @forelse ($needsAttentionItems as $item)
                        <div class="py-3 flex items-center justify-between gap-3 group">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 
                                    {{ $item['icon'] === 'alert' || $item['priority'] === 'High' ? 'bg-rose-50 text-rose-500' : ($item['icon'] === 'meeting' ? 'bg-blue-50 text-blue-500' : 'bg-amber-50 text-amber-500') }}">
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
                                <div>
                                    <div class="font-bold text-xs md:text-sm text-slate-900 dark:text-slate-100">
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
                                            class="text-[11px] font-medium text-slate-400 hover:text-blue-600 mt-0.5 inline-block transition"
                                        >
                                            {{ $item['key'] }}
                                        </a>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-2.5 shrink-0">
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full border {{ $item['priority_class'] }}">
                                    {{ $item['priority'] }}
                                </span>
                                @if (!empty($item['jira_url']))
                                    <a href="{{ $item['jira_url'] }}" target="_blank" rel="noopener noreferrer">
                                        <x-heroicon-m-chevron-right class="w-4 h-4 text-slate-400 hover:text-slate-600 transition" />
                                    </a>
                                @else
                                    <x-heroicon-m-chevron-right class="w-4 h-4 text-slate-400" />
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
        <div id="section-hours" class="scroll-mt-6 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                        3. My Hours This Month
                    </h2>
                    <div class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 shadow-2xs cursor-pointer hover:bg-slate-50">
                        <span>Track time</span>
                        <x-heroicon-m-chevron-down class="w-3.5 h-3.5 text-slate-400" />
                    </div>
                </div>

                {{-- Big Stat and Allocation --}}
                <div class="flex items-baseline mb-3">
                    <span class="text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight">
                        {{ $hoursData['total_hours'] }}h
                    </span>
                    <span class="text-xs md:text-sm font-medium text-slate-400 ml-2">
                        of {{ $hoursData['allocated_hours'] }}h allocation
                    </span>
                </div>

                {{-- Full-width Progress Bar --}}
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-full bg-slate-100 dark:bg-slate-800 h-2.5 rounded-full overflow-hidden flex-1">
                        <div 
                            class="bg-[#2563EB] h-2.5 rounded-full transition-all duration-500" 
                            style="width: {{ $hoursData['progress_pct'] }}%"
                        ></div>
                    </div>
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300 shrink-0">
                        {{ $hoursData['progress_pct'] }}%
                    </span>
                </div>

                {{-- Customer Breakdown Table --}}
                <div class="mb-4">
                    <div class="grid grid-cols-12 text-[11px] font-semibold text-slate-400 uppercase tracking-wider py-1.5 border-b border-slate-100 dark:border-slate-800">
                        <div class="col-span-5">Customer</div>
                        <div class="col-span-2 text-right">Hours</div>
                        <div class="col-span-2 text-right">Allocation</div>
                        <div class="col-span-3 text-right">%</div>
                    </div>
                    <div class="divide-y divide-slate-50 dark:divide-slate-800/60">
                        @forelse ($hoursData['customers'] as $c)
                            <div class="grid grid-cols-12 items-center py-2 text-xs">
                                <div class="col-span-5 flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-xs shrink-0" style="background-color: {{ $c['color'] }}"></span>
                                    <span class="font-medium text-slate-800 dark:text-slate-200 truncate">
                                        {{ $c['name'] }}
                                    </span>
                                </div>
                                <div class="col-span-2 text-right font-bold text-slate-900 dark:text-slate-100">
                                    {{ $c['hours'] }}
                                </div>
                                <div class="col-span-2 text-right text-slate-500 dark:text-slate-400">
                                    {{ $c['allocation'] }}
                                </div>
                                <div class="col-span-3 flex items-center justify-end gap-2">
                                    <span class="font-semibold text-slate-700 dark:text-slate-300 w-8 text-right">
                                        {{ $c['pct'] }}%
                                    </span>
                                    <div class="w-12 bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden shrink-0">
                                        <div 
                                            class="{{ $c['bar_color'] }} h-1.5 rounded-full" 
                                            style="width: {{ min(100, $c['pct']) }}%"
                                        ></div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="py-4 text-center text-xs text-slate-400">
                                No customer spaces tracked yet this month.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Missing Time Info Card --}}
            <div class="bg-[#F0F7FF] dark:bg-blue-950/30 border border-blue-100 dark:border-blue-900/60 rounded-xl p-3.5 flex items-start gap-3 mt-2">
                <x-heroicon-m-information-circle class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div>
                    <div class="text-xs text-slate-700 dark:text-slate-300 leading-relaxed">
                        You have {{ $hoursData['missing_time_count'] }} tasks completed with no time entries. Consider adding time to keep your hours up to date.
                    </div>
                    <a href="#" class="text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline mt-1.5 inline-flex items-center gap-1">
                        <span>View missing time entries</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- LOWER GRID: Upcoming Meetings & My Project Health --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- SECTION 4: Upcoming Meetings --}}
        <div id="section-upcoming-meetings" class="scroll-mt-6 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2.5">
                        <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                            4. Upcoming Meetings
                        </h2>
                        <span class="{{ count($upcomingMeetings) > 0 ? 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }} font-semibold text-xs px-2.5 py-0.5 rounded-full">
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
                                    {{ ($meeting['action_type'] ?? '') === 'prep' ? 'bg-blue-50 text-blue-600 border-blue-100 dark:bg-blue-950/60 dark:text-blue-400 dark:border-blue-900' : 'bg-purple-50 text-purple-600 border-purple-100 dark:bg-purple-950/60 dark:text-purple-400 dark:border-purple-900' }}">
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
                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full border
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

                            <a
                                href="{{ $meeting['action_url'] }}"
                                class="inline-flex items-center gap-1.5 {{ ($meeting['action_type'] ?? '') === 'prep' ? 'bg-[#EFF6FF] hover:bg-blue-100 text-[#2563EB] border-blue-200 dark:bg-blue-950/60 dark:hover:bg-blue-900 dark:text-blue-300 dark:border-blue-800' : 'bg-[#F5F3FF] hover:bg-purple-100 text-[#7C3AED] border-purple-200 dark:bg-purple-950/60 dark:hover:bg-purple-900 dark:text-purple-300 dark:border-purple-800' }} border text-xs font-semibold px-3 py-1.5 rounded-lg whitespace-nowrap shadow-2xs transition shrink-0"
                            >
                                @if (($meeting['action_type'] ?? '') === 'prep')
                                    <x-heroicon-m-document-text class="w-3.5 h-3.5 shrink-0" />
                                @else
                                    <x-heroicon-m-clipboard-document-check class="w-3.5 h-3.5 shrink-0" />
                                @endif
                                <span>{{ $meeting['action_label'] }}</span>
                            </a>
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
                    <div class="flex items-center gap-2.5">
                        <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                            5. My Project Health
                        </h2>
                        <span class="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 font-semibold text-xs px-2.5 py-0.5 rounded-full">
                            {{ count($projectHealthItems) }} projects
                        </span>
                    </div>
                    <a href="#" class="text-blue-600 hover:underline text-xs font-semibold">
                        View all
                    </a>
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
                                    <td class="py-3 px-3 font-semibold text-sm text-slate-900 dark:text-slate-100">
                                        {{ $p['name'] }}
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full border {{ $p['health_class'] }}">
                                            {{ $p['health'] }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 text-xs {{ $p['is_urgent'] ? 'font-bold text-rose-600' : 'text-slate-600 dark:text-slate-400' }}">
                                        {{ $p['upcoming'] }}
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <div class="inline-flex items-center gap-1 text-xs font-medium text-slate-600 dark:text-slate-400 hover:text-blue-600 cursor-pointer">
                                            <span>{{ $p['tasks_count'] }}</span>
                                            <x-heroicon-m-chevron-right class="w-3.5 h-3.5 text-slate-400" />
                                        </div>
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
        <div class="bg-[#FAF5FF] dark:bg-purple-950/20 border border-purple-200/90 dark:border-purple-900/60 rounded-2xl p-4 flex items-center justify-between shadow-2xs transition">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-300 flex items-center justify-center shrink-0">
                    <x-heroicon-m-sparkles class="w-5 h-5" />
                </div>
                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3">
                    <span class="text-sm font-bold text-purple-900 dark:text-purple-200 whitespace-nowrap">
                        AI Insight
                    </span>
                    <span class="text-xs md:text-sm text-slate-700 dark:text-slate-300 leading-relaxed">
                        {{ $aiInsightText }}
                    </span>
                </div>
            </div>

            <button 
                type="button" 
                wire:click="dismissAiInsight" 
                class="text-slate-400 hover:text-slate-600 p-1 rounded-md transition shrink-0 ml-4"
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
