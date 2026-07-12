@php
    $snapshot = $getState();

    // The snapshot is stored as a categorized structure:
    // { "completed_since_last_meeting": [{key, summary, ...}], "in_progress": [...], ... }
    // with metadata keys like "total_count" and "snapshot_at".
    // Flatten all category arrays into a single issues list.
    $issues = [];
    $metadataKeys = ['total_count', 'snapshot_at'];

    if (is_array($snapshot)) {
        foreach ($snapshot as $categoryKey => $categoryItems) {
            if (in_array($categoryKey, $metadataKeys, true)) {
                continue;
            }
            if (is_array($categoryItems)) {
                foreach ($categoryItems as $item) {
                    if (is_array($item) && isset($item['key'])) {
                        $item['_category'] = $categoryKey;
                        $issues[] = $item;
                    }
                }
            }
        }
    }
@endphp

<div class="space-y-3">
    @if (count($issues) > 0)
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Key</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Summary</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Status</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Assignee</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Priority</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Category</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($issues as $issue)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="px-3 py-2 font-mono text-xs text-primary-600 dark:text-primary-400">
                                @if (config('meeting_agent.jira.base_url'))
                                    <a href="{{ config('meeting_agent.jira.base_url') }}/browse/{{ $issue['key'] ?? '' }}" target="_blank" class="underline hover:no-underline">
                                        {{ $issue['key'] ?? '—' }}
                                    </a>
                                @else
                                    {{ $issue['key'] ?? '—' }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                {{ $issue['summary'] ?? $issue['title'] ?? '—' }}
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">
                                    {{ $issue['status'] ?? '—' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                                {{ $issue['assignee'] ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                                {{ $issue['priority'] ?? '—' }}
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                                    {{ str_replace('_', ' ', ucfirst($issue['_category'] ?? '—')) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ count($issues) }} {{ Str::plural('issue', count($issues)) }} in snapshot
        </p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">No Jira data captured.</p>
    @endif
</div>

