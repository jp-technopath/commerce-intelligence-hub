@php
    $data = $this->getMeetingsData();
    $meetings = $data['meetings'] ?? collect();
    $unassignedCount = $data['unassigned_count'] ?? 0;
@endphp

<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-6 space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-200 dark:border-gray-800">
        <div>
            <div class="flex items-center gap-2">
                <div class="p-2 bg-purple-50 dark:bg-purple-950 text-purple-600 dark:text-purple-400 rounded-lg">
                    <x-heroicon-m-calendar class="w-5 h-5" />
                </div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Meeting Intelligence & Action Readiness</h2>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                4-stage preparation & follow-up tracking across upcoming client interactions.
            </p>
        </div>

        <div>
            <button
                type="button"
                wire:click="syncMeetingIntelligence"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950 hover:bg-primary-100 rounded-lg border border-primary-200 dark:border-primary-800 transition"
            >
                <x-heroicon-m-arrow-path wire:loading.class="animate-spin" class="w-4 h-4" />
                <span>Sync Readiness</span>
            </button>
        </div>
    </div>

    {{-- Unassigned Meetings Alert --}}
    @if ($unassignedCount > 0)
        <div class="p-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900 rounded-xl flex items-center justify-between text-xs text-amber-800 dark:text-amber-300">
            <div class="flex items-center gap-2">
                <x-heroicon-m-user-minus class="w-5 h-5 text-amber-600" />
                <span><strong>{{ $unassignedCount }} upcoming meeting(s)</strong> have no designated internal owner assigned.</span>
            </div>
            <span class="text-[11px] text-amber-700">Assign an engineer or account manager to drive preparation</span>
        </div>
    @endif

    {{-- Meetings Table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border border-gray-200 dark:border-gray-800 rounded-xl divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-300 uppercase tracking-wider font-semibold">
                <tr>
                    <th class="py-3 px-4">Client</th>
                    <th class="py-3 px-4">Meeting Title</th>
                    <th class="py-3 px-4">Scheduled Date</th>
                    <th class="py-3 px-4">Internal Owner</th>
                    <th class="py-3 px-4">Prep Stage</th>
                    <th class="py-3 px-4">Follow-up</th>
                    <th class="py-3 px-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($meetings as $m)
                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition">
                        <td class="py-3 px-4 font-semibold text-gray-900 dark:text-gray-100">
                            {{ $m->client?->name ?? 'External' }}
                        </td>
                        <td class="py-3 px-4 font-medium text-gray-800 dark:text-gray-200">
                            {{ $m->title }}
                        </td>
                        <td class="py-3 px-4 text-gray-600 dark:text-gray-300">
                            {{ $m->meeting_start_at ? $m->meeting_start_at->format('M j, g:i A') : '-' }}
                        </td>
                        <td class="py-3 px-4">
                            @if ($m->internalOwner)
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $m->internalOwner->name }}</span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[11px] bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 font-bold">
                                    Unassigned
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            @php $pStage = $m->computed_prep_stage ?? 'needed'; @endphp
                            @if ($pStage === 'needed')
                                <span class="px-2 py-0.5 rounded-full text-xs bg-rose-100 dark:bg-rose-950 text-rose-800 dark:text-rose-300 font-medium">
                                    Preparation Needed
                                </span>
                            @elseif ($pStage === 'draft_generated')
                                <span class="px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 font-medium">
                                    Draft Generated
                                </span>
                            @elseif ($pStage === 'reviewed_ready')
                                <span class="px-2 py-0.5 rounded-full text-xs bg-blue-100 dark:bg-blue-950 text-blue-800 dark:text-blue-300 font-medium">
                                    Reviewed & Ready
                                </span>
                            @elseif ($pStage === 'completed')
                                <span class="px-2 py-0.5 rounded-full text-xs bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 font-medium">
                                    Shared / Completed
                                </span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            <span class="text-gray-500 text-[11px] capitalize">
                                {{ str_replace('_', ' ', $m->computed_followup_stage ?? 'Not started') }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-right">
                            <a
                                href="{{ url('/admin/client-meetings/' . $m->id) }}"
                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline"
                            >
                                <span>Open</span>
                                <x-heroicon-m-arrow-top-right-on-square class="w-3.5 h-3.5" />
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 text-center text-gray-500 dark:text-gray-400">
                            No upcoming client meetings scheduled in the next 7 days.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
