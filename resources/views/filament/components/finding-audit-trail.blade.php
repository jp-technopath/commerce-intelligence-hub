<div class="space-y-3 py-2">
    <div class="text-xs text-gray-500 mb-2">
        Audit log of all lifecycle status transitions, user attributions, and resolution notes for this finding.
    </div>

    @php
        $transitions = $finding->statusTransitions()->exists()
            ? $finding->statusTransitions()->orderBy('id', 'asc')->get()
            : collect($finding->status_history_json ?? []);
    @endphp

    @forelse ($transitions as $entry)
        @php
            $isRelational = is_object($entry) && !($entry instanceof \stdClass);
            $fromStatus = $isRelational ? ($entry->from_status?->value ?? (string)$entry->from_status) : ($entry['from_status'] ?? 'new');
            $toStatus = $isRelational ? ($entry->to_status?->value ?? (string)$entry->to_status) : ($entry['to_status'] ?? '');
            $changedAt = $isRelational ? $entry->created_at : ($entry['changed_at'] ?? null);
            $changedByName = $isRelational ? ($entry->user?->name ?? 'System') : ($entry['changed_by_name'] ?? 'System');
            $source = $isRelational ? ($entry->transition_source ?? null) : ($entry['transition_source'] ?? null);
            $notes = $isRelational ? ($entry->notes ?? null) : ($entry['notes'] ?? null);
            $isSimulation = in_array($source, ['pilot_simulation', 'pilot_demonstration'], true) || str_contains((string)$notes, '[SIMULATED');
            $isCleanup = $source === 'pilot_cleanup' || str_contains((string)$notes, '[SIMULATION AUDIT CLEANUP]');
        @endphp
        <div class="p-3 {{ $isSimulation ? 'bg-purple-50/70 dark:bg-purple-950/40 border-purple-200 dark:border-purple-800/60' : ($isCleanup ? 'bg-amber-50/70 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800/60' : 'bg-gray-50 dark:bg-gray-800/60 border-gray-200 dark:border-gray-700/60') }} border rounded-lg text-xs space-y-1">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-bold text-gray-800 dark:text-gray-200">
                        {{ ucfirst(str_replace('_', ' ', $fromStatus ?: 'new')) }} &rarr; {{ ucfirst(str_replace('_', ' ', $toStatus ?: 'new')) }}
                    </span>
                    @if ($isSimulation)
                        <span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-purple-200 text-purple-900 dark:bg-purple-900 dark:text-purple-200 tracking-wide uppercase">
                            Simulated Pilot Activity &bull; Not Actual Outreach
                        </span>
                    @elseif ($isCleanup)
                        <span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-amber-200 text-amber-900 dark:bg-amber-900 dark:text-amber-200 tracking-wide uppercase">
                            Simulation Cleanup
                        </span>
                    @endif
                </div>
                <span class="text-[10px] text-gray-400">
                    {{ $changedAt ? \Carbon\Carbon::parse($changedAt)->toDayDateTimeString() : '—' }}
                </span>
            </div>
            <div class="text-gray-500">
                Changed by: <strong class="text-gray-700 dark:text-gray-300">{{ $changedByName }}</strong>
                @if ($source && !in_array($source, ['forge_ui', 'pilot_simulation', 'pilot_cleanup']))
                    <span class="text-gray-400">via {{ $source }}</span>
                @endif
            </div>
            @if (!empty($notes))
                <div class="text-[11px] {{ $isSimulation ? 'text-purple-900 dark:text-purple-300' : 'text-gray-600 dark:text-gray-400' }} italic pt-1 border-t border-gray-200/60 dark:border-gray-700/40">
                    &ldquo;{{ $notes }}&rdquo;
                </div>
            @endif
        </div>
    @empty
        <div class="text-xs text-gray-400 text-center py-4">
            No status transitions recorded yet.
        </div>
    @endforelse
</div>
