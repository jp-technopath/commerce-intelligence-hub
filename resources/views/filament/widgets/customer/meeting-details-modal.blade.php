@php
    $meetLink = $meeting->metadata['meet_link'] ?? null;
    $htmlLink = $meeting->metadata['html_link'] ?? null;
    $isUpcoming = $meeting->meeting_start_at && $meeting->meeting_start_at >= now();
@endphp

<div class="space-y-4">
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
                📌 {{ $meeting->actionItems->count() }} Action Items
            </div>
        </div>
    </div>

    {{-- Pre-Meeting Agenda / Brief --}}
    @if($meeting->prep)
        <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2 mb-2">
                <span>📋 Agenda & Topics</span>
            </h4>
            @if(!empty($meeting->prep->recommended_agenda))
                <ul class="space-y-1.5 list-disc list-inside text-xs text-gray-700 dark:text-gray-300">
                    @foreach((array) $meeting->prep->recommended_agenda as $item)
                        <li>{{ is_array($item) ? json_encode($item) : $item }}</li>
                    @endforeach
                </ul>
            @endif
            @if(!empty($meeting->prep->internal_summary))
                <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-600 dark:text-gray-400 whitespace-pre-line leading-relaxed">
                    {{ $meeting->prep->internal_summary }}
                </div>
            @endif
        </div>
    @endif

    {{-- Meeting Summary & Decisions --}}
    @if($meeting->followUp)
        <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2 mb-2">
                <span>📝 Executive Summary</span>
            </h4>
            @if(!empty($meeting->followUp->summary))
                <div class="text-xs text-gray-700 dark:text-gray-300 whitespace-pre-line leading-relaxed">
                    {{ $meeting->followUp->summary }}
                </div>
            @endif
            @if(!empty($meeting->followUp->decisions))
                <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                    <h5 class="text-xs font-semibold text-gray-800 dark:text-gray-200 mb-1.5">Key Decisions:</h5>
                    <ul class="space-y-1 list-disc list-inside text-xs text-gray-600 dark:text-gray-400">
                        @foreach((array) $meeting->followUp->decisions as $decision)
                            <li>{{ is_array($decision) ? json_encode($decision) : $decision }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    {{-- Action Items & Commitments --}}
    @if($meeting->actionItems->count() > 0)
        <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2 mb-3">
                <span>📌 Action Items & Commitments ({{ $meeting->actionItems->count() }})</span>
            </h4>
            <div class="space-y-2">
                @foreach($meeting->actionItems as $item)
                    <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40 text-xs">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="w-2 h-2 rounded-full shrink-0 {{ $item->status?->value === 'completed' ? 'bg-emerald-500' : 'bg-primary-500' }}"></span>
                            <div class="truncate">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $item->title }}</span>
                                @if($item->owner_name)
                                    <span class="text-gray-500 dark:text-gray-400 text-[11px] ml-1.5">• {{ $item->owner_name }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 ml-2">
                            @if($item->due_date)
                                <span class="text-[11px] text-gray-500 dark:text-gray-400">Due {{ $item->due_date->format('M d') }}</span>
                            @endif
                            <span class="px-2 py-0.5 rounded text-[10px] font-medium {{ $item->status?->value === 'completed' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' }}">
                                {{ $item->status?->label() ?? 'Pending' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif(!$meeting->prep && !$meeting->followUp)
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
