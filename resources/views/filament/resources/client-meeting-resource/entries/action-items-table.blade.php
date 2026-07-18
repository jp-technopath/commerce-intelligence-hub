@php
    $record = $getRecord();
    $actionItems = $record->actionItems()->orderBy('created_at', 'desc')->get();
@endphp

<div>
    @if ($actionItems->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No action items yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Title</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Owner</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Due Date</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Status</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Source</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Jira</th>
                        <th class="px-3 py-2 text-center font-medium text-gray-700 dark:text-gray-300">Customer‑Facing</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($actionItems as $item)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100 font-medium">
                                {{ $item->title }}
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                                {{ $item->owner_name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                                {{ $item->due_date?->format('M j, Y') ?? '—' }}
                            </td>
                            <td class="px-3 py-2">
                                <span @class([
                                    'inline-flex items-center gap-x-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                                    'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30' => $item->status === \App\Enums\ActionItemStatus::Open,
                                    'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-400/10 dark:text-yellow-500 dark:ring-yellow-400/30' => $item->status === \App\Enums\ActionItemStatus::InProgress,
                                    'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30' => $item->status === \App\Enums\ActionItemStatus::Completed,
                                    'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/30' => $item->status === \App\Enums\ActionItemStatus::Blocked,
                                ])>
                                    {{ $item->status->label() }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <span @class([
                                    'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                                    'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30' => $item->source === \App\Enums\ActionItemSource::Ai,
                                    'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/30' => $item->source === \App\Enums\ActionItemSource::Manual,
                                ])>
                                    {{ $item->source->label() }}
                                </span>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">
                                @if ($item->jira_issue_key)
                                    @php
                                        $user = auth()->user();
                                        $jiraAccount = $user?->jiraAccount();
                                        $siteUrl = $jiraAccount ? ($jiraAccount->getCredential('site_url') ?: config('meeting_agent.jira.base_url')) : config('meeting_agent.jira.base_url');
                                    @endphp
                                    @if ($siteUrl)
                                        <a href="{{ rtrim($siteUrl, '/') }}/browse/{{ $item->jira_issue_key }}" target="_blank" class="text-primary-600 underline hover:no-underline dark:text-primary-400 inline-flex items-center gap-x-0.5">
                                            <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3" />
                                            {{ $item->jira_issue_key }}
                                        </a>
                                    @else
                                        {{ $item->jira_issue_key }}
                                    @endif
                                @else
                                    <button type="button"
                                            wire:click="createJiraTaskForActionItem({{ $item->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="createJiraTaskForActionItem({{ $item->id }})"
                                            class="inline-flex items-center gap-x-1 rounded bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700 shadow-sm ring-1 ring-inset ring-blue-600/20 hover:bg-blue-100 disabled:opacity-50 dark:bg-blue-900/30 dark:text-blue-300 dark:ring-blue-400/20 dark:hover:bg-blue-900/50">
                                        <svg class="h-3 w-3 animate-spin" wire:loading wire:target="createJiraTaskForActionItem({{ $item->id }})" style="display: none;" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span wire:loading.remove wire:target="createJiraTaskForActionItem({{ $item->id }})">
                                            Create Task
                                        </span>
                                        <span wire:loading wire:target="createJiraTaskForActionItem({{ $item->id }})">
                                            Creating...
                                        </span>
                                    </button>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center">
                                @if ($item->is_customer_facing)
                                    <x-heroicon-m-check-circle class="inline h-5 w-5 text-green-500" />
                                @else
                                    <x-heroicon-m-minus-circle class="inline h-5 w-5 text-gray-300 dark:text-gray-600" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ $actionItems->count() }} {{ Str::plural('action item', $actionItems->count()) }}
        </p>
    @endif
</div>
