@php
    $targetUser = $this->getTargetUser();
    $data = $this->getHoursData();
    $totalHours = $data['total_hours'] ?? 0.0;
    $periodLabel = $data['period_label'] ?? now()->format('M 1 - M j, Y');
    $byCustomer = $data['by_customer'] ?? [];
    $missingCount = $data['missing_time_count'] ?? 0;
    $capacityValidated = $data['capacity_validated'] ?? false;
    $workingCapacityHours = $data['working_capacity_hours'] ?? null;
@endphp

<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-6 space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-gray-200 dark:border-gray-800">
        <div>
            <div class="flex items-center gap-2">
                <div class="p-2 bg-blue-50 dark:bg-blue-950 text-blue-600 dark:text-blue-400 rounded-lg">
                    <x-heroicon-m-clock class="w-5 h-5" />
                </div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ $targetUser && $targetUser->id !== auth()->id() ? "{$targetUser->name}'s Hours This Month" : 'My Hours This Month' }}
                </h2>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Reporting Period: <strong>{{ $periodLabel }}</strong>
            </p>
        </div>

        @if ($missingCount > 0)
            <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-900 text-xs font-semibold text-amber-800 dark:text-amber-300">
                <x-heroicon-m-exclamation-triangle class="w-4 h-4 text-amber-600" />
                <span>{{ $missingCount }} completed task(s) missing time logs</span>
            </div>
        @endif
    </div>

    {{-- Top Overview Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="p-4 bg-gray-50 dark:bg-gray-800/40 rounded-xl border border-gray-100 dark:border-gray-800">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Logged Hours</span>
            <div class="text-2xl font-extrabold text-gray-900 dark:text-gray-100 mt-1">
                {{ $totalHours }}<span class="text-sm font-normal text-gray-500">h</span>
            </div>
            @if ($capacityValidated && $workingCapacityHours)
                <span class="text-[11px] text-gray-500">Target capacity: ~{{ $workingCapacityHours }}h</span>
            @endif
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-800/40 rounded-xl border border-gray-100 dark:border-gray-800">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Customers Supported</span>
            <div class="text-2xl font-extrabold text-gray-900 dark:text-gray-100 mt-1">
                {{ count($byCustomer) }}
            </div>
            <span class="text-[11px] text-gray-500">Active accounts this month</span>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-800/40 rounded-xl border border-gray-100 dark:border-gray-800">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Time Compliance</span>
            <div class="text-2xl font-extrabold {{ $missingCount === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }} mt-1">
                {{ $missingCount === 0 ? '100%' : 'Needs Review' }}
            </div>
            <span class="text-[11px] text-gray-500">{{ $missingCount === 0 ? 'All closed tasks logged' : $missingCount . ' task(s) unlogged' }}</span>
        </div>
    </div>

    {{-- Breakdown by Customer and Project --}}
    <div class="space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Customer Allocation Breakdown</h3>

        @if (empty($byCustomer))
            <div class="text-center py-6 text-xs text-gray-500 dark:text-gray-400">
                No worklogs recorded yet for this billing cycle.
            </div>
        @else
            <div class="space-y-4">
                @foreach ($byCustomer as $c)
                    <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $c['client_name'] }}</h4>
                            </div>
                            <div class="text-right">
                                <span class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $c['hours'] }}h</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">({{ $c['share_pct'] }}%)</span>
                            </div>
                        </div>

                        {{-- Progress bar --}}
                        <div class="w-full h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                            <div
                                class="h-full bg-primary-600 dark:bg-primary-500 rounded-full transition-all duration-300"
                                style="width: {{ min(100, $c['share_pct']) }}%"
                            ></div>
                        </div>

                        {{-- Projects sub-list --}}
                        @if (! empty($c['projects']))
                            <div class="pl-2 border-l-2 border-gray-100 dark:border-gray-800 space-y-1">
                                @foreach ($c['projects'] as $proj)
                                    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                        <span>• {{ $proj['project_name'] }}</span>
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $proj['hours'] }}h</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
