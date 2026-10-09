@php
    $data = $this->getHoursData();
    $totalHours = $data['total_portfolio_hours'] ?? 0.0;
    $unattributedHours = $data['total_unattributed_hours'] ?? 0.0;
    $unattributedAuthors = $data['unattributed_authors'] ?? [];
    $byCustomer = $data['by_customer'] ?? [];
    $periodLabel = $data['period_label'] ?? now()->format('M 1 - M j, Y');
@endphp

<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-6 space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-gray-200 dark:border-gray-800">
        <div>
            <div class="flex items-center gap-2">
                <div class="p-2 bg-indigo-50 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 rounded-lg">
                    <x-heroicon-m-chart-bar class="w-5 h-5" />
                </div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Customer Hours & Contract Allocation</h2>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Independent budget pacing tracking (evaluated separately from technical project delivery health).
            </p>
        </div>

        <div class="text-right">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Portfolio Hours</span>
            <div class="text-xl font-black text-gray-900 dark:text-gray-100">
                {{ $totalHours }}<span class="text-xs font-normal text-gray-500">h logged</span>
            </div>
        </div>
    </div>

    {{-- Unattributed Worklogs Alert Card --}}
    @if ($unattributedHours > 0)
        <div class="p-4 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <div class="p-1.5 bg-amber-100 dark:bg-amber-900/60 rounded-lg text-amber-700 dark:text-amber-300 shrink-0">
                    <x-heroicon-m-exclamation-triangle class="w-5 h-5" />
                </div>
                <div class="space-y-1">
                    <h4 class="text-xs font-bold text-amber-900 dark:text-amber-200">
                        {{ $unattributedHours }} hours logged by unmapped authors
                    </h4>
                    <p class="text-xs text-amber-800 dark:text-amber-300">
                        Unmatched authors: <strong>{{ implode(', ', array_slice($unattributedAuthors, 0, 5)) }}</strong>
                    </p>
                    <p class="text-[11px] text-amber-700 dark:text-amber-400">
                        These worklogs are included in customer totals, but need Jira/provider mapping under Connected Accounts to link to internal engineers.
                    </p>
                </div>
            </div>
            <div class="shrink-0">
                <a
                    href="{{ url('/admin/connected-accounts') }}"
                    class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-600 hover:bg-amber-500 text-white shadow-sm transition"
                >
                    Review Connected Accounts
                </a>
            </div>
        </div>
    @endif

    {{-- Customer Breakdown Table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border border-gray-200 dark:border-gray-800 rounded-xl divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-300 uppercase tracking-wider font-semibold">
                <tr>
                    <th class="py-3 px-4">Customer Account</th>
                    <th class="py-3 px-4">Actual vs. Allocated Hours</th>
                    <th class="py-3 px-4">Budget Utilization</th>
                    <th class="py-3 px-4">Allocation Status</th>
                    <th class="py-3 px-4">Active Contributors</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($byCustomer as $c)
                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition">
                        <td class="py-3 px-4 font-semibold text-gray-900 dark:text-gray-100">
                            {{ $c['client_name'] }}
                        </td>
                        <td class="py-3 px-4 font-medium">
                            <span class="font-bold text-gray-900 dark:text-gray-100">{{ $c['hours'] ?? $c['actual_hours'] ?? 0 }}h</span>
                            @if ($c['allocated_hours'])
                                <span class="text-gray-500"> / {{ $c['allocated_hours'] }}h</span>
                            @else
                                <span class="text-gray-400 text-[11px] italic"> (No monthly budget set)</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 w-44">
                            @php
                                $utilPct = $c['utilization_pct'] ?? $c['allocation_pct'] ?? null;
                                $status = $c['status'] ?? $c['allocation_status'] ?? 'unbudgeted';
                                $actHours = $c['hours'] ?? $c['actual_hours'] ?? 0;
                            @endphp
                            @if ($utilPct !== null)
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                                        <div
                                            class="h-full rounded-full transition-all duration-300
                                                {{ $utilPct >= 100 ? 'bg-rose-500' : ($utilPct >= 85 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                                            style="width: {{ min(100, $utilPct) }}%"
                                        ></div>
                                    </div>
                                    <span class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 w-10 text-right">
                                        {{ $utilPct }}%
                                    </span>
                                </div>
                            @else
                                <span class="text-gray-400 text-[11px]">-</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            @if ($status === 'on_budget')
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                                    On Budget
                                </span>
                            @elseif ($status === 'approaching')
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                                    Approaching Cap ({{ $utilPct }}%)
                                </span>
                            @elseif ($status === 'over_allocation')
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-rose-100 dark:bg-rose-950 text-rose-800 dark:text-rose-300">
                                    Over Budget (+{{ round($actHours - $c['allocated_hours'], 1) }}h)
                                </span>
                            @else
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
                                    Unbudgeted
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-gray-600 dark:text-gray-400">
                            @if (! empty($c['contributors']))
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($c['contributors'] as $dev)
                                        <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                            {{ $dev['name'] }} ({{ $dev['hours'] }}h)
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-gray-400 text-[11px]">No contributors</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-6 text-center text-gray-500 dark:text-gray-400">
                            No active customer worklogs found this month.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
