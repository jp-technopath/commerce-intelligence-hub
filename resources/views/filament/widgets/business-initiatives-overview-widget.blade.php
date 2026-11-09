@php
    $initiatives = $this->initiatives;
    $selectedInitiative = $this->selectedInitiative;
    $auditFinding = $this->auditFinding;

    // Portfolio Totals (Strictly Segregated)
    $totalReorderValue = $initiatives->sum(fn($i) => $i['financial_metrics']['historical_reorder_value'] ?? 0.0);
    $totalSpendingDecline = $initiatives->sum(fn($i) => $i['financial_metrics']['observed_spending_decline'] ?? 0.0);
    $totalProvenAccounts = $initiatives->sum('proven_accounts_count');
    $totalProvisionalAccounts = $initiatives->sum('provisional_accounts_count');
    $totalAccounts = $initiatives->sum('qualifying_accounts_count');
@endphp

<div class="space-y-6">
    {{-- Header & Filters Bar --}}
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-5 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-gray-200 dark:border-gray-800">
            <div>
                <div class="flex items-center gap-2">
                    <div class="p-2 bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 rounded-lg">
                        <x-heroicon-m-briefcase class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Strategic Business Initiatives</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Executive opportunity and risk portfolio answering the 5 management questions with verified, segregated financials.
                        </p>
                    </div>
                </div>
            </div>

            {{-- Dynamic Filters --}}
            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <select
                        wire:model.live="selectedClientId"
                        class="text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    >
                        <option value="">All Customers</option>
                        @foreach ($this->clients as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <select
                        wire:model.live="selectedPillar"
                        class="text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    >
                        <option value="">All Pillars</option>
                        <option value="growth_opportunity">Growth Opportunities</option>
                        <option value="business_risk">Business Risks</option>
                        <option value="technical_health">Technical Health</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Executive Portfolio Summary Badges (Segregated Financials) --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 pt-1">
            <div class="p-3 bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700/60 rounded-xl">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Active Initiatives</span>
                <div class="text-xl font-black text-gray-900 dark:text-gray-100 mt-1">
                    {{ $initiatives->count() }}
                </div>
                <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ $totalAccounts }} accounts ({{ $totalProvenAccounts }} proven, {{ $totalProvisionalAccounts }} provisional)</span>
            </div>

            <div class="p-3 bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900/40 rounded-xl">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Reorder Value</span>
                    <span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-emerald-100 dark:bg-emerald-900 text-emerald-700 dark:text-emerald-200">Verified</span>
                </div>
                <div class="text-xl font-black text-emerald-700 dark:text-emerald-400 mt-1">
                    ${{ number_format($totalReorderValue, 2) }}
                </div>
                <span class="text-[11px] text-emerald-600/80 dark:text-emerald-400/70">Historical repeat purchasing</span>
            </div>

            <div class="p-3 bg-rose-50/60 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/40 rounded-xl">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-rose-800 dark:text-rose-300 uppercase tracking-wider">Spending Decline</span>
                    <span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-rose-100 dark:bg-rose-900 text-rose-700 dark:text-rose-200">Observed</span>
                </div>
                <div class="text-xl font-black text-rose-700 dark:text-rose-400 mt-1">
                    ${{ number_format($totalSpendingDecline, 2) }}
                </div>
                <span class="text-[11px] text-rose-600/80 dark:text-rose-400/70">Account contraction at risk</span>
            </div>

            <div class="p-3 bg-amber-50/60 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/40 rounded-xl">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-amber-800 dark:text-amber-300 uppercase tracking-wider">Provisional Accounts</span>
                    <span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-200">Review Queue</span>
                </div>
                <div class="text-xl font-black text-amber-700 dark:text-amber-400 mt-1">
                    {{ $totalProvisionalAccounts }}
                </div>
                <span class="text-[11px] text-amber-600/80 dark:text-amber-400/70">Unverified cadence; manual review</span>
            </div>
        </div>
    </div>

    {{-- Initiative Cards Grid --}}
    @if ($initiatives->isEmpty())
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-12 text-center">
            <x-heroicon-o-check-circle class="w-12 h-12 text-emerald-500 mx-auto mb-3" />
            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">No Actionable Initiatives Required</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-md mx-auto">
                All client accounts are purchasing within expected cadence run-rates or data baselines are maturing.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach ($initiatives as $initiative)
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm hover:shadow-md transition flex flex-col justify-between overflow-hidden">
                    {{-- Card Header --}}
                    <div class="p-5 border-b border-gray-100 dark:border-gray-800/80 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="px-2 py-0.5 text-[11px] font-bold rounded-md bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                        {{ $initiative['client_name'] }}
                                    </span>
                                    <span class="px-2 py-0.5 text-[11px] font-semibold rounded-md {{ $initiative['pillar'] === 'business_risk' ? 'bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300' : ($initiative['pillar'] === 'growth_opportunity' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-800 text-gray-700') }}">
                                        {{ $initiative['pillar_label'] }}
                                    </span>
                                    <span class="px-2 py-0.5 text-[11px] font-semibold rounded-md bg-amber-50 dark:bg-amber-950 text-amber-700 dark:text-amber-300">
                                        {{ $initiative['execution_status']['status_label'] ?? $initiative['execution_status']['label'] ?? 'New' }}
                                    </span>
                                    @if (!empty($initiative['has_provisional_accounts']))
                                        <span class="px-2 py-0.5 text-[11px] font-semibold rounded-md bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                                            {{ $initiative['provisional_notice'] }}
                                        </span>
                                    @endif
                                </div>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                    {{ $initiative['strategic_goal'] }}
                                </h3>
                            </div>

                            {{-- Financial Value Badge --}}
                            <div class="text-right flex-shrink-0">
                                <div class="text-xl font-black text-gray-900 dark:text-gray-100">
                                    {{ $initiative['formatted_primary_financial'] }}
                                </div>
                                <span class="inline-block mt-0.5 px-2 py-0.5 text-[10px] font-bold rounded {{ $initiative['primary_financial_nature'] === 'observed_spending_decline' ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/60 dark:text-rose-200' : ($initiative['primary_financial_nature'] === 'historical_reorder_value' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200') }}">
                                    {{ $initiative['primary_financial_label'] }}
                                </span>
                            </div>
                        </div>

                        {{-- Question 1: What business opportunity exists? --}}
                        <p class="text-xs text-gray-600 dark:text-gray-300 leading-relaxed">
                            {{ $initiative['business_opportunity'] }}
                        </p>
                    </div>

                    {{-- Card Body: 5 Management Questions Synthesis --}}
                    <div class="p-5 space-y-3.5 bg-gray-50/50 dark:bg-gray-900/40 text-xs">
                        {{-- Question 2: Why should we care? --}}
                        <div class="flex items-start gap-2">
                            <x-heroicon-m-chart-bar class="w-4 h-4 text-primary-500 mt-0.5 flex-shrink-0" />
                            <div>
                                <span class="font-bold text-gray-900 dark:text-gray-200">Commercial Rationale:</span>
                                <span class="text-gray-600 dark:text-gray-400 ml-1">{{ $initiative['business_rationale'] }}</span>
                            </div>
                        </div>

                        {{-- Question 4: What should we do next? --}}
                        <div class="flex items-start gap-2">
                            <x-heroicon-m-arrow-right-circle class="w-4 h-4 text-emerald-500 mt-0.5 flex-shrink-0" />
                            <div>
                                <span class="font-bold text-gray-900 dark:text-gray-200">Recommended Action:</span>
                                <span class="text-gray-600 dark:text-gray-400 ml-1">{{ $initiative['recommended_action'] }}</span>
                            </div>
                        </div>

                        {{-- Question 5: Who is responsible? --}}
                        <div class="flex items-center justify-between pt-2 border-t border-gray-200/60 dark:border-gray-800 text-[11px]">
                            <div class="flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                                <x-heroicon-m-user class="w-3.5 h-3.5" />
                                <span>Target Role: <strong class="text-gray-800 dark:text-gray-200">{{ $initiative['target_role_label'] }}</strong></span>
                            </div>

                            <div class="text-gray-500 dark:text-gray-400">
                                <span>Accounts: <strong class="text-gray-800 dark:text-gray-200">{{ $initiative['qualifying_accounts_count'] }}</strong> ({{ $initiative['proven_accounts_count'] }} proven, {{ $initiative['provisional_accounts_count'] }} provisional)</span>
                            </div>
                        </div>
                    </div>

                    {{-- Card Actions Footer --}}
                    <div class="p-4 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                        <button
                            type="button"
                            wire:click="openDrillDown('{{ $initiative['id'] }}')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950 hover:bg-primary-100 rounded-lg border border-primary-200 dark:border-primary-800 transition"
                        >
                            <x-heroicon-m-magnifying-glass-plus class="w-4 h-4" />
                            <span>Drill-Down Accounts ({{ $initiative['qualifying_accounts_count'] }})</span>
                        </button>

                        <button
                            type="button"
                            wire:click="openBatchApproveModal('{{ $initiative['id'] }}')"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950 hover:bg-emerald-100 rounded-lg border border-emerald-200 dark:border-emerald-800 transition"
                            title="Open batch approval confirmation modal"
                        >
                            <x-heroicon-m-check class="w-3.5 h-3.5" />
                            <span>Batch Approve</span>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Account Drill-Down Modal / Slideover --}}
    @if ($selectedInitiative)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-2xl max-w-5xl w-full max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                {{-- Modal Header --}}
                <div class="p-6 border-b border-gray-200 dark:border-gray-800 flex items-start justify-between gap-4 bg-gray-50/70 dark:bg-gray-800/40">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 text-xs font-bold rounded bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                                {{ $selectedInitiative['client_name'] }}
                            </span>
                            <span class="px-2 py-0.5 text-xs font-semibold rounded bg-primary-100 dark:bg-primary-950 text-primary-700 dark:text-primary-300">
                                {{ $selectedInitiative['strategic_goal'] }}
                            </span>
                        </div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-2">
                            Account-Level Drill-Down & Evidence
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ $selectedInitiative['business_rationale'] }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="text-right mr-3">
                            <div class="text-lg font-black text-gray-900 dark:text-gray-100">
                                {{ $selectedInitiative['formatted_primary_financial'] }}
                            </div>
                            <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400">
                                {{ $selectedInitiative['primary_financial_label'] }}
                            </span>
                        </div>

                        <button
                            type="button"
                            wire:click="closeDrillDown"
                            class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                        >
                            <x-heroicon-m-x-mark class="w-6 h-6" />
                        </button>
                    </div>
                </div>

                {{-- Modal Table of Accounts --}}
                <div class="p-6 overflow-y-auto space-y-4">
                    <div class="border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden shadow-sm">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-100/70 dark:bg-gray-800/80 text-gray-600 dark:text-gray-300 font-semibold border-b border-gray-200 dark:border-gray-800">
                                    <th class="p-3">Account Identity</th>
                                    <th class="p-3">Purchasing Cadence</th>
                                    <th class="p-3">Commercial Value</th>
                                    <th class="p-3">Suggested Action</th>
                                    <th class="p-3">Status / Assignee</th>
                                    <th class="p-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                @foreach ($selectedInitiative['accounts'] as $acc)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition">
                                        {{-- Account Identity --}}
                                        <td class="p-3 align-top">
                                            <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-1.5 flex-wrap">
                                                <span>{{ $acc['primary_name'] ?? $acc['customer_name'] ?? $acc['account_label'] }}</span>
                                                @if (!empty($acc['customer_id']))
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300 border border-blue-200 dark:border-blue-800" title="Magento Customer ID">
                                                        CID: #{{ $acc['customer_id'] }}
                                                    </span>
                                                @endif
                                            </div>

                                            @if (!empty($acc['customer_email']))
                                                <div class="text-xs text-primary-600 dark:text-primary-400 flex items-center gap-1 mt-0.5 font-medium">
                                                    <svg class="w-3.5 h-3.5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                                    <span>{{ $acc['customer_email'] }}</span>
                                                </div>
                                            @else
                                                <div class="text-[11px] text-gray-400 italic mt-0.5 flex items-center gap-1">
                                                    <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                                    <span>Email unavailable</span>
                                                </div>
                                            @endif

                                            @if (!empty($acc['account_hash'] ?? $acc['account_identity_hash'] ?? null))
                                                <div class="text-[10px] text-gray-400 font-mono mt-0.5" title="HMAC Customer Identity Hash: {{ $acc['account_hash'] ?? $acc['account_identity_hash'] }}">
                                                    #{{ substr($acc['account_hash'] ?? $acc['account_identity_hash'], 0, 8) }}
                                                </div>
                                            @endif
                                            @if (!empty($acc['is_provisional']))
                                                <span class="inline-block mt-1 px-1.5 py-0.5 text-[9px] font-bold rounded bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                    Provisional (Manual Review Required)
                                                </span>
                                            @else
                                                <span class="inline-block mt-1 px-1.5 py-0.5 text-[9px] font-semibold rounded bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                                    Verified Repeat Cadence
                                                </span>
                                            @endif
                                            @if ($acc['has_outlier_normalization'])
                                                <span class="inline-block mt-1 px-1.5 py-0.5 text-[9px] font-semibold rounded bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300" title="{{ $acc['outlier_notes'] }}">
                                                    Cadence Normalized
                                                </span>
                                            @endif
                                        </td>

                                        {{-- Purchasing Cadence & History --}}
                                        <td class="p-3 align-top space-y-1">
                                            @if ($acc['cadence'])
                                                <div>
                                                    <span class="text-gray-500">Cadence:</span>
                                                    <strong class="text-gray-800 dark:text-gray-200">{{ $acc['cadence'] }}d</strong>
                                                </div>
                                            @endif
                                            @if ($acc['days_since_last_order'])
                                                <div>
                                                    <span class="text-gray-500">Days Inactive:</span>
                                                    <strong class="text-gray-800 dark:text-gray-200">{{ $acc['days_since_last_order'] }}d</strong>
                                                </div>
                                            @endif
                                            @if ($acc['baseline_spend'] && $acc['eval_spend'] !== null)
                                                <div class="text-[11px] text-rose-600 dark:text-rose-400">
                                                    <span>Drop: ${{ number_format($acc['baseline_spend'], 2) }} &rarr; ${{ number_format($acc['eval_spend'], 2) }}</span>
                                                </div>
                                            @endif
                                            <div class="text-[10px] text-gray-400">
                                                Lifetime: ${{ number_format($acc['lifetime_spend'] ?? $acc['lifetime_net_revenue'] ?? 0.0, 2) }} ({{ $acc['lifetime_orders'] ?? 0 }} orders)
                                            </div>
                                        </td>

                                        {{-- Commercial Impact --}}
                                        <td class="p-3 align-top">
                                            @if (!empty($acc['is_provisional']))
                                                <div class="font-bold text-gray-500 dark:text-gray-400">
                                                    $0.00 <span class="text-[10px] font-normal text-amber-600 dark:text-amber-400">(Unverified Pipeline)</span>
                                                </div>
                                                <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">
                                                    Prior Spend: <strong class="text-gray-700 dark:text-gray-300">${{ number_format($acc['prior_spend'], 2) }}</strong>
                                                </div>
                                                <span class="inline-block mt-0.5 px-1.5 py-0.5 text-[9px] font-bold rounded bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                    Directional Past Spend
                                                </span>
                                            @else
                                                <div class="font-bold text-gray-900 dark:text-gray-100">
                                                    ${{ number_format($acc['financial_impact'], 2) }}
                                                </div>
                                                <span class="inline-block mt-0.5 px-1.5 py-0.5 text-[9px] font-bold rounded {{ $acc['impact_nature'] === 'observed_spending_decline' ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/60 dark:text-rose-200' : ($acc['impact_nature'] === 'historical_reorder_value' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200') }}">
                                                    {{ $acc['impact_nature_label'] }}
                                                </span>
                                            @endif
                                            <div class="text-[10px] text-gray-400 mt-1">
                                                Priority: {{ round($acc['priority_score'], 1) }} &bull; Conf: {{ round($acc['confidence_score'], 1) }}
                                            </div>
                                        </td>

                                        {{-- Suggested Action --}}
                                        <td class="p-3 align-top max-w-xs text-gray-600 dark:text-gray-300">
                                            {{ $acc['suggested_action'] }}
                                        </td>

                                        {{-- Status / Assignee --}}
                                        <td class="p-3 align-top space-y-1">
                                            <div class="flex items-center gap-1 flex-wrap">
                                                <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                                    {{ $acc['status_label'] }}
                                                </span>
                                                @if (!empty($acc['execution_status']) && $acc['execution_status'] !== 'new')
                                                    <span class="px-1.5 py-0.5 text-[9px] font-semibold rounded bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                                        {{ $acc['execution_status_label'] }}
                                                    </span>
                                                @endif
                                                @if (!empty($acc['outcome_status']) && $acc['outcome_status'] !== 'pending_execution')
                                                    <span class="px-1.5 py-0.5 text-[9px] font-semibold rounded {{ $acc['outcome_status'] === 'outcome_measured' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300' }}" title="Commercial outcome status (distinct from operational action completion)">
                                                        Outcome: {{ $acc['outcome_status_label'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            @if ($acc['execution_status'] === 'action_completed' && $acc['outcome_status'] === 'awaiting_results')
                                                <div class="text-[10px] text-amber-700 dark:text-amber-400 font-medium">
                                                    &bull; Action done &bull; Observing revenue (no recovery claimed)
                                                </div>
                                            @elseif ($acc['outcome_status'] === 'outcome_measured')
                                                @if (!empty($acc['has_observed_orders']))
                                                    <div class="text-[10px] text-emerald-700 dark:text-emerald-400 font-medium">
                                                        &bull; Verified observed orders: ${{ number_format($acc['observed_revenue'], 2) }}
                                                        @if (!empty($acc['has_modeled_lift']))
                                                            <span class="text-gray-500 font-normal">(Modeled lift: ${{ number_format($acc['modeled_lift'], 2) }} &bull; non-causal)</span>
                                                        @else
                                                            <span class="text-gray-500 font-normal">(Within historical baseline)</span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <div class="text-[10px] text-gray-500 dark:text-gray-400">
                                                        &bull; 0 post-action orders observed during observation window
                                                    </div>
                                                @endif
                                            @endif
                                            @if (!empty($acc['pm_work_item_key']))
                                                <div>
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300" title="{{ $acc['pm_work_item_title'] }} ({{ $acc['pm_work_item_status'] }})">
                                                        Jira: {{ $acc['pm_work_item_key'] }}
                                                    </span>
                                                </div>
                                            @endif
                                            @if ($acc['assigned_user_name'])
                                                <div class="text-[11px] text-gray-700 dark:text-gray-300 font-medium">
                                                    &bull; {{ $acc['assigned_user_name'] }}
                                                    @if (!empty($acc['target_role']))
                                                        <span class="text-[10px] text-gray-400 capitalize">({{ str_replace('_', ' ', $acc['target_role']) }})</span>
                                                    @endif
                                                </div>
                                            @else
                                                <div class="text-[10px] text-gray-400 italic">
                                                    Unassigned
                                                    @if (!empty($acc['target_role']))
                                                        <span class="text-[10px] text-gray-400 capitalize">({{ str_replace('_', ' ', $acc['target_role']) }})</span>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($acc['approved_by_name'])
                                                <div class="text-[10px] text-emerald-600 dark:text-emerald-400">
                                                    Approved by {{ $acc['approved_by_name'] }}
                                                </div>
                                            @endif
                                        </td>

                                        {{-- Lifecycle Actions --}}
                                        <td class="p-3 align-top text-right space-y-1">
                                            <div class="flex items-center justify-end gap-1 flex-wrap">
                                                @if ($acc['status'] === 'new')
                                                    <button
                                                        type="button"
                                                        wire:click="reviewFinding({{ $acc['finding_id'] }})"
                                                        class="px-2 py-1 text-[11px] font-medium rounded bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950 dark:text-amber-300"
                                                    >
                                                        Review
                                                    </button>
                                                @endif

                                                @if (in_array($acc['status'], ['new', 'under_review']))
                                                    <button
                                                        type="button"
                                                        wire:click="approveFinding({{ $acc['finding_id'] }})"
                                                        class="px-2 py-1 text-[11px] font-medium rounded bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300"
                                                    >
                                                        Approve
                                                    </button>
                                                @endif

                                                @if (in_array($acc['status'], ['approved', 'assigned']) && empty($acc['pm_work_item_id']))
                                                    <button
                                                        type="button"
                                                        wire:click="openCreatePmModal({{ $acc['finding_id'] }})"
                                                        class="px-2 py-1 text-[11px] font-medium rounded bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950 dark:text-indigo-300"
                                                        title="Create Jira Work Item"
                                                    >
                                                        + Jira
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="openLinkPmModal({{ $acc['finding_id'] }})"
                                                        class="px-2 py-1 text-[11px] font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300"
                                                        title="Link Existing Jira Work Item"
                                                    >
                                                        Link
                                                    </button>
                                                @endif

                                                <button
                                                    type="button"
                                                    wire:click="openAssignModal({{ $acc['finding_id'] }})"
                                                    class="px-2 py-1 text-[11px] font-medium rounded bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950 dark:text-blue-300"
                                                >
                                                    Assign
                                                </button>

                                                @if ($acc['status'] !== 'resolved')
                                                    <button
                                                        type="button"
                                                        wire:click="openResolveModal({{ $acc['finding_id'] }})"
                                                        class="px-2 py-1 text-[11px] font-medium rounded bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-950 dark:text-green-300"
                                                    >
                                                        Resolve
                                                    </button>
                                                @endif

                                                <button
                                                    type="button"
                                                    wire:click="openDismissModal({{ $acc['finding_id'] }})"
                                                    class="px-2 py-1 text-[11px] font-medium rounded bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400"
                                                    title="Dismiss"
                                                >
                                                    Dismiss
                                                </button>

                                                <button
                                                    type="button"
                                                    wire:click="openAuditModal({{ $acc['finding_id'] }})"
                                                    class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                                    title="Audit Trail"
                                                >
                                                    <x-heroicon-m-clock class="w-4 h-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Modal Footer --}}
                <div class="p-4 bg-gray-50 dark:bg-gray-800/40 border-t border-gray-200 dark:border-gray-800 flex justify-end">
                    <button
                        type="button"
                        wire:click="closeDrillDown"
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600 transition"
                    >
                        Close Drill-Down
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Assign Finding Modal --}}
    @if ($assignFindingId)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Assign Finding to Team Member</h3>
                <p class="text-xs text-gray-500">Select an authorized Forge team member eligible to work on this customer account.</p>

                @php $modalCust = $this->getActiveFindingCustomerDisplay(); @endphp
                @if (!empty($modalCust))
                    <div class="p-2.5 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700/60 flex items-center justify-between text-xs">
                        <div>
                            <span class="text-gray-500 font-medium">Customer:</span>
                            <strong class="text-gray-900 dark:text-gray-100 ml-1">{{ $modalCust['primary_name'] ?? 'Account' }}</strong>
                            @if ($modalCust['has_email'])
                                <span class="text-primary-600 dark:text-primary-400 font-normal ml-1">({{ $modalCust['customer_email'] }})</span>
                            @else
                                <span class="text-gray-400 italic ml-1">(Email unavailable)</span>
                            @endif
                        </div>
                        @if ($modalCust['secondary_badge'])
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                {{ $modalCust['secondary_badge'] }}
                            </span>
                        @endif
                    </div>
                @endif

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Assignee</label>
                        <select
                            wire:model="assigneeUserId"
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                        >
                            @foreach ($this->assignableUsers as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('assigneeUserId')
                            <span class="text-[11px] text-rose-600 dark:text-rose-400 block mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Target Business Role</label>
                        <select
                            wire:model="assignTargetRole"
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                        >
                            <option value="sales">Sales (Account Executive / SDR)</option>
                            <option value="marketing">Marketing (Growth / Retention)</option>
                            <option value="engineering">Engineering (Platform / Integrations)</option>
                            <option value="analytics">Analytics (BI / Forecasting)</option>
                            <option value="project_management">Project Management (Delivery)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Assignment Instructions / Notes</label>
                        <textarea
                            wire:model="assignNotes"
                            rows="2"
                            placeholder="Optional instructions for the assignee..."
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                        ></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="closeAssignModal"
                        class="px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmAssign"
                        class="px-3 py-1.5 text-xs font-semibold text-white bg-primary-600 hover:bg-primary-700 rounded-lg shadow-sm"
                    >
                        Assign Finding
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Create PM Work Item Modal --}}
    @if ($createPmFindingId)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-gray-200 dark:border-gray-800">
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Create Jira Work Item</h3>
                    <button type="button" wire:click="closeCreatePmModal" class="p-1 text-gray-400 hover:text-gray-600 rounded">
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>
                <p class="text-xs text-gray-500">Create an execution ticket in the customer's Jira project with commercial evidence and account context pre-filled.</p>

                @php $modalCust = $this->getActiveFindingCustomerDisplay(); @endphp
                @if (!empty($modalCust))
                    <div class="p-2.5 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700/60 flex items-center justify-between text-xs">
                        <div>
                            <span class="text-gray-500 font-medium">Customer:</span>
                            <strong class="text-gray-900 dark:text-gray-100 ml-1">{{ $modalCust['primary_name'] ?? 'Account' }}</strong>
                            @if ($modalCust['has_email'])
                                <span class="text-primary-600 dark:text-primary-400 font-normal ml-1">({{ $modalCust['customer_email'] }})</span>
                            @else
                                <span class="text-gray-400 italic ml-1">(Email unavailable)</span>
                            @endif
                        </div>
                        @if ($modalCust['secondary_badge'])
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                {{ $modalCust['secondary_badge'] }}
                            </span>
                        @endif
                    </div>
                @endif

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Jira Project / Board</label>
                        <select
                            wire:model="createPmProjectId"
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                        >
                            <option value="">Select Project...</option>
                            @foreach ($this->projectsForCreatePm as $proj)
                                <option value="{{ $proj->id }}">{{ $proj->name }} ({{ $proj->external_project_key }})</option>
                            @endforeach
                        </select>
                        @error('createPmProjectId')
                            <span class="text-[11px] text-rose-600 dark:text-rose-400 block mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Issue Type</label>
                            <select
                                wire:model="createPmIssueType"
                                class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                            >
                                <option value="Task">Task</option>
                                <option value="Story">Story</option>
                                <option value="Action">Action</option>
                                <option value="Initiative">Initiative</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Priority</label>
                            <select
                                wire:model="createPmPriority"
                                class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                            >
                                <option value="Highest">Highest</option>
                                <option value="High">High</option>
                                <option value="Medium">Medium</option>
                                <option value="Low">Low</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Issue Title</label>
                        <input
                            type="text"
                            wire:model="createPmTitle"
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                        />
                        @error('createPmTitle')
                            <span class="text-[11px] text-rose-600 dark:text-rose-400 block mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Description & Evidence</label>
                        <textarea
                            wire:model="createPmDescription"
                            rows="5"
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-mono text-[11px]"
                        ></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="closeCreatePmModal"
                        class="px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmCreatePmWorkItem"
                        class="px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-sm"
                    >
                        Create Jira Issue
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Link PM Work Item Modal --}}
    @if ($linkPmFindingId)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-gray-200 dark:border-gray-800">
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Link Existing Jira Work Item</h3>
                    <button type="button" wire:click="closeLinkPmModal" class="p-1 text-gray-400 hover:text-gray-600 rounded">
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>
                <p class="text-xs text-gray-500">Connect this finding to an existing Jira issue for this customer to sync status without creating duplicates.</p>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Select Work Item</label>
                        <select
                            wire:model="linkPmWorkItemId"
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                        >
                            <option value="">Select Work Item...</option>
                            @foreach ($this->workItemsForLinkPm as $item)
                                <option value="{{ $item->id }}">{{ $item->external_item_key }}: {{ \Illuminate\Support\Str::limit($item->title, 40) }} ({{ $item->status }})</option>
                            @endforeach
                        </select>
                        @error('linkPmWorkItemId')
                            <span class="text-[11px] text-rose-600 dark:text-rose-400 block mt-1">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="closeLinkPmModal"
                        class="px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmLinkPmWorkItem"
                        class="px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-sm"
                    >
                        Link Jira Issue
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Resolve Finding Modal --}}
    @if ($resolveFindingId)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Complete Commercial Action</h3>
                <p class="text-xs text-gray-500">Record commercial outreach or technical work completed. Note: Marking an action completed documents representative execution and initiates the 30-day outcome observation window — it does NOT assume or claim recovered revenue.</p>

                @php $modalCust = $this->getActiveFindingCustomerDisplay(); @endphp
                @if (!empty($modalCust))
                    <div class="p-2.5 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700/60 flex items-center justify-between text-xs">
                        <div>
                            <span class="text-gray-500 font-medium">Customer:</span>
                            <strong class="text-gray-900 dark:text-gray-100 ml-1">{{ $modalCust['primary_name'] ?? 'Account' }}</strong>
                            @if ($modalCust['has_email'])
                                <span class="text-primary-600 dark:text-primary-400 font-normal ml-1">({{ $modalCust['customer_email'] }})</span>
                            @else
                                <span class="text-gray-400 italic ml-1">(Email unavailable)</span>
                            @endif
                        </div>
                        @if ($modalCust['secondary_badge'])
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                {{ $modalCust['secondary_badge'] }}
                            </span>
                        @endif
                    </div>
                @endif

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Action Type / Category</label>
                        <select
                            wire:model="businessOutcome"
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                        >
                            <option value="reorder_secured">Reorder Secured</option>
                            <option value="meeting_held">Client Meeting Held</option>
                            <option value="quote_submitted">Quote / Proposal Submitted</option>
                            <option value="cadence_normalized">Account Cadence Normalized</option>
                            <option value="technical_fix_deployed">Technical Fix Deployed</option>
                            <option value="no_action_needed">Reviewed - No Immediate Action</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Action Taken & Resolution Notes (Min 10 characters)</label>
                        <textarea
                            wire:model="resolutionNotes"
                            rows="3"
                            placeholder="e.g. Account re-engaged; quote submitted via wholesale portal. Observing reorders."
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                        ></textarea>
                        @error('resolutionNotes')
                            <span class="text-[11px] text-rose-600 dark:text-rose-400 block mt-1">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="closeResolveModal"
                        class="px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmResolve"
                        class="px-3 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-sm"
                    >
                        Mark Action Completed
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Dismiss Finding Modal --}}
    @if ($dismissFindingId)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Dismiss Finding</h3>
                <p class="text-xs text-gray-500">Specify why this account opportunity is being dismissed (e.g., account closed, known business change).</p>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Dismissal Reason</label>
                    <textarea
                        wire:model="dismissalReason"
                        rows="2"
                        placeholder="e.g. Customer permanently ceased operations in regional territory."
                        class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                    ></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="closeDismissModal"
                        class="px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmDismiss"
                        class="px-3 py-1.5 text-xs font-semibold text-white bg-gray-700 hover:bg-gray-800 rounded-lg shadow-sm"
                    >
                        Dismiss Finding
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Batch Approval Confirmation Modal --}}
    @if ($batchApproveInitiativeId && !empty($batchApproveSummary))
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-gray-200 dark:border-gray-800">
                    <div>
                        <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Batch Approval Confirmation</h3>
                        <p class="text-xs text-gray-500">{{ $batchApproveSummary['initiative_title'] }}</p>
                    </div>
                    <button type="button" wire:click="closeBatchApproveModal" class="p-1 text-gray-400 hover:text-gray-600 rounded">
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-3 text-xs">
                    <p class="text-gray-600 dark:text-gray-300">
                        Review the account eligibility breakdown below before executing batch approval:
                    </p>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="p-3 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                            <span class="font-bold text-emerald-800 dark:text-emerald-300 text-sm block">
                                {{ $batchApproveSummary['proven_eligible_count'] }}
                            </span>
                            <span class="text-[11px] text-emerald-700 dark:text-emerald-400">
                                Proven repeat accounts eligible for immediate approval
                            </span>
                        </div>

                        <div class="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-lg">
                            <span class="font-bold text-amber-800 dark:text-amber-300 text-sm block">
                                {{ $batchApproveSummary['provisional_count'] }}
                            </span>
                            <span class="text-[11px] text-amber-700 dark:text-amber-400">
                                Provisional accounts requiring individual review (Skipped)
                            </span>
                        </div>

                        <div class="p-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400">
                            <span class="font-bold text-gray-900 dark:text-gray-200 block">{{ $batchApproveSummary['already_approved_count'] }}</span>
                            <span class="text-[10px]">Already approved or assigned (No change)</span>
                        </div>

                        <div class="p-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400">
                            <span class="font-bold text-gray-900 dark:text-gray-200 block">{{ $batchApproveSummary['excluded_count'] }}</span>
                            <span class="text-[10px]">Resolved or dismissed accounts (Excluded)</span>
                        </div>
                    </div>

                    @if ($batchApproveSummary['provisional_count'] > 0)
                        <div class="p-2.5 bg-amber-50/70 dark:bg-amber-950/20 border border-amber-200/80 dark:border-amber-900/40 rounded-lg text-[11px] text-amber-800 dark:text-amber-300 flex items-start gap-1.5">
                            <x-heroicon-m-exclamation-triangle class="w-4 h-4 flex-shrink-0 mt-0.5 text-amber-600" />
                            <span>
                                <strong>Safeguard Active:</strong> {{ $batchApproveSummary['provisional_count'] }} provisional account(s) have unverified purchasing cadence and will NOT be batch-approved. They must be evaluated individually in the drill-down view.
                            </span>
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-200 dark:border-gray-800">
                    <button
                        type="button"
                        wire:click="closeBatchApproveModal"
                        class="px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmBatchApprove"
                        @if($batchApproveSummary['proven_eligible_count'] === 0) disabled @endif
                        class="px-4 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg shadow-sm"
                    >
                        Approve {{ $batchApproveSummary['proven_eligible_count'] }} Proven Accounts
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Audit Trail Modal --}}
    @if ($auditFinding)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Status Audit History</h3>
                        <p class="text-xs text-gray-500">{{ $auditFinding->title }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeAuditModal"
                        class="p-1 text-gray-400 hover:text-gray-600 rounded"
                    >
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>

                @php $modalCust = $this->getActiveFindingCustomerDisplay(); @endphp
                @if (!empty($modalCust))
                    <div class="p-2.5 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700/60 flex items-center justify-between text-xs">
                        <div>
                            <span class="text-gray-500 font-medium">Customer:</span>
                            <strong class="text-gray-900 dark:text-gray-100 ml-1">{{ $modalCust['primary_name'] ?? 'Account' }}</strong>
                            @if ($modalCust['has_email'])
                                <span class="text-primary-600 dark:text-primary-400 font-normal ml-1">({{ $modalCust['customer_email'] }})</span>
                            @else
                                <span class="text-gray-400 italic ml-1">(Email unavailable)</span>
                            @endif
                        </div>
                        @if ($modalCust['secondary_badge'])
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                {{ $modalCust['secondary_badge'] }}
                            </span>
                        @endif
                    </div>
                @endif

                <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                    @forelse ($auditFinding->status_history_json ?? [] as $entry)
                        <div class="p-3 bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/60 rounded-lg text-xs space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-gray-800 dark:text-gray-200">
                                    {{ ucfirst(str_replace('_', ' ', $entry['from_status'] ?? 'new')) }} &rarr; {{ ucfirst(str_replace('_', ' ', $entry['to_status'] ?? '')) }}
                                </span>
                                <span class="text-[10px] text-gray-400">
                                    {{ \Carbon\Carbon::parse($entry['changed_at'])->toDayDateTimeString() }}
                                </span>
                            </div>
                            <div class="text-gray-500">
                                Changed by: <strong class="text-gray-700 dark:text-gray-300">{{ $entry['changed_by_name'] ?? 'System' }}</strong>
                            </div>
                            @if (!empty($entry['business_outcome']))
                                <div class="text-[11px] text-emerald-700 dark:text-emerald-400 font-semibold">
                                    Commercial Outcome: {{ ucfirst(str_replace('_', ' ', $entry['business_outcome'])) }}
                                </div>
                            @endif
                            @if (!empty($entry['notes']))
                                <div class="text-[11px] text-gray-600 dark:text-gray-400 italic pt-1 border-t border-gray-200/60 dark:border-gray-700/40">
                                    &ldquo;{{ $entry['notes'] }}&rdquo;
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-xs text-gray-400 text-center py-4">
                            No status transitions recorded yet.
                        </div>
                    @endforelse
                </div>

                <div class="flex justify-end pt-2">
                    <button
                        type="button"
                        wire:click="closeAuditModal"
                        class="px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
