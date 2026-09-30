@php
    use App\Services\MeetingAgent\MeetingContentPresenter;

    $user = auth()->user();
    $isClientUser = $user?->isClientOnly() ?? false;

    $meetLink = $meeting->metadata['meet_link'] ?? null;
    $htmlLink = $meeting->metadata['html_link'] ?? null;
    $isUpcoming = $meeting->meeting_start_at && $meeting->meeting_start_at >= now();

    // Pre-Meeting Prep from Sent Email
    $prep = $meeting->prep;
    $prepSentAt = $prep?->email_sent_at;
    $isPrepSent = !empty($prepSentAt);
    $rawPrepBody = $prep?->effectiveBody();
    $prepBody = MeetingContentPresenter::formatEmailBody($rawPrepBody);
    $prepSubject = $prep?->effectiveSubject() ?? 'Pre-Meeting Status Update';
    $prepTo = $prep?->email_to;
    $prepCc = $prep?->email_cc;
    $hasPrepContent = !empty($prepBody);
    $canShowPrep = $isPrepSent || (!$isClientUser && $hasPrepContent);
    $agenda = MeetingContentPresenter::parseAgenda($prep?->recommended_agenda);

    // Post-Meeting Follow-Up from Sent Email
    $followUp = $meeting->followUp;
    $followUpSentAt = $followUp?->email_sent_at;
    $isFollowUpSent = !empty($followUpSentAt);
    $rawFollowUpBody = $followUp?->effectiveBody();
    $followUpBody = MeetingContentPresenter::formatEmailBody($rawFollowUpBody);
    $followUpSubject = $followUp?->effectiveSubject() ?? 'Meeting Follow-Up & Next Steps';
    $followUpTo = $followUp?->email_to;
    $followUpCc = $followUp?->email_cc;
    $decisions = MeetingContentPresenter::parseDecisions($followUp?->decisions);
    $hasFollowUpContent = !empty($followUpBody);
    $canShowFollowUp = $isFollowUpSent || (!$isClientUser && $hasFollowUpContent);

    // Action Items
    $actionItemsCount = $meeting->actionItems->count();

    // Default tab logic
    if (!$isUpcoming && ($isFollowUpSent || $canShowFollowUp)) {
        $defaultTab = 'followup';
    } elseif ($isPrepSent || $canShowPrep || $agenda['count'] > 0) {
        $defaultTab = 'prep';
    } elseif ($actionItemsCount > 0) {
        $defaultTab = 'actions';
    } else {
        $defaultTab = 'prep';
    }
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
    <div class="flex items-center gap-1.5 p-1 rounded-xl bg-gray-100/90 dark:bg-gray-800/90 border border-gray-200/80 dark:border-gray-700/80 text-xs font-semibold overflow-x-auto shadow-sm">
        {{-- Prep Tab Button --}}
        <button type="button" 
            @click="activeTab = 'prep'" 
            :class="activeTab === 'prep' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
            <span>📋</span>
            <span>Pre-Meeting Prep</span>
            @if($isPrepSent)
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">
                    Sent
                </span>
            @elseif(!$isClientUser && $hasPrepContent)
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">
                    Draft
                </span>
            @endif
        </button>

        {{-- Follow-Up Tab Button --}}
        <button type="button" 
            @click="activeTab = 'followup'" 
            :class="activeTab === 'followup' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
            <span>📝</span>
            <span>Meeting Follow-Up</span>
            @if($isFollowUpSent)
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">
                    Sent
                </span>
            @elseif(!$isClientUser && $hasFollowUpContent)
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">
                    Draft
                </span>
            @endif
        </button>

        {{-- Action Items Tab Button --}}
        <button type="button" 
            @click="activeTab = 'actions'" 
            :class="activeTab === 'actions' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
            <span>📌</span>
            <span>Action Items</span>
            @if($actionItemsCount > 0)
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-primary-100 dark:bg-primary-950 text-primary-700 dark:text-primary-300 font-bold">
                    {{ $actionItemsCount }}
                </span>
            @endif
        </button>

        {{-- View All Tab Button --}}
        <button type="button" 
            @click="activeTab = 'all'" 
            :class="activeTab === 'all' ? 'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm border border-gray-200/60 dark:border-gray-700/60' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200'" 
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition shrink-0 ml-auto cursor-pointer">
            <span>📄</span>
            <span>View All</span>
        </button>
    </div>

    {{-- TAB 1: Pre-Meeting Prep (From Sent Email) --}}
    <div x-show="activeTab === 'prep' || activeTab === 'all'" class="space-y-4">
        {{-- Agenda Card (if available) --}}
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

        {{-- Sent Status Email Card --}}
        @if($canShowPrep)
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
                {{-- Email Header --}}
                <div class="p-4 bg-gray-50/80 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-800">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                        <div class="min-w-0">
                            <div class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1.5">
                                <span>✉️ Pre-Meeting Status Update</span>
                            </div>
                            <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 mt-0.5 truncate">
                                {{ $prepSubject }}
                            </h4>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                                @if($prepTo)
                                    <span><strong>To:</strong> {{ $prepTo }}</span>
                                @endif
                                @if(!empty($prepCc))
                                    <span><strong>CC:</strong> {{ is_array($prepCc) ? implode(', ', $prepCc) : $prepCc }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="shrink-0 self-start sm:self-center">
                            @if($isPrepSent)
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                    <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Sent {{ $prepSentAt->format('M j, Y \a\t g:i A') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                    ⚠️ In Draft / Not Sent to Client Yet
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Email Body --}}
                <div class="p-4 sm:p-5 prose prose-sm dark:prose-invert max-w-none text-gray-800 dark:text-gray-200 leading-relaxed font-normal">
                    {!! $prepBody !!}
                </div>
            </div>
        @else
            {{-- Friendly Placeholder for client if not sent yet --}}
            <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-800 p-8 text-center bg-white dark:bg-gray-900">
                <div class="text-gray-400 dark:text-gray-500 text-3xl mb-2">⏳</div>
                <div class="text-sm font-semibold text-gray-800 dark:text-gray-200">Pre-Meeting Status Update In Preparation</div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 max-w-md mx-auto leading-relaxed">
                    Our team is reviewing the latest project progress and preparing the status update. Once reviewed and sent, it will appear directly here.
                </p>
            </div>
        @endif
    </div>

    {{-- TAB 2: Meeting Follow-Up (From Sent Email) --}}
    <div x-show="activeTab === 'followup' || activeTab === 'all'" class="space-y-4">
        @if($canShowFollowUp)
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
                {{-- Email Header --}}
                <div class="p-4 bg-gray-50/80 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-800">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                        <div class="min-w-0">
                            <div class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1.5">
                                <span>✉️ Meeting Follow-Up &amp; Summary</span>
                            </div>
                            <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 mt-0.5 truncate">
                                {{ $followUpSubject }}
                            </h4>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                                @if($followUpTo)
                                    <span><strong>To:</strong> {{ $followUpTo }}</span>
                                @endif
                                @if(!empty($followUpCc))
                                    <span><strong>CC:</strong> {{ is_array($followUpCc) ? implode(', ', $followUpCc) : $followUpCc }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="shrink-0 self-start sm:self-center">
                            @if($isFollowUpSent)
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                    <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Sent {{ $followUpSentAt->format('M j, Y \a\t g:i A') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                    ⚠️ In Draft / Not Sent to Client Yet
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Email Body --}}
                <div class="p-4 sm:p-5 prose prose-sm dark:prose-invert max-w-none text-gray-800 dark:text-gray-200 leading-relaxed font-normal">
                    {!! $followUpBody !!}
                </div>

                {{-- Key Decisions Checklist (if present) --}}
                @if(!empty($decisions))
                    <div class="p-4 sm:p-5 border-t border-gray-100 dark:border-gray-800 bg-gray-50/30 dark:bg-gray-800/20">
                        <h5 class="text-xs font-bold text-gray-900 dark:text-gray-100 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                            <span>🎯</span>
                            <span>Key Decisions Agreed:</span>
                        </h5>
                        <div class="space-y-2">
                            @foreach($decisions as $decision)
                                <div class="flex items-start gap-2.5 p-2.5 rounded-lg bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/50 text-xs text-gray-800 dark:text-gray-200">
                                    <span class="text-emerald-600 dark:text-emerald-400 font-bold shrink-0 mt-0.5">✓</span>
                                    <span class="leading-relaxed font-medium">{{ $decision }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @else
            {{-- Friendly Placeholder for client if not sent yet --}}
            <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-800 p-8 text-center bg-white dark:bg-gray-900">
                <div class="text-gray-400 dark:text-gray-500 text-3xl mb-2">📝</div>
                <div class="text-sm font-semibold text-gray-800 dark:text-gray-200">Follow-Up Notes In Preparation</div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 max-w-md mx-auto leading-relaxed">
                    Meeting summary, decisions, and agreed next steps are being finalized by the team and will appear directly here once sent.
                </p>
            </div>
        @endif
    </div>

    {{-- TAB 3: Action Items & Commitments --}}
    <div x-show="activeTab === 'actions' || activeTab === 'all'" class="space-y-4">
        @if($actionItemsCount > 0)
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-gray-100 dark:border-gray-800">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <span>📌 Action Items &amp; Commitments</span>
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
            <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-800 p-8 text-center bg-white dark:bg-gray-900">
                <div class="text-gray-400 dark:text-gray-500 text-3xl mb-2">✓</div>
                <div class="text-sm font-semibold text-gray-800 dark:text-gray-200">No Action Items Recorded</div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 max-w-sm mx-auto">
                    All action items have been addressed or none were formally flagged for this session.
                </p>
            </div>
        @endif
    </div>

    {{-- Admin Quick Link --}}
    @if(auth()->user()?->isSuperAdmin() || !auth()->user()?->isClientOnly())
        <div class="pt-3 border-t border-gray-100 dark:border-gray-800 flex justify-end">
            <a href="/admin/client-meetings/{{ $meeting->id }}?tab=-summary-tab" target="_blank" class="text-xs text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1 font-medium">
                Open in Full Admin View &rarr;
            </a>
        </div>
    @endif
</div>
