<x-filament-panels::page class="relative">
    <style>
        .quick-filter-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.5rem;
            border-radius: 0.75rem;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .dark .quick-filter-bar {
            background: #111827 !important;
            border-color: #1f2937 !important;
        }
        .quick-filter-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.375rem;
        }
        .quick-filter-label {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            padding: 0 0.5rem;
            user-select: none;
        }
        .dark .quick-filter-label {
            color: #64748b !important;
        }
        .quick-filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.6875rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #334155;
            user-select: none;
        }
        .quick-filter-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        .dark .quick-filter-btn {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }
        .dark .quick-filter-btn:hover {
            background: #334155 !important;
            color: #f8fafc !important;
            border-color: #475569 !important;
        }
        .quick-filter-btn-active {
            background: #2563eb !important;
            border-color: #2563eb !important;
            color: #ffffff !important;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.35);
        }
        .quick-filter-btn-active:hover {
            background: #1d4ed8 !important;
            border-color: #1d4ed8 !important;
            color: #ffffff !important;
        }
        .dark .quick-filter-btn-active {
            background: #3b82f6 !important;
            border-color: #3b82f6 !important;
            color: #ffffff !important;
            box-shadow: 0 1px 4px rgba(59, 130, 246, 0.4);
        }
        .dark .quick-filter-btn-active:hover {
            background: #2563eb !important;
            border-color: #2563eb !important;
            color: #ffffff !important;
        }
        .quick-filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.25rem;
            height: 1.125rem;
            padding: 0 0.35rem;
            font-size: 0.625rem;
            font-weight: 700;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
            transition: all 0.15s ease-in-out;
        }
        .dark .quick-filter-badge {
            background: #0f172a !important;
            color: #94a3b8 !important;
        }
        .quick-filter-btn-active .quick-filter-badge {
            background: rgba(255, 255, 255, 0.25) !important;
            color: #ffffff !important;
        }
        .quick-filter-clear {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: #64748b;
            padding: 0.375rem 0.625rem;
            border-radius: 0.375rem;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
        }
        .quick-filter-clear:hover {
            color: #0f172a;
            background: #f1f5f9;
        }
        .dark .quick-filter-clear {
            color: #94a3b8 !important;
        }
        .dark .quick-filter-clear:hover {
            color: #f8fafc !important;
            background: #1e293b !important;
        }
    </style>

    {{-- Glassmorphism Full Loading Overlay --}}
    <div wire:loading.flex class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-md flex flex-col items-center justify-center transition-opacity duration-300">
        <div class="p-6 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-2xl flex flex-col items-center gap-4 max-w-sm w-full mx-4 text-center">
            <div class="relative flex items-center justify-center">
                <div class="absolute w-12 h-12 rounded-full border-4 border-primary-500/20 animate-ping"></div>
                <svg class="animate-spin w-10 h-10 text-primary-600 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Loading Active Work Items...</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Fetching live delivery pipeline and task statuses.</p>
            </div>
        </div>
    </div>

    {{-- Top Customer Selector Bar --}}
    @if ($this->shouldShowClientFilter())
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-4 mb-4 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 rounded-lg">
                    <x-heroicon-m-building-office-2 class="w-6 h-6" />
                </div>
                <div>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Customer Account Filter</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Select a customer account to inspect active tasks currently in delivery.</p>
                </div>
            </div>
            <div class="w-full sm:w-80">
                {{ $this->form }}
            </div>
        </div>
    @endif

    {{-- Quick Status Filters Toolbar --}}
    @php
        $counts = $this->getQuickFilterCounts();
        $quickFilters = [
            'all' => [
                'label' => 'All Tasks',
                'count' => $counts['all'] ?? 0,
                'icon' => 'heroicon-m-squares-2x2',
                'description' => 'View all tasks for this customer',
            ],
            'pipeline' => [
                'label' => 'Pipeline',
                'count' => $counts['pipeline'] ?? 0,
                'icon' => 'heroicon-m-arrow-path',
                'description' => 'Ready for Dev and In Progress',
            ],
            'ready' => [
                'label' => 'Ready for Dev',
                'count' => $counts['ready'] ?? 0,
                'icon' => 'heroicon-m-code-bracket',
                'description' => 'Tasks ready for development',
            ],
            'in_progress' => [
                'label' => 'In Progress',
                'count' => $counts['in_progress'] ?? 0,
                'icon' => 'heroicon-m-play',
                'description' => 'Tasks actively in progress',
            ],
            'ready_for_deployment' => [
                'label' => 'Ready for Deployment',
                'count' => $counts['ready_for_deployment'] ?? 0,
                'icon' => 'heroicon-m-rocket-launch',
                'description' => 'Features ready for deployment',
            ],
            'backlog' => [
                'label' => 'Backlog',
                'count' => $counts['backlog'] ?? 0,
                'icon' => 'heroicon-m-archive-box',
                'description' => 'Tasks in backlog or on hold',
            ],
            'done' => [
                'label' => 'Done',
                'count' => $counts['done'] ?? 0,
                'icon' => 'heroicon-m-check-circle',
                'description' => 'Completed / resolved tasks',
            ],
        ];
    @endphp

    <div class="mb-4">
        <div class="quick-filter-bar">
            <div class="quick-filter-group">
                <span class="quick-filter-label">
                    Quick Filter:
                </span>
                @foreach ($quickFilters as $key => $filter)
                    @php $isActive = ($this->quick_filter === $key); @endphp
                    <button
                        type="button"
                        wire:click="setQuickFilter('{{ $key }}')"
                        wire:loading.attr="disabled"
                        title="{{ $filter['description'] }}"
                        class="quick-filter-btn {{ $isActive ? 'quick-filter-btn-active' : '' }}"
                    >
                        <x-dynamic-component :component="$filter['icon']" class="w-3.5 h-3.5 shrink-0" />
                        <span>{{ $filter['label'] }}</span>
                        <span class="quick-filter-badge">
                            {{ number_format($filter['count']) }}
                        </span>
                    </button>
                @endforeach
            </div>

            @if ($this->quick_filter !== 'all')
                <div class="flex items-center">
                    <button
                        type="button"
                        wire:click="clearQuickFilter"
                        wire:loading.attr="disabled"
                        class="quick-filter-clear"
                        title="Reset filter and show all tasks"
                    >
                        <x-heroicon-m-x-mark class="w-3.5 h-3.5" />
                        <span>Clear Filter</span>
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- Active Work Items Table --}}
    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
