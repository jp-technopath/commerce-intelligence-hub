@php
    $data = $this->getPortfolioData();
    $counts = $data['counts'] ?? [];
    $clients = $data['clients'] ?? [];
@endphp

<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-6 space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-200 dark:border-gray-800">
        <div>
            <div class="flex items-center gap-2">
                <div class="p-2 bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 rounded-lg">
                    <x-heroicon-m-heart class="w-5 h-5" />
                </div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Client Delivery Health Overview</h2>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Deterministic health evaluation based on milestone deadlines, blockers, QA rework loops, and response times.
            </p>
        </div>

        <div>
            <button
                type="button"
                wire:click="triggerDeliveryScan"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950 hover:bg-primary-100 rounded-lg border border-primary-200 dark:border-primary-800 transition"
            >
                <x-heroicon-m-arrow-path wire:loading.class="animate-spin" class="w-4 h-4" />
                <span>Re-scan Delivery Signals</span>
            </button>
        </div>
    </div>

    {{-- 4 Stat Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="p-4 bg-emerald-50/50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900/40 rounded-xl">
            <span class="text-xs font-semibold text-emerald-800 dark:text-emerald-300">Healthy</span>
            <div class="text-2xl font-black text-emerald-700 dark:text-emerald-400 mt-1">
                {{ $counts['Healthy'] ?? 0 }}
            </div>
            <span class="text-[11px] text-emerald-600/80">On schedule & active</span>
        </div>

        <div class="p-4 bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/40 rounded-xl">
            <span class="text-xs font-semibold text-amber-800 dark:text-amber-300">Watch</span>
            <div class="text-2xl font-black text-amber-700 dark:text-amber-400 mt-1">
                {{ $counts['Watch'] ?? 0 }}
            </div>
            <span class="text-[11px] text-amber-600/80">Approaching delays / 1-2 blockers</span>
        </div>

        <div class="p-4 bg-rose-50/50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/40 rounded-xl">
            <span class="text-xs font-semibold text-rose-800 dark:text-rose-300">At Risk</span>
            <div class="text-2xl font-black text-rose-700 dark:text-rose-400 mt-1">
                {{ $counts['At Risk'] ?? 0 }}
            </div>
            <span class="text-[11px] text-rose-600/80">Critical delays or QA rework</span>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-800 rounded-xl">
            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Insufficient Data</span>
            <div class="text-2xl font-black text-gray-800 dark:text-gray-200 mt-1">
                {{ $counts['Unknown'] ?? 0 }}
            </div>
            <span class="text-[11px] text-gray-500">Zero synced work items</span>
        </div>
    </div>

    {{-- Portfolio Client Table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border border-gray-200 dark:border-gray-800 rounded-xl divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-300 uppercase tracking-wider font-semibold">
                <tr>
                    <th class="py-3 px-4">Client Name</th>
                    <th class="py-3 px-4">Delivery Health</th>
                    <th class="py-3 px-4 text-center">Active Tasks</th>
                    <th class="py-3 px-4 text-center">Blockers</th>
                    <th class="py-3 px-4 text-center">Overdue</th>
                    <th class="py-3 px-4 text-center">QA Rework</th>
                    <th class="py-3 px-4">Health Rationale / Key Drivers</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($clients as $c)
                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition">
                        <td class="py-3 px-4 font-semibold text-gray-900 dark:text-gray-100">
                            {{ $c['name'] }}
                        </td>
                        <td class="py-3 px-4">
                            @if ($c['status'] === 'Healthy')
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                                    Healthy
                                </span>
                            @elseif ($c['status'] === 'Watch')
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                                    Watch
                                </span>
                            @elseif ($c['status'] === 'At Risk')
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-rose-100 dark:bg-rose-950 text-rose-800 dark:text-rose-300">
                                    At Risk
                                </span>
                            @else
                                <span class="px-2.5 py-1 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-400">
                                    Insufficient Data
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-center font-medium">
                            {{ $c['metrics']['total_active_tasks'] }}
                        </td>
                        <td class="py-3 px-4 text-center">
                            @if ($c['metrics']['blocked_tasks_count'] > 0)
                                <span class="px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 font-bold">
                                    {{ $c['metrics']['blocked_tasks_count'] }}
                                </span>
                            @else
                                <span class="text-gray-400">0</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-center">
                            @if ($c['metrics']['overdue_tasks_count'] > 0)
                                <span class="px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 font-bold">
                                    {{ $c['metrics']['overdue_tasks_count'] }}
                                </span>
                            @else
                                <span class="text-gray-400">0</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-center">
                            @if ($c['metrics']['rework_tasks_count'] > 0)
                                <span class="px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 font-bold">
                                    {{ $c['metrics']['rework_tasks_count'] }}
                                </span>
                            @else
                                <span class="text-gray-400">0</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-gray-600 dark:text-gray-300 max-w-md">
                            {{ $c['summary'] }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 text-center text-gray-500 dark:text-gray-400">
                            No active clients found in portfolio scope.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
