@php
    use App\Services\MeetingAgent\MeetingContentPresenter;

    $meetLink = $meeting->metadata['meet_link'] ?? null;
    $htmlLink = $meeting->metadata['html_link'] ?? null;
    $isUpcoming = $meeting->meeting_start_at && $meeting->meeting_start_at >= now();

    $agenda = MeetingContentPresenter::parseAgenda($meeting->prep?->recommended_agenda);
    $prepSummary = MeetingContentPresenter::parseInternalSummary($meeting->prep?->internal_summary);
    $decisions = MeetingContentPresenter::parseDecisions($meeting->followUp?->decisions);
    $actionItemsCount = $meeting->actionItems->count();
    $hasClientEmail = !empty($meeting->prep?->edited_status_email_body ?? $meeting->prep?->generated_status_email_body);

    $defaultTab = ($agenda['count'] > 0 || $prepSummary['has_content']) 
        ? 'agenda' 
        : ($actionItemsCount > 0 ? 'actions' : ($meeting->followUp ? 'summary' : 'agenda'));
@endphp

<div x-data="{ activeTab: '{{ $defaultTab }}' }" class="space-y-4">
    {{-- Video Meeting Link Callout --}}
    @if($meetLink || $htmlLink)
        <div class="rounded-xl border border-emerald-200 dark:border-emerald-800/60 bg-emerald-50/80 dark:bg-emerald-950/30 p-4 transition shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="p-2.5 rounded-lg bg-emerald-100 dark:bg-emerald-900/60 text-emerald-600 dark:text-emerald-400 mt-0.5 sm:mt-0 shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-emerald-900 dark:text-emerald-200">Video Meeting Link</span>
                            @if($isUpcoming)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-200/80 dark:bg-emerald-800 text-emerald-800 dark:text-emerald-200">
                                    Ready to Join
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-emerald-700 dark:text-emerald-300/80 mt-0.5 break-all font-mono select-all">
                            {{ $meetLink ?? $htmlLink }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 self-start sm:self-center shrink-0">
                    @if($meetLink)
                        <a href="{{ $meetLink }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold shadow-sm transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                            Join Meeting
                        </a>
                    @endif
                    @if($htmlLink && $htmlLink !== $meetLink)
                        <a href="{{ $htmlLink }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 hover:bg-emerald-100/50 dark:hover:bg-emerald-900/40 text-xs font-medium transition">
                            📅 Calendar Event
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/40 p-3.5 text-xs text-gray-500 dark:text-gray-400 flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            No video link is configured for this sync. Please check calendar invites or contact your host.
        </div>
    @endif

    {{-- Metadata Overview Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="p-3.5 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/40">
            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date & Time</div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 mt-1">
                {{ $meeting->meeting_start_at ? $meeting->meeting_start_at->format('M d, Y') : '—' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {{ $meeting->meeting_start_at ? $meeting->meeting_start_at->format('g:i A T') : '' }}
            </div>
        </div>

        <div class="p-3.5 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/40">
            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Host & Organizer</div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 mt-1">
                👤 {{ $meeting->owner?->name ?? 'Technopath Team' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
                {{ $meeting->metadata['organizer_email'] ?? $meeting->owner?->email ?? '' }}
            </div>
        </div>

        <div class="p-3.5 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/40">
            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Sync Status</div>
            <div class="mt-1 flex items-center gap-2">
                @if($isUpcoming)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                        🟢 Upcoming Sync
                    </span>
                @else
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300">
                        ⚪ Past Sync
                    </span>
                @endif
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                📌 {{ $actionItemsCount }} Action Items
            </div>
        </div>
    </div>

    {{-- Tab Navigation Bar --}}
    @if($agenda['count'] > 0 || $prepSummary['has_content'] || $actionItemsCount > 0 || $meeting->followUp || $hasClientEmail)
        <div class="flex items-center gap-1.5 p-1 rounded-xl bg-gray-100/90 dark:bg-gray-800/90 border border-gray-200/80 dark:border-gray-700/80 text-xs font-semibold overflow-x-auto shadow-sm">
            @if($agenda['count'] > 0 || $prepSummary['has_content'])
                <button type="button" 
                    @click="activeTab = 'agenda'" 
                    :class="activeTab === 'agenda' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    <span>📋</span>
                    <span>Agenda & Overview</span>
                    @if($agenda['count'] > 0)
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-primary-100 dark:bg-primary-950 text-primary-700 dark:text-primary-300 font-bold">
                            {{ $agenda['count'] }}
                        </span>
                    @endif
                </button>

                @if(!empty($prepSummary['sections']))
                    <button type="button" 
                        @click="activeTab = 'tickets'" 
                        :class="activeTab === 'tickets' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                        <span>🚀</span>
                        <span>Ticket Breakdown</span>
                        @if($prepSummary['health']['total_tickets'] ?? null)
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 font-bold">
                                {{ $prepSummary['health']['total_tickets'] }}
                            </span>
                        @endif
                    </button>
                @endif
            @endif

            @if($actionItemsCount > 0)
                <button type="button" 
                    @click="activeTab = 'actions'" 
                    :class="activeTab === 'actions' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    <span>📌</span>
                    <span>Action Items</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-200 font-bold">
                        {{ $actionItemsCount }}
                    </span>
                </button>
            @endif

            @if($hasClientEmail)
                <button type="button" 
                    @click="activeTab = 'email'" 
                    :class="activeTab === 'email' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    <span>✉️</span>
                    <span>Client Status Email</span>
                </button>
            @endif

            @if($meeting->followUp)
                <button type="button" 
                    @click="activeTab = 'summary'" 
                    :class="activeTab === 'summary' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    <span>📝</span>
                    <span>Decisions & Follow-Up</span>
                </button>
            @endif

            <button type="button" 
                @click="activeTab = 'all'" 
                :class="activeTab === 'all' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 ml-auto cursor-pointer">
                <span>📄</span>
                <span>View All</span>
            </button>
        </div>
    @endif

    {{-- TAB 1: Agenda & Overview --}}
    <div x-show="activeTab === 'agenda' || activeTab === 'all'" class="space-y-4">
        {{-- Recommended Agenda Card --}}
        @if($agenda['count'] > 0)
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-gray-100 dark:border-gray-800">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <span>📋 Meeting Agenda &amp; Schedule</span>
                    </h4>
                    <div class="flex items-center gap-2">
                        @if($agenda['total_duration'])
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/60">
                                ⏱ ~{{ $agenda['total_duration'] }}
                            </span>
                        @endif
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                            {{ $agenda['count'] }} {{ \Illuminate\Support\Str::plural('topic', $agenda['count']) }}
                        </span>
                    </div>
                </div>

                <div class="space-y-2">
                    @foreach($agenda['items'] as $item)
                        <div class="flex items-start justify-between gap-3 p-3 rounded-lg border border-gray-100 dark:border-gray-800/80 bg-gray-50/40 dark:bg-gray-800/30 hover:bg-gray-50 dark:hover:bg-gray-800/60 transition">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-950/80 text-primary-700 dark:text-primary-300 font-bold text-xs shrink-0 mt-0.5">
                                    {{ $item['number'] }}
                                </span>
                                <div class="min-w-0">
                                    <h5 class="text-xs font-bold text-gray-900 dark:text-gray-100 leading-snug">
                                        {{ $item['title'] }}
                                    </h5>
                                    @if($item['detail'])
                                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 leading-relaxed">
                                            {{ $item['detail'] }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            @if($item['duration'])
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/50 shrink-0 self-start">
                                    ⏱ {{ $item['duration'] }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Project Health Card --}}
        @if($prepSummary['health'])
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-gray-800">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <span>🏥 Project Health Assessment</span>
                    </h4>
                    <div class="flex items-center gap-2">
                        @php
                            $healthColor = $prepSummary['health']['color'];
                            $colorClasses = match($healthColor) {
                                'success' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
                                'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border-amber-200 dark:border-amber-800',
                                'danger'  => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border-rose-200 dark:border-rose-800',
                                default   => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700',
                            };
                            $dotColor = match($healthColor) {
                                'success' => 'bg-emerald-500',
                                'warning' => 'bg-amber-500',
                                'danger'  => 'bg-rose-500',
                                default   => 'bg-gray-400',
                            };
                        @endphp
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border {{ $colorClasses }}">
                            <span class="w-2 h-2 rounded-full {{ $dotColor }} animate-pulse"></span>
                            {{ $prepSummary['health']['status'] }}
                        </span>
                    </div>
                </div>

                {{-- Ticket Breakdown Pills --}}
                @if(!empty($prepSummary['health']['ticket_breakdown']))
                    <div class="flex flex-wrap items-center gap-1.5 mt-3 pt-1">
                        @foreach($prepSummary['health']['ticket_breakdown'] as $stat)
                            @php
                                $badgeStyle = 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700';
                                if (stripos($stat, 'Done') !== false || stripos($stat, 'Closed') !== false) {
                                    $badgeStyle = 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200/80 dark:border-emerald-800/60 font-semibold';
                                } elseif (stripos($stat, 'QA') !== false || stripos($stat, 'Staging') !== false || stripos($stat, 'Review') !== false) {
                                    $badgeStyle = 'bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300 border-purple-200/80 dark:border-purple-800/60 font-semibold';
                                } elseif (stripos($stat, 'In Progress') !== false || stripos($stat, 'Dev') !== false) {
                                    $badgeStyle = 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300 border-blue-200/80 dark:border-blue-800/60 font-semibold';
                                } elseif (stripos($stat, 'Hold') !== false || stripos($stat, 'Block') !== false) {
                                    $badgeStyle = 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border-amber-200/80 dark:border-amber-800/60 font-semibold';
                                } elseif (stripos($stat, 'Deploy') !== false) {
                                    $badgeStyle = 'bg-teal-50 text-teal-700 dark:bg-teal-950/40 dark:text-teal-300 border-teal-200/80 dark:border-teal-800/60 font-semibold';
                                }
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] border {{ $badgeStyle }}">
                                {{ $stat }}
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- Health Details --}}
                <div class="mt-3 text-xs text-gray-700 dark:text-gray-300 leading-relaxed font-normal">
                    {{ $prepSummary['health']['details'] }}
                </div>
            </div>
        @endif

        {{-- Quick Customer Input Banner in Tab 1 if present --}}
        @php
            $customerInputSec = collect($prepSummary['sections'])->firstWhere('key', 'customer_input');
        @endphp
        @if($customerInputSec)
            <div class="rounded-xl border border-indigo-200 dark:border-indigo-800/60 bg-indigo-50/50 dark:bg-indigo-950/30 p-4 shadow-sm">
                <h4 class="text-xs font-bold text-indigo-900 dark:text-indigo-200 uppercase tracking-wider flex items-center gap-2 mb-2">
                    <span>💡 Action Needed / Client Decisions For The Call</span>
                </h4>
                <div class="space-y-2 text-xs text-indigo-950 dark:text-indigo-100">
                    @foreach($customerInputSec['items'] as $cItem)
                        <div class="flex items-start gap-2">
                            <span class="text-indigo-600 dark:text-indigo-400 font-bold shrink-0 mt-0.5">•</span>
                            <span class="leading-relaxed">{{ $cItem['title'] ?? $cItem['raw'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- TAB 2: Ticket Breakdown --}}
    <div x-show="activeTab === 'tickets' || activeTab === 'all'" class="space-y-4">
        @if(!empty($prepSummary['sections']))
            <div class="space-y-4">
                @foreach($prepSummary['sections'] as $sec)
                    @php
                        $headerColor = match($sec['color']) {
                            'emerald' => 'border-emerald-200 dark:border-emerald-800/70 bg-emerald-50/40 dark:bg-emerald-950/20 text-emerald-900 dark:text-emerald-200',
                            'purple'  => 'border-purple-200 dark:border-purple-800/70 bg-purple-50/40 dark:bg-purple-950/20 text-purple-900 dark:text-purple-200',
                            'blue'    => 'border-blue-200 dark:border-blue-800/70 bg-blue-50/40 dark:bg-blue-950/20 text-blue-900 dark:text-blue-200',
                            'teal'    => 'border-teal-200 dark:border-teal-800/70 bg-teal-50/40 dark:bg-teal-950/20 text-teal-900 dark:text-teal-200',
                            'amber'   => 'border-amber-200 dark:border-amber-800/70 bg-amber-50/40 dark:bg-amber-950/20 text-amber-900 dark:text-amber-200',
                            'indigo'  => 'border-indigo-200 dark:border-indigo-800/70 bg-indigo-50/40 dark:bg-indigo-950/20 text-indigo-900 dark:text-indigo-200',
                            default   => 'border-gray-200 dark:border-gray-800 bg-gray-50/40 dark:bg-gray-800/20 text-gray-900 dark:text-gray-200',
                        };

                        $iconSymbol = match($sec['key']) {
                            'completed'      => '✅',
                            'qa'             => '🔍',
                            'deployment'     => '🚀',
                            'in_progress'    => '⚡',
                            'blockers'       => '⚠️',
                            'customer_input' => '💡',
                            'talking_points' => '💬',
                            default          => '📌',
                        };
                    @endphp

                    <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 overflow-hidden shadow-sm">
                        {{-- Section Header --}}
                        <div class="p-3.5 border-b {{ $headerColor }} flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wider flex items-center gap-2">
                                <span>{{ $iconSymbol }}</span>
                                <span>{{ $sec['title'] }}</span>
                            </h4>
                            @if($sec['count'] !== null)
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-white/80 dark:bg-gray-900/80 border border-current shadow-xs">
                                    {{ $sec['count'] }}
                                </span>
                            @endif
                        </div>

                        {{-- Section Body --}}
                        <div class="p-4 space-y-2.5">
                            {{-- Action notes callout if present --}}
                            @if(!empty($sec['action_notes']))
                                @foreach($sec['action_notes'] as $note)
                                    <div class="p-2.5 rounded-lg bg-primary-50/70 dark:bg-primary-950/30 border border-primary-200/80 dark:border-primary-800/60 text-xs font-medium text-primary-900 dark:text-primary-200 flex items-start gap-2">
                                        <span class="shrink-0 mt-0.5">📌</span>
                                        <span>{{ $note }}</span>
                                    </div>
                                @endforeach
                            @endif

                            {{-- Items list --}}
                            @foreach($sec['items'] as $item)
                                @if(!empty($item['ticket']))
                                    {{-- Ticket Card --}}
                                    <div class="p-3 rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50/40 dark:bg-gray-800/30 hover:border-gray-200 dark:hover:border-gray-700 transition space-y-1.5">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono font-bold text-[11px] px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 border border-gray-300 dark:border-gray-700">
                                                    {{ $item['ticket'] }}
                                                </span>

                                                @if($item['priority'])
                                                    @php
                                                        $priColor = match(strtolower($item['priority'])) {
                                                            'highest', 'critical', 'blocker' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300 border-rose-200 dark:border-rose-800',
                                                            'high' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300 border-amber-200 dark:border-amber-800',
                                                            'medium' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300 border-blue-200 dark:border-blue-800',
                                                            default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700',
                                                        };
                                                    @endphp
                                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border {{ $priColor }}">
                                                        {{ $item['priority'] }}
                                                    </span>
                                                @endif

                                                @if($item['status'])
                                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700">
                                                        {{ $item['status'] }}
                                                    </span>
                                                @endif
                                            </div>

                                            @if($item['owner'])
                                                <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                                    <span>👤</span>
                                                    <span>{{ $item['owner'] }}</span>
                                                </span>
                                            @endif
                                        </div>

                                        <div class="text-xs font-semibold text-gray-900 dark:text-gray-100 leading-snug">
                                            {{ $item['title'] }}
                                        </div>

                                        @if($item['notes'])
                                            <div class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed pt-0.5">
                                                {{ $item['notes'] }}
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    {{-- Standard bullet item / Talking point --}}
                                    <div class="flex items-start gap-2.5 p-2 rounded-lg hover:bg-gray-50/60 dark:hover:bg-gray-800/40 text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                                        <span class="text-primary-500 dark:text-primary-400 font-bold shrink-0 mt-0.5">•</span>
                                        <div class="min-w-0">
                                            {{-- Inline Jira ticket highlighting --}}
                                            {!! preg_replace('/([A-Z0-9]+-\d+)/', '<span class="inline-flex items-center px-1.5 py-0.2 rounded text-[11px] font-mono font-semibold bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 border border-gray-200 dark:border-gray-700">$1</span>', e($item['title'] ?? $item['raw'])) !!}
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif(!$meeting->prep)
            <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-800 p-6 text-center">
                <div class="text-gray-400 dark:text-gray-500 text-2xl mb-1">⏳</div>
                <div class="text-sm font-medium text-gray-700 dark:text-gray-300">No Pre-Meeting Ticket Breakdown Available</div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Meeting briefing and ticket status will be generated automatically prior to the sync.
                </p>
            </div>
        @endif
    </div>

    {{-- TAB 3: Action Items --}}
    <div x-show="activeTab === 'actions' || activeTab === 'all'" class="space-y-4">
        @if($actionItemsCount > 0)
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-gray-100 dark:border-gray-800">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <span>📌 Action Items & Commitments</span>
                    </h4>
                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/60">
                        {{ $actionItemsCount }} {{ \Illuminate\Support\Str::plural('item', $actionItemsCount) }}
                    </span>
                </div>

                <div class="space-y-2">
                    @foreach($meeting->actionItems as $item)
                        @php
                            $isDone = in_array(strtolower($item->status?->value ?? ''), ['completed', 'resolved']);
                        @endphp
                        <div class="flex items-center justify-between p-3 rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40 text-xs hover:border-gray-200 dark:hover:border-gray-700 transition">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $isDone ? 'bg-emerald-500' : 'bg-primary-500 animate-pulse' }}"></span>
                                <div class="truncate">
                                    <span class="font-semibold text-gray-900 dark:text-gray-100 {{ $isDone ? 'line-through text-gray-400 dark:text-gray-500' : '' }}">{{ $item->title }}</span>
                                    @if($item->owner_name)
                                        <span class="text-gray-500 dark:text-gray-400 text-[11px] ml-1.5">• 👤 {{ $item->owner_name }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0 ml-3">
                                @if($item->due_date)
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400 font-medium">Due {{ $item->due_date->format('M d') }}</span>
                                @endif
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border {{ $isDone ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700' }}">
                                    {{ $item->status?->label() ?? 'Pending' }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-800 p-6 text-center">
                <div class="text-gray-400 dark:text-gray-500 text-2xl mb-1">✓</div>
                <div class="text-sm font-medium text-gray-700 dark:text-gray-300">No Action Items Recorded</div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    All action items have been addressed or none were formally flagged for this session.
                </p>
            </div>
        @endif
    </div>

    {{-- TAB 4: Client Status Update Email Preview --}}
    @if($hasClientEmail)
        <div x-show="activeTab === 'email' || activeTab === 'all'" class="space-y-4">
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-gray-100 dark:border-gray-800">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <span>✉️ Customer Status Update Email</span>
                    </h4>
                    <span class="px-2 py-0.5 rounded text-[11px] font-medium bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
                        Preview
                    </span>
                </div>

                @if($meeting->prep->edited_status_email_subject ?? $meeting->prep->generated_status_email_subject)
                    <div class="mb-3 p-2.5 rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200/80 dark:border-gray-700/80 text-xs">
                        <span class="font-semibold text-gray-600 dark:text-gray-400">Subject:</span>
                        <span class="font-medium text-gray-900 dark:text-gray-100 ml-1">
                            {{ $meeting->prep->edited_status_email_subject ?? $meeting->prep->generated_status_email_subject }}
                        </span>
                    </div>
                @endif

                <div class="p-4 rounded-xl bg-gray-50/50 dark:bg-gray-800/30 border border-gray-100 dark:border-gray-800 text-xs leading-relaxed text-gray-700 dark:text-gray-300 font-normal">
                    {!! $meeting->prep->edited_status_email_body ?? $meeting->prep->generated_status_email_body !!}
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 5: Decisions & Follow-Up --}}
    @if($meeting->followUp)
        <div x-show="activeTab === 'summary' || activeTab === 'all'" class="space-y-4">
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2 pb-3 mb-3 border-b border-gray-100 dark:border-gray-800">
                    <span>📝 Post-Meeting Summary & Decisions</span>
                </h4>

                @if(!empty($meeting->followUp->summary))
                    <div class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line mb-4 font-normal">
                        {{ $meeting->followUp->summary }}
                    </div>
                @endif

                @if(!empty($decisions))
                    <div class="pt-3 border-t border-gray-100 dark:border-gray-800">
                        <h5 class="text-xs font-bold text-gray-900 dark:text-gray-100 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                            <span>🎯</span>
                            <span>Key Decisions Agreed:</span>
                        </h5>
                        <div class="space-y-2">
                            @foreach($decisions as $decision)
                                <div class="flex items-start gap-2.5 p-2.5 rounded-lg bg-emerald-50/50 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/50 text-xs text-gray-800 dark:text-gray-200">
                                    <span class="text-emerald-600 dark:text-emerald-400 font-bold shrink-0 mt-0.5">✓</span>
                                    <span class="leading-relaxed font-medium">{{ $decision }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Empty State if no prep, no followup, no action items --}}
    @if(!$meeting->prep && !$meeting->followUp && $actionItemsCount === 0)
        <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-800 p-6 text-center">
            <div class="text-gray-400 dark:text-gray-500 text-2xl mb-1">📅</div>
            <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Upcoming Sync Scheduled</div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">
                Use the video meeting link above to join when the session begins. Notes, AI summary, and action items will update automatically once the sync concludes.
            </p>
        </div>
    @endif

    {{-- Admin Quick Link --}}
    @if(auth()->user()?->isSuperAdmin() || !auth()->user()?->isClientOnly())
        <div class="pt-3 border-t border-gray-100 dark:border-gray-800 flex justify-end">
            <a href="/admin/client-meetings/{{ $meeting->id }}?tab=-summary-tab" target="_blank" class="text-xs text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1 font-medium">
                Open in Full Admin View &rarr;
            </a>
        </div>
    @endif
</div>
