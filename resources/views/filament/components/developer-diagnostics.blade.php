@props([
    'diagnostics' => [],
])

@if(!empty($diagnostics['kpis']))
    <div x-data="{ open: false }" class="mt-8 border-t border-gray-200 pt-6 dark:border-gray-800">
        {{-- Toggle Header --}}
        <div class="flex items-center justify-between">
            <button
                @click="open = !open"
                type="button"
                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-2xs transition hover:bg-gray-50 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
            >
                <svg class="h-4 w-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />
                </svg>
                <span>Developer KPI Diagnostics & Telemetry</span>
                <span x-text="open ? '▲ Hide' : '▼ View Diagnostics'" class="text-[0.65rem] text-gray-400"></span>
            </button>

            @if(!empty($diagnostics['reconciliation']))
                <div class="flex items-center gap-2 text-xs">
                    <span class="font-medium text-gray-500">Reconciliation:</span>
                    @if($diagnostics['reconciliation']['triggers_discrepancy_warning'])
                        <span class="rounded bg-rose-100 px-2 py-0.5 font-bold text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                            Warning: {{ $diagnostics['reconciliation']['transaction_diff_percent'] }}% Tx Diff
                        </span>
                    @else
                        <span class="rounded bg-emerald-100 px-2 py-0.5 font-bold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                            Reconciled ({{ $diagnostics['reconciliation']['transaction_diff_percent'] }}% Tx Diff)
                        </span>
                    @endif
                </div>
            @endif
        </div>

        {{-- Expanded Diagnostics Content --}}
        <div x-show="open" x-cloak class="mt-4 rounded-xl border border-gray-200 bg-gray-900 p-4 font-mono text-xs text-gray-200 shadow-xl dark:border-gray-800">
            <div class="mb-3 flex flex-wrap items-center justify-between border-b border-gray-800 pb-2 text-[0.7rem] text-gray-400">
                <div>Client: <strong class="text-white">{{ $diagnostics['client']['name'] ?? 'N/A' }}</strong> ({{ $diagnostics['client']['platform_type'] ?? 'N/A' }})</div>
                <div>Timezone: <strong class="text-white">{{ $diagnostics['client']['timezone'] ?? 'N/A' }}</strong></div>
                <div>Current Period: <strong class="text-white">{{ $diagnostics['period']['current_start'] ?? '' }}</strong> → <strong class="text-white">{{ $diagnostics['period']['current_end'] ?? '' }}</strong></div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-gray-800 text-[0.65rem] uppercase tracking-wider text-gray-400">
                            <th class="py-2 pe-3">KPI Name</th>
                            <th class="py-2 pe-3">Current Val (Num / Denom)</th>
                            <th class="py-2 pe-3">Prior Val (Num / Denom)</th>
                            <th class="py-2 pe-3">Formula</th>
                            <th class="py-2 pe-3">Data Source & Fields</th>
                            <th class="py-2">Filter Scope</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800/60">
                        @foreach($diagnostics['kpis'] as $key => $kpi)
                            <tr class="hover:bg-gray-800/40">
                                <td class="py-2.5 pe-3 font-semibold text-cyan-400">{{ $kpi['display_name'] }}</td>
                                <td class="py-2.5 pe-3 text-emerald-400">
                                    <strong>{{ $kpi['current_value'] }}</strong>
                                    <div class="text-[0.65rem] text-gray-400">({{ number_format($kpi['current_numerator']) }} / {{ number_format($kpi['current_denominator']) }})</div>
                                </td>
                                <td class="py-2.5 pe-3 text-slate-300">
                                    <strong>{{ $kpi['prior_value'] }}</strong>
                                    <div class="text-[0.65rem] text-gray-400">({{ number_format($kpi['prior_numerator']) }} / {{ number_format($kpi['prior_denominator']) }})</div>
                                </td>
                                <td class="py-2.5 pe-3 text-gray-300 text-[0.65rem]">{{ $kpi['formula'] }}</td>
                                <td class="py-2.5 pe-3 text-gray-400 text-[0.65rem]">
                                    <div class="text-indigo-300">{{ $kpi['data_source'] }}</div>
                                    <div class="text-[0.6rem] text-gray-500">{{ $kpi['api_or_db_fields'] }}</div>
                                </td>
                                <td class="py-2.5 text-gray-400 text-[0.6rem]">
                                    <div>{{ $kpi['filters'] }}</div>
                                    <div class="text-gray-500">TZ: {{ $kpi['timezone'] }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
