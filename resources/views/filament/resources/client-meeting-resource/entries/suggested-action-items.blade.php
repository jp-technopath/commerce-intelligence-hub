@php
    $record = $getRecord();
    $suggestions = $record->followUp?->suggested_action_items ?? [];
    $isClientUser = auth()->user()?->isClientOnly() ?? false;
@endphp

<style>
    .suggested-action-item-card {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        padding: 1rem;
        transition: background-color 0.2s, border-color 0.2s;
    }
    .dark .suggested-action-item-card {
        background-color: rgba(30, 41, 59, 0.85) !important;
        border-color: rgba(71, 85, 105, 0.7) !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }
    .suggested-action-item-title {
        color: #0f172a;
    }
    .dark .suggested-action-item-title {
        color: #f8fafc !important;
    }
    .suggested-action-item-desc {
        color: #475569;
    }
    .dark .suggested-action-item-desc {
        color: #cbd5e1 !important;
    }
    .suggested-action-item-meta {
        color: #64748b;
    }
    .dark .suggested-action-item-meta {
        color: #94a3b8 !important;
    }
    .suggested-accept-btn {
        background-color: #2563eb;
        color: #ffffff;
        transition: background-color 0.2s;
    }
    .suggested-accept-btn:hover {
        background-color: #1d4ed8;
    }
    .suggested-dismiss-btn {
        background-color: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        transition: background-color 0.2s, color 0.2s;
    }
    .suggested-dismiss-btn:hover {
        background-color: #e2e8f0;
        color: #1e293b;
    }
    .dark .suggested-dismiss-btn {
        background-color: #334155 !important;
        color: #e2e8f0 !important;
        border-color: #475569 !important;
    }
    .dark .suggested-dismiss-btn:hover {
        background-color: #475569 !important;
        color: #ffffff !important;
    }
</style>

<div class="space-y-3">
    @forelse ($suggestions as $index => $item)
        <div class="suggested-action-item-card flex items-start gap-x-4">
            <div class="flex-1 space-y-1">
                <p class="suggested-action-item-title text-sm font-semibold">
                    {{ $item['title'] ?? 'Untitled' }}
                </p>
                @if (! empty($item['description']))
                    <p class="suggested-action-item-desc text-sm leading-relaxed">
                        {{ $item['description'] }}
                    </p>
                @endif
                <div class="suggested-action-item-meta flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    @if (! empty($item['owner_name'] ?? $item['owner'] ?? null))
                        <span class="inline-flex items-center gap-1">
                            <x-heroicon-m-user class="h-3.5 w-3.5" />
                            {{ $item['owner_name'] ?? $item['owner'] }}
                        </span>
                    @endif
                    @if (! empty($item['due_date']))
                        <span class="inline-flex items-center gap-1">
                            <x-heroicon-m-calendar class="h-3.5 w-3.5" />
                            {{ $item['due_date'] }}
                        </span>
                    @endif
                    @if (! empty($item['is_customer_facing']))
                        <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-medium">
                            <x-heroicon-m-eye class="h-3.5 w-3.5" />
                            Customer-facing
                        </span>
                    @endif
                </div>
            </div>
            @if (!$isClientUser)
                <div class="flex shrink-0 items-center gap-x-2">
                    <button
                        type="button"
                        wire:click="acceptSuggestedItem({{ $index }})"
                        class="suggested-accept-btn inline-flex items-center gap-x-1 rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                    >
                        <x-heroicon-m-check class="h-4 w-4" />
                        Accept
                    </button>
                    <button
                        type="button"
                        wire:click="dismissSuggestedItem({{ $index }})"
                        class="suggested-dismiss-btn inline-flex items-center gap-x-1 rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm"
                    >
                        <x-heroicon-m-x-mark class="h-4 w-4" />
                        Dismiss
                    </button>
                </div>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">No remaining suggestions.</p>
    @endforelse
</div>
