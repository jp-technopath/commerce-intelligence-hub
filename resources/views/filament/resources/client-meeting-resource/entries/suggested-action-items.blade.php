@php
    $record = $getRecord();
    $suggestions = $record->followUp?->suggested_action_items ?? [];
@endphp

<div class="space-y-3">
    @forelse ($suggestions as $index => $item)
        <div class="flex items-start gap-x-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
            <div class="flex-1 space-y-1">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $item['title'] ?? 'Untitled' }}
                </p>
                @if (! empty($item['description']))
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $item['description'] }}
                    </p>
                @endif
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    @if (! empty($item['owner_name'] ?? $item['owner'] ?? null))
                        <span>
                            <x-heroicon-m-user class="inline h-3.5 w-3.5" />
                            {{ $item['owner_name'] ?? $item['owner'] }}
                        </span>
                    @endif
                    @if (! empty($item['due_date']))
                        <span>
                            <x-heroicon-m-calendar class="inline h-3.5 w-3.5" />
                            {{ $item['due_date'] }}
                        </span>
                    @endif
                    @if (! empty($item['is_customer_facing']))
                        <span class="text-green-600 dark:text-green-400">
                            <x-heroicon-m-eye class="inline h-3.5 w-3.5" />
                            Customer-facing
                        </span>
                    @endif
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-x-2">
                <button
                    type="button"
                    wire:click="acceptSuggestedItem({{ $index }})"
                    style="background-color: #2563eb; color: #ffffff; transition: background-color 0.2s;"
                    onmouseover="this.style.backgroundColor='#1d4ed8'"
                    onmouseout="this.style.backgroundColor='#2563eb'"
                    class="inline-flex items-center gap-x-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                >
                    <x-heroicon-m-check class="h-4 w-4" />
                    Accept
                </button>
                <button
                    type="button"
                    wire:click="dismissSuggestedItem({{ $index }})"
                    class="inline-flex items-center gap-x-1 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-600"
                >
                    <x-heroicon-m-x-mark class="h-4 w-4" />
                    Dismiss
                </button>
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">No remaining suggestions.</p>
    @endforelse
</div>
