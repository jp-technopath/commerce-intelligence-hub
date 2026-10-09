@php
    $plan = $this->getPlanData();
    $recommended = $plan['recommended_order'] ?? [];
    $needsAttention = $plan['needs_attention'] ?? [];
    $meetingPrep = $plan['meeting_prep'] ?? [];
    $isSyncStale = $plan['is_sync_stale'] ?? false;
    $lastSynced = $plan['last_synced_at'] ?? 'Unknown';
    $generatedAt = $plan['generated_at'] ?? '';
@endphp

<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-6 space-y-6">
    {{-- Header & Freshness Controls --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-200 dark:border-gray-800">
        <div class="space-y-1">
            <div class="flex items-center gap-2">
                <div class="p-2 bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 rounded-lg">
                    <x-heroicon-m-sparkles class="w-5 h-5" />
                </div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    <span>AI Daily Prioritization & Focus</span>
                    @if ($this->getTargetUser() && $this->getTargetUser()->id !== auth()->id())
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-50 dark:bg-primary-950 text-primary-700 dark:text-primary-300 border border-primary-200 dark:border-primary-800">
                            Viewing {{ $this->getTargetUser()->name }}
                        </span>
                    @endif
                </h2>
            </div>
            <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span>Calculated: {{ \Carbon\Carbon::parse($generatedAt)->format('g:i A') }}</span>
                <span>•</span>
                <span class="flex items-center gap-1">
                    PM Sync: <strong class="{{ $isSyncStale ? 'text-amber-500' : 'text-gray-700 dark:text-gray-300' }}">{{ $lastSynced }}</strong>
                    @if ($isSyncStale)
                        <span class="px-1.5 py-0.5 text-[10px] bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 rounded">Stale</span>
                    @endif
                </span>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                wire:click="refreshWorkPlan"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950 hover:bg-primary-100 dark:hover:bg-primary-900/60 rounded-lg border border-primary-200 dark:border-primary-800 transition"
            >
                <x-heroicon-m-arrow-path wire:loading.class="animate-spin" class="w-4 h-4" />
                <span>Recalculate Priorities</span>
            </button>
        </div>
    </div>

    {{-- Navigation Tabs --}}
    <div class="flex border-b border-gray-200 dark:border-gray-800 space-x-6 text-sm font-medium">
        <button
            type="button"
            wire:click="$set('activeTab', 'plan')"
            class="pb-3 border-b-2 flex items-center gap-2 transition {{ ($activeTab ?? 'plan') === 'plan' ? 'border-primary-600 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' }}"
        >
            <span>Executable Plan</span>
            <span class="px-2 py-0.5 text-xs rounded-full bg-primary-100 dark:bg-primary-950 text-primary-700 dark:text-primary-300">
                {{ count($recommended) }}
            </span>
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'attention')"
            class="pb-3 border-b-2 flex items-center gap-2 transition {{ ($activeTab ?? 'plan') === 'attention' ? 'border-rose-600 text-rose-600 dark:text-rose-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' }}"
        >
            <span>Needs My Attention</span>
            @if (count($needsAttention) > 0)
                <span class="px-2 py-0.5 text-xs rounded-full bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 font-bold">
                    {{ count($needsAttention) }}
                </span>
            @endif
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'meetings')"
            class="pb-3 border-b-2 flex items-center gap-2 transition {{ ($activeTab ?? 'plan') === 'meetings' ? 'border-amber-600 text-amber-600 dark:text-amber-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' }}"
        >
            <span>Meeting Readiness</span>
            @if (count($meetingPrep) > 0)
                <span class="px-2 py-0.5 text-xs rounded-full bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">
                    {{ count($meetingPrep) }}
                </span>
            @endif
        </button>
    </div>

    {{-- TAB 1: Executable Recommended Plan --}}
    @if (($activeTab ?? 'plan') === 'plan')
        @if (empty($recommended))
            <div class="text-center py-10 bg-gray-50 dark:bg-gray-800/40 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
                <x-heroicon-o-check-circle class="w-12 h-12 text-emerald-500 mx-auto mb-2" />
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">No Pending Executable Tasks</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 max-w-sm mx-auto mt-1">
                    You have no active or unblocked tasks waiting in your queue. Check 'Needs My Attention' or your PM backlog.
                </p>
            </div>
        @else
            <div class="space-y-4">
                @foreach ($recommended as $index => $item)
                    <div class="border border-gray-200 dark:border-gray-800 hover:border-primary-400 dark:hover:border-primary-600 rounded-xl p-4 transition-all bg-gray-50/50 dark:bg-gray-800/30 flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="space-y-2 flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="px-2 py-0.5 text-xs font-bold rounded-md bg-primary-100 dark:bg-primary-900/60 text-primary-700 dark:text-primary-300">
                                    #{{ $index + 1 }} • Priority Score: {{ $item['score'] }}
                                </span>
                                @if (! empty($item['jira_url']))
                                    <a href="{{ $item['jira_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-mono font-semibold text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1">
                                        {{ $item['key'] }}
                                        <x-heroicon-m-arrow-top-right-on-square class="w-3 h-3 opacity-60" />
                                    </a>
                                @else
                                    <span class="text-xs font-mono font-semibold text-gray-600 dark:text-gray-300">
                                        {{ $item['key'] }}
                                    </span>
                                @endif
                                <span class="px-2 py-0.5 text-[11px] rounded bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                                    {{ $item['client_name'] }}
                                </span>
                                <span class="px-2 py-0.5 text-[11px] rounded font-medium
                                    {{ strtolower($item['priority'] ?? '') === 'critical' || strtolower($item['priority'] ?? '') === 'highest' ? 'bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300' : 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300' }}">
                                    {{ $item['priority'] }}
                                </span>
                                @if ($item['is_overdue'])
                                    <span class="px-2 py-0.5 text-[11px] rounded bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300 font-bold">
                                        Overdue
                                    </span>
                                @endif
                            </div>

                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 truncate">
                                @if (! empty($item['jira_url']))
                                    <a href="{{ $item['jira_url'] }}" target="_blank" rel="noopener noreferrer" class="hover:text-primary-600 dark:hover:text-primary-400 transition">
                                        {{ $item['title'] }}
                                    </a>
                                @else
                                    {{ $item['title'] }}
                                @endif
                            </h3>

                            <div class="flex flex-wrap items-center gap-y-1 gap-x-4 text-xs text-gray-500 dark:text-gray-400">
                                <div>Status: <strong class="text-gray-700 dark:text-gray-300">{{ $item['delivery_status'] }}</strong></div>
                                <div>Est / Spent: <strong class="text-gray-700 dark:text-gray-300">{{ $item['estimated_hours'] }}h / {{ $item['time_spent_hours'] }}h</strong></div>
                                @if ($item['target_due_date'])
                                    <div>Due: <strong class="text-gray-700 dark:text-gray-300">{{ $item['target_due_date'] }}</strong></div>
                                @endif
                            </div>

                            <div class="p-2.5 rounded-lg bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 text-xs space-y-1">
                                <div class="text-gray-600 dark:text-gray-300">
                                    <span class="font-semibold text-primary-600 dark:text-primary-400">Why this now:</span>
                                    {{ $item['reasoning'] }}
                                </div>
                                <div class="text-gray-600 dark:text-gray-300">
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">Suggested Action:</span>
                                    {{ $item['suggested_next_step'] }}
                                </div>
                            </div>
                        </div>

                        <div class="flex sm:flex-col items-center justify-end gap-2 shrink-0">
                            @if (! empty($item['jira_url']))
                                <a
                                    href="{{ $item['jira_url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="w-full inline-flex justify-center items-center gap-1.5 px-3 py-2 text-xs font-semibold text-primary-700 dark:text-primary-300 bg-primary-50 dark:bg-primary-950/60 hover:bg-primary-100 dark:hover:bg-primary-900/80 rounded-lg border border-primary-200 dark:border-primary-800 transition shadow-sm"
                                >
                                    <x-heroicon-m-arrow-top-right-on-square class="w-4 h-4" />
                                    <span>Open Jira</span>
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- TAB 2: Needs My Attention (Strictly Blocked / Missing Time Logs) --}}
    @if (($activeTab ?? 'plan') === 'attention')
        @if (empty($needsAttention))
            <div class="text-center py-10 bg-gray-50 dark:bg-gray-800/40 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
                <x-heroicon-o-shield-check class="w-12 h-12 text-emerald-500 mx-auto mb-2" />
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Zero Blockers or Unresolved Bottlenecks</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 max-w-sm mx-auto mt-1">
                    No tasks are currently waiting on external dependencies, blocker clearance, or missing time tracking.
                </p>
            </div>
        @else
            <div class="space-y-4">
                <div class="p-3 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-lg text-xs text-rose-800 dark:text-rose-300">
                    <strong>Isolated from Executable Plan:</strong> The items below are blocked or unready for active development. Resolve blockers or log missing hours to return them to your priority flow.
                </div>

                @foreach ($needsAttention as $item)
                    <div class="border border-rose-200 dark:border-rose-900/60 bg-rose-50/20 dark:bg-rose-950/10 rounded-xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="space-y-1.5 flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 text-xs font-bold rounded bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300">
                                    Blocked / Attention
                                </span>
                                @if (! empty($item['jira_url']))
                                    <a href="{{ $item['jira_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-mono font-semibold text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1">
                                        {{ $item['key'] }}
                                        <x-heroicon-m-arrow-top-right-on-square class="w-3 h-3 opacity-60" />
                                    </a>
                                @else
                                    <span class="text-xs font-mono font-semibold text-gray-600 dark:text-gray-300">
                                        {{ $item['key'] }}
                                    </span>
                                @endif
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $item['client_name'] }}
                                </span>
                            </div>

                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                @if (! empty($item['jira_url']))
                                    <a href="{{ $item['jira_url'] }}" target="_blank" rel="noopener noreferrer" class="hover:text-primary-600 dark:hover:text-primary-400 transition">
                                        {{ $item['title'] }}
                                    </a>
                                @else
                                    {{ $item['title'] }}
                                @endif
                            </h3>

                            <p class="text-xs text-rose-700 dark:text-rose-400">
                                <strong>Blocker / Issue:</strong> {{ $item['reason'] }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            @if (! empty($item['jira_url']))
                                <a
                                    href="{{ $item['jira_url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-700 transition"
                                >
                                    <x-heroicon-m-arrow-top-right-on-square class="w-3.5 h-3.5" />
                                    <span>Jira</span>
                                </a>
                            @endif

                            @if (str_contains(strtolower($item['action_label'] ?? ''), 'blocker'))
                                <button
                                    type="button"
                                    wire:click="clearBlocker({{ $item['item_id'] }})"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950 hover:bg-emerald-100 rounded-lg border border-emerald-300 dark:border-emerald-800 transition"
                                >
                                    <x-heroicon-m-check class="w-4 h-4" />
                                    <span>Unblock Task</span>
                                </button>
                            @endif
                        </div>
                    </div>

                @endforeach
            </div>
        @endif
    @endif

    {{-- TAB 3: Meeting Readiness --}}
    @if (($activeTab ?? 'plan') === 'meetings')
        @if (empty($meetingPrep))
            <div class="text-center py-10 bg-gray-50 dark:bg-gray-800/40 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
                <x-heroicon-o-calendar-days class="w-12 h-12 text-blue-500 mx-auto mb-2" />
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">All Upcoming Meetings Prepared</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 max-w-sm mx-auto mt-1">
                    No client meetings scheduled in the next 48 hours require preparation or briefing draft review.
                </p>
            </div>
        @else
            <div class="space-y-4">
                @foreach ($meetingPrep as $m)
                    <div class="border border-amber-200 dark:border-amber-900/60 bg-amber-50/20 dark:bg-amber-950/10 rounded-xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="space-y-1 flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                                    Stage: {{ ucfirst(str_replace('_', ' ', $m['prep_stage'])) }}
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $m['client_name'] }}
                                </span>
                            </div>

                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                {{ $m['title'] }}
                            </h3>

                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Scheduled: <strong class="text-gray-700 dark:text-gray-300">{{ $m['meeting_start_at'] }}</strong>
                            </p>
                        </div>

                        <div class="shrink-0">
                            <a
                                href="{{ url('/admin/client-meetings/' . $m['meeting_id']) }}"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950 hover:bg-amber-100 rounded-lg border border-amber-300 dark:border-amber-800 transition"
                            >
                                <x-heroicon-m-document-text class="w-4 h-4" />
                                <span>{{ $m['action_label'] }}</span>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
