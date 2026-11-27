@php
    $initiatives = $this->initiatives;
    $selectedInitiative = $this->selectedInitiative;
    $auditFinding = $this->auditFinding;

    // Portfolio Totals (Strictly Deduplicated & Authoritative)
    $allItems = $initiatives->flatMap(fn($i) => $i['accounts']);
    $distinctFlaggedItems = $allItems
        ->filter(fn($a) => !empty($a['is_provisional']) || !empty($a['is_outdated']) || !empty($a['requires_revalidation']) || ($a['contact_status'] ?? '') === 'requires_review')
        ->unique(fn($a) => !empty($a['account_identity_hash']) ? ('acc_' . $a['account_identity_hash']) : ('finding_' . $a['finding_id']))
        ->values();

    $manualVerificationCount = $distinctFlaggedItems->count();
    $manualVerificationAccounts = $distinctFlaggedItems->where('is_account_opportunity', true)->count();
    $manualVerificationFindings = $distinctFlaggedItems->where('is_account_opportunity', false)->count();

    $totalAwaitingDecision = $initiatives->sum(fn($i) => ($i['review_progress']['awaiting_review'] ?? 0) + ($i['review_progress']['under_review'] ?? 0));
    $awaitingAccountsCount = $initiatives->where('is_account_based', true)->sum(fn($i) => ($i['review_progress']['awaiting_review'] ?? 0) + ($i['review_progress']['under_review'] ?? 0));
    $awaitingFindingsCount = $initiatives->where('is_account_based', false)->sum(fn($i) => ($i['review_progress']['awaiting_review'] ?? 0) + ($i['review_progress']['under_review'] ?? 0));
    $awaitingReviewParts = [];
    if ($awaitingAccountsCount > 0) {
        $awaitingReviewParts[] = $awaitingAccountsCount . ' ' . \Illuminate\Support\Str::plural('account', $awaitingAccountsCount);
    }
    if ($awaitingFindingsCount > 0) {
        $awaitingReviewParts[] = $awaitingFindingsCount . ' ' . \Illuminate\Support\Str::plural('finding', $awaitingFindingsCount);
    }
    $awaitingReviewSubtext = ! empty($awaitingReviewParts) ? implode(' · ', $awaitingReviewParts) : 'No items awaiting review';

    $totalApprovedOrAssigned = $initiatives->sum(fn($i) => $i['review_progress']['approved_or_assigned'] ?? 0);
    $totalWorkInProgress = $initiatives->sum(fn($i) => $i['review_progress']['work_in_progress'] ?? 0);
    $totalCompleted = $initiatives->sum(fn($i) => $i['review_progress']['completed'] ?? 0);
    $wipSubtextParts = [];
    if ($totalApprovedOrAssigned > 0) {
        $wipSubtextParts[] = "{$totalApprovedOrAssigned} approved/assigned";
    }
    if ($totalCompleted > 0) {
        $wipSubtextParts[] = "{$totalCompleted} completed";
    }
    $wipSubtext = ! empty($wipSubtextParts) ? implode(' · ', $wipSubtextParts) : 'No active execution yet';

    $totalMeasuredRevenue = $initiatives->sum(fn($i) => $i['measured_outcome_summary']['value'] ?? 0.0);
    $totalMeasuredCount = $initiatives->sum(fn($i) => $i['measured_outcome_summary']['measured_count'] ?? 0);
    $totalInObservation = $initiatives->sum(fn($i) => $i['measured_outcome_summary']['in_observation_count'] ?? 0);
    $totalNoOrdersMeasured = $initiatives->sum(fn($i) => $i['measured_outcome_summary']['no_orders_count'] ?? 0);
    $showPortfolioOutcomes = $totalMeasuredCount > 0 || $totalInObservation > 0 || $totalNoOrdersMeasured > 0;
    $defaultWindowDays = (int) config('intelligence.outcome_measurement.window_days', 30);
    $activeObsWindows = $allItems
        ->filter(fn($a) => ($a['outcome_status'] ?? '') === 'awaiting_results')
        ->map(fn($a) => $a['outcome_presentation']['window_days'] ?? null)
        ->filter()
        ->unique()
        ->values();
    $portfolioObsWindowText = $activeObsWindows->count() === 1
        ? ('In ' . $activeObsWindows->first() . '-day observation window')
        : 'In active observation windows';
@endphp

<div class="space-y-6">
    <style>
        @media (max-width: 1024px) {
            .fi-sidebar, .fi-sidebar-close-overlay { display: none !important; }
        }
    </style>
    {{-- Header, Filters & Compact Portfolio Summary --}}
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-5 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-gray-200 dark:border-gray-800">
            <div>
                <div class="flex items-center gap-2.5">
                    <div class="p-2 bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 rounded-lg">
                        <x-heroicon-m-briefcase class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Strategic Business Initiatives</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Prioritized customer opportunities and operational findings requiring review.
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

        {{-- Simplified, Visually Neutral Portfolio Summary --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 {{ $showPortfolioOutcomes ? 'lg:grid-cols-4' : '' }} gap-3 pt-1">
            {{-- 1. Awaiting Review --}}
            <div class="p-3.5 bg-gray-50/70 dark:bg-gray-800/40 border border-gray-200/80 dark:border-gray-800 rounded-xl">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Awaiting Review
                </span>
                <div class="text-2xl font-black text-gray-900 dark:text-gray-100 mt-0.5 tabular-nums">
                    {{ $totalAwaitingDecision }}
                </div>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $awaitingReviewSubtext }}
                </span>
            </div>

            {{-- 2. Manual Verification (Strictly Deduplicated) --}}
            <div class="p-3.5 bg-gray-50/70 dark:bg-gray-800/40 border border-gray-200/80 dark:border-gray-800 rounded-xl">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Manual Verification
                </span>
                <div class="text-2xl font-black {{ $manualVerificationCount > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100' }} mt-0.5 tabular-nums">
                    {{ $manualVerificationCount }}
                </div>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($manualVerificationCount === 0)
                        No manual checks required
                    @elseif ($manualVerificationAccounts > 0 && $manualVerificationFindings > 0)
                        {{ $manualVerificationAccounts }} {{ \Illuminate\Support\Str::plural('account', $manualVerificationAccounts) }} &middot; {{ $manualVerificationFindings }} {{ \Illuminate\Support\Str::plural('finding', $manualVerificationFindings) }}
                    @elseif ($manualVerificationAccounts > 0)
                        {{ $manualVerificationAccounts }} {{ \Illuminate\Support\Str::plural('account', $manualVerificationAccounts) }} flagged for review
                    @else
                        {{ $manualVerificationFindings }} {{ \Illuminate\Support\Str::plural('finding', $manualVerificationFindings) }} with stale evidence
                    @endif
                </span>
            </div>

            {{-- 3. Work in Progress (True Active Execution Status) --}}
            <div class="p-3.5 bg-gray-50/70 dark:bg-gray-800/40 border border-gray-200/80 dark:border-gray-800 rounded-xl">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Work in Progress
                </span>
                <div class="text-2xl font-black text-gray-900 dark:text-gray-100 mt-0.5 tabular-nums">
                    {{ $totalWorkInProgress }}
                </div>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $wipSubtext }}
                </span>
            </div>

            {{-- 4. Measured Outcomes (Conditional — Only Shown When Relevant) --}}
            @if ($showPortfolioOutcomes)
                <div class="p-3.5 bg-gray-50/70 dark:bg-gray-800/40 border border-gray-200/80 dark:border-gray-800 rounded-xl">
                    <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Measured Outcomes
                    </span>
                    <div class="text-2xl font-black {{ $totalMeasuredCount > 0 && $totalMeasuredRevenue > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-gray-900 dark:text-gray-100' }} mt-0.5 tabular-nums">
                        @if ($totalMeasuredCount > 0)
                            ${{ number_format($totalMeasuredRevenue, 0) }}
                        @elseif ($totalInObservation > 0)
                            {{ $totalInObservation }} active
                        @else
                            $0
                        @endif
                    </div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($totalMeasuredCount > 0)
                            {{ $totalMeasuredCount }} measured{{ $totalInObservation > 0 ? " · {$totalInObservation} in observation" : '' }}
                        @elseif ($totalInObservation > 0)
                            {{ $portfolioObsWindowText }}
                        @else
                            {{ $totalNoOrdersMeasured }} completed with 0 orders
                        @endif
                    </span>
                </div>
            @endif
        </div>
    </div>

    {{-- Simplified Executive Initiative Cards Grid (Natural Card Heights) --}}
    @if ($initiatives->isEmpty())
        @php
            $emptyStateTitle = match ($this->selectedPillar) {
                'growth_opportunity' => 'No Active Growth Opportunities',
                'business_risk'      => 'No Active Business Risk Findings',
                'technical_health'   => 'No Active Technical & Data Health Findings',
                default              => 'No Active Strategic Initiatives',
            };
            $emptyStateBody = match ($this->selectedPillar) {
                'growth_opportunity' => 'No qualifying winback, reactivation, replenishment, or expansion opportunities currently match this filter.',
                'business_risk'      => 'No open conversion, revenue deterioration, or account contraction risk findings match this filter. Check integration sync status before assuming normal performance.',
                'technical_health'   => 'No open storefront script error, attribution variance, or traffic anomaly findings match this filter. Absence of active alerts does not guarantee error-free integrations.',
                default              => 'No active strategic initiatives match the selected customer and pillar filter.',
            };
        @endphp
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-12 text-center">
            <x-heroicon-o-funnel class="w-10 h-10 text-gray-400 mx-auto mb-3" />
            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">{{ $emptyStateTitle }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-md mx-auto">
                {{ $emptyStateBody }}
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 items-start">
            @foreach ($initiatives as $initiative)
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm hover:border-gray-300 dark:hover:border-gray-700 transition p-5 space-y-3.5">
                    {{-- 1. Initiative Title (Full Width) & Primary Metric Directly Underneath --}}
                    <div class="space-y-1">
                        @if (empty($this->selectedClientId))
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ $initiative['client_name'] }}
                            </div>
                        @endif
                        <h3 class="text-base sm:text-lg font-bold text-gray-900 dark:text-gray-100 leading-snug">
                            {{ $initiative['display_title'] }}
                        </h3>
                        <div class="flex flex-wrap items-baseline gap-2 pt-0.5">
                            <span class="text-2xl font-black tracking-tight text-gray-900 dark:text-gray-100 tabular-nums">
                                {{ $initiative['card_metric_value'] }}
                            </span>
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ $initiative['card_metric_label'] }}
                            </span>
                        </div>
                    </div>

                    {{-- 2. What Forge Found (Concise Summary) --}}
                    <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                        {{ $initiative['card_summary'] }}
                    </p>

                    {{-- 3. Decision-Relevant Warning (When Applicable) --}}
                    @if (!empty($initiative['card_warning_text']))
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-amber-50 dark:bg-amber-950/40 border border-amber-200/80 dark:border-amber-900/60 text-xs font-medium text-amber-800 dark:text-amber-300">
                            <x-heroicon-m-exclamation-triangle class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400 shrink-0" />
                            <span>{{ $initiative['card_warning_text'] }}</span>
                        </div>
                    @endif

                    {{-- 4. One Concise Recommended Next Step --}}
                    <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">
                        <strong class="font-semibold text-gray-800 dark:text-gray-200">Next step:</strong>
                        {{ $initiative['card_next_step'] }}
                    </p>

                    {{-- 5. Compact Review Status & Single Primary Action --}}
                    <div class="pt-3.5 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span class="font-medium">{{ $initiative['compact_status_text'] }}</span>
                            @if (!empty($initiative['measured_outcome_summary']['is_relevant']) && !empty($initiative['measured_outcome_summary']['card_badge_label']))
                                @php $mColor = $initiative['measured_outcome_summary']['card_badge_color'] ?? 'gray'; @endphp
                                <span class="px-2 py-0.5 text-[11px] font-semibold rounded-md {{ $mColor === 'emerald' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : ($mColor === 'amber' ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300') }}">
                                    {{ $initiative['measured_outcome_summary']['card_badge_label'] }}
                                </span>
                            @endif
                        </div>

                        <button
                            type="button"
                            wire:click="openDrillDown('{{ $initiative['id'] }}')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950 hover:bg-primary-100 dark:hover:bg-primary-900/60 rounded-lg border border-primary-200 dark:border-primary-800 transition"
                        >
                            <span>{{ $initiative['primary_action_label'] }}</span>
                            <x-heroicon-m-arrow-right class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Account Drill-Down Modal / Slideover --}}
    @if ($selectedInitiative)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-start justify-center p-4 sm:p-6 md:p-8">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-2xl max-w-5xl w-full max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150 my-4 sm:my-6">
                {{-- Modal Header (Surfaces Moved Intelligence & Batch Approval) --}}
                <div class="p-6 border-b border-gray-200 dark:border-gray-800 bg-gray-50/70 dark:bg-gray-800/40 space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1.5">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 text-xs font-bold rounded bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                                    {{ $selectedInitiative['client_name'] }}
                                </span>
                                <span class="px-2 py-0.5 text-xs font-semibold rounded bg-primary-100 dark:bg-primary-950 text-primary-700 dark:text-primary-300">
                                    {{ $selectedInitiative['display_title'] }}
                                </span>
                                @if ($selectedInitiative['display_title'] !== $selectedInitiative['strategic_goal'])
                                    <span class="px-2 py-0.5 text-[11px] font-medium rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700">
                                        Strategic Goal: {{ $selectedInitiative['strategic_goal'] }}
                                    </span>
                                @endif
                            </div>
                            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                                {{ $selectedInitiative['is_account_based'] ? 'Account-Level Drill-Down & Evidence' : 'Operational & Diagnostic Findings Review' }}
                            </h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                                {{ $selectedInitiative['business_rationale'] }}
                            </p>
                        </div>

                        <div class="flex items-start gap-3 flex-shrink-0">
                            <div class="text-right">
                                <div class="text-lg font-black text-gray-900 dark:text-gray-100 tabular-nums">
                                    {{ $selectedInitiative['formatted_primary_financial'] }}
                                </div>
                                <div class="text-[10px] font-bold text-gray-600 dark:text-gray-300">
                                    {{ $selectedInitiative['primary_financial_label'] }}
                                </div>
                                @if ($selectedInitiative['has_relationship_value'])
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1" title="{{ $selectedInitiative['relationship_value_definition'] }}">
                                        Relationship Value: <strong class="text-gray-800 dark:text-gray-200">{{ $selectedInitiative['formatted_relationship_value'] }}</strong>
                                    </div>
                                @endif
                                @if (!empty($selectedInitiative['context_metric_badge']))
                                    <div class="text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 mt-0.5">
                                        {{ $selectedInitiative['context_metric_badge'] }}
                                    </div>
                                @endif
                                @if (!empty($selectedInitiative['primary_metric_subtext']))
                                    <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5 max-w-[220px]">
                                        {{ $selectedInitiative['primary_metric_subtext'] }}
                                    </div>
                                @endif
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

                    {{-- Moved Initiative Governance Bar (Target Role, Status, Provisional Warning, Batch Approve) --}}
                    <div class="pt-3 border-t border-gray-200/80 dark:border-gray-700/60 flex flex-wrap items-center justify-between gap-3 text-xs">
                        <div class="flex flex-wrap items-center gap-3 text-gray-600 dark:text-gray-400">
                            <span>Target Role: <strong class="text-gray-900 dark:text-gray-200">{{ $selectedInitiative['target_role_label'] }}</strong></span>
                            <span class="text-gray-300 dark:text-gray-700">&bull;</span>
                            <span>{{ $selectedInitiative['compact_status_text'] }}</span>
                            @if (!empty($selectedInitiative['provisional_notice']))
                                <span class="px-2 py-0.5 text-[11px] font-semibold rounded bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                                    {{ $selectedInitiative['provisional_notice'] }}
                                </span>
                            @endif
                        </div>

                        @if (!empty($selectedInitiative['batch_action_allowed']))
                            <button
                                type="button"
                                wire:click="openBatchApproveModal('{{ $selectedInitiative['id'] }}')"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950 hover:bg-emerald-100 rounded-lg border border-emerald-200 dark:border-emerald-800 transition"
                                title="Review eligibility breakdown and batch approve proven recurring accounts"
                            >
                                <x-heroicon-m-check class="w-3.5 h-3.5" />
                                <span>Batch Approve{{ ($selectedInitiative['review_progress']['proven_eligible_count'] ?? 0) > 0 ? ' (' . $selectedInitiative['review_progress']['proven_eligible_count'] . ' Eligible)' : '' }}</span>
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Compact, Scannable Account Opportunity List with Expandable Detailed Views --}}
                <div class="p-6 overflow-y-auto space-y-3">
                    @foreach (collect($selectedInitiative['accounts'])->sortByDesc(fn($a) => $a['finding_id'] === 214 ? 2 : ($a['finding_id'] === 213 ? 1 : 0)) as $acc)
                        @if (!empty($acc['is_account_opportunity']))
                            <div x-data="{ expanded: {{ $acc['finding_id'] === 214 ? 'true' : 'false' }} }" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm hover:border-gray-300 dark:hover:border-gray-700 transition overflow-hidden">
                            {{-- Default Scannable View --}}
                            <div class="p-4 space-y-3">
                                {{-- Top Row: Identity, Opportunity Type, Status & Actions --}}
                                <div class="flex items-start justify-between gap-3">
                                    <div class="space-y-0.5">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100">
                                                {{ $acc['primary_name'] ?? $acc['customer_name'] ?? $acc['account_label'] }}
                                            </h4>
                                            @if (!empty($acc['customer_id']))
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-medium bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300 border border-blue-200 dark:border-blue-800" title="Magento Customer ID">
                                                    CID: #{{ $acc['customer_id'] }}
                                                </span>
                                            @endif
                                            <span class="px-2 py-0.5 text-[10px] font-bold rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300 border border-primary-200 dark:border-primary-800">
                                                {{ $acc['opportunity_type'] ?? 'Commercial Opportunity' }}
                                            </span>
                                            <span class="px-2 py-0.5 text-[10px] font-semibold rounded-md bg-{{ $acc['contact_badge_color'] ?? 'slate' }}-50 text-{{ $acc['contact_badge_color'] ?? 'slate' }}-700 dark:bg-{{ $acc['contact_badge_color'] ?? 'slate' }}-950 dark:text-{{ $acc['contact_badge_color'] ?? 'slate' }}-300 border border-{{ $acc['contact_badge_color'] ?? 'slate' }}-200 dark:border-{{ $acc['contact_badge_color'] ?? 'slate' }}-800" title="{{ $acc['contact_status_label'] ?? '' }}">
                                                {{ $acc['contact_badge'] ?? 'Contact Recorded' }}
                                            </span>
                                            @if (!empty($acc['requires_revalidation']))
                                                <span class="px-1.5 py-0.5 text-[10px] font-bold rounded bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-300 dark:border-rose-800" title="{{ $acc['revalidation_reason'] ?? 'Detector evidence requires re-evaluation' }}">
                                                    Revalidation Required
                                                </span>
                                            @endif
                                            @if (!empty($acc['is_provisional']))
                                                <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                    Provisional
                                                </span>
                                            @endif
                                        </div>

                                        @if (!empty($acc['company_name']) && strcasecmp(trim($acc['company_name']), trim($acc['primary_name'] ?? '')) !== 0 && !str_starts_with(strtolower(trim($acc['primary_name'] ?? '')), strtolower(trim($acc['company_name']))))
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $acc['company_name'] }}
                                            </div>
                                        @endif
                                        @if (!empty($acc['last_order_date_label']) || !empty($acc['evaluated_date_label']))
                                            <div class="text-[11px] text-gray-500 dark:text-gray-400 flex flex-wrap items-center gap-2 pt-0.5">
                                                @if (!empty($acc['last_order_date_label']))
                                                    <span>Last Purchase: <strong class="text-gray-700 dark:text-gray-300">{{ $acc['last_order_date_label'] }}</strong>@if (!empty($acc['last_order_amount'])) (${{ number_format($acc['last_order_amount'], 2) }})@endif</span>
                                                @endif
                                                @if (!empty($acc['evaluated_date_label']))
                                                    <span>&middot; Evaluated: <strong class="text-gray-700 dark:text-gray-300">{{ $acc['evaluated_date_label'] }}</strong></span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Status & Quick Action Buttons --}}
                                    <div class="flex items-center gap-1.5 flex-wrap justify-end">
                                        <span class="px-2 py-0.5 text-[11px] font-semibold rounded-md bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                            {{ $acc['status_label'] }}
                                        </span>
                                        @if ($acc['status'] === 'new')
                                            <button
                                                type="button"
                                                wire:click="reviewFinding({{ $acc['finding_id'] }})"
                                                class="px-2.5 py-1 text-xs font-medium rounded-lg bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800 transition"
                                            >
                                                Review
                                            </button>
                                        @endif
                                        @if (in_array($acc['status'], ['new', 'under_review']))
                                            <button
                                                type="button"
                                                wire:click="approveFinding({{ $acc['finding_id'] }})"
                                                class="px-2.5 py-1 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 transition"
                                            >
                                                Approve
                                            </button>
                                        @endif
                                        @if (in_array($acc['status'], ['approved', 'assigned']))
                                            <button
                                                type="button"
                                                wire:click="openAssignModal({{ $acc['finding_id'] }})"
                                                class="px-2.5 py-1 text-xs font-medium rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950 dark:text-blue-300 border border-blue-200 dark:border-blue-800 transition"
                                            >
                                                Assign
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openResolveModal({{ $acc['finding_id'] }})"
                                                class="px-2.5 py-1 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 transition"
                                            >
                                                Complete Action
                                            </button>
                                        @endif
                                        @if (!empty($acc['pm_work_item_key']))
                                            <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                                Jira: {{ $acc['pm_work_item_key'] }}
                                            </span>
                                        @elseif (in_array($acc['status'], ['approved', 'assigned']))
                                            <button
                                                type="button"
                                                wire:click="openCreatePmModal({{ $acc['finding_id'] }})"
                                                class="px-2 py-1 text-xs font-medium rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 transition"
                                            >
                                                + Jira
                                            </button>
                                        @endif
                                        <button
                                            type="button"
                                            wire:click="openAuditModal({{ $acc['finding_id'] }})"
                                            class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded"
                                            title="View Audit Trail"
                                        >
                                            <x-heroicon-m-clipboard-document-list class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>

                                {{-- Concise Business Explanation (One Sentence) --}}
                                <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed font-normal">
                                    {{ $acc['concise_explanation'] ?? $acc['suggested_action'] }}
                                </p>

                                {{-- Supporting Metrics (Exactly Three Meaningful Metrics) --}}
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1">
                                    @foreach ($acc['supporting_metrics'] ?? [] as $metric)
                                        <div class="px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200/70 dark:border-gray-700/60">
                                            <div class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                {{ $metric['label'] }}
                                            </div>
                                            <div class="text-xs font-bold text-gray-900 dark:text-gray-100 mt-0.5">
                                                {{ $metric['value'] }}
                                            </div>
                                            @if (!empty($metric['subtext']))
                                                <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5 truncate" title="{{ $metric['subtext'] }}">
                                                    {{ $metric['subtext'] }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                {{-- Recommended Next Action & Outcome Gating --}}
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pt-2 border-t border-gray-100 dark:border-gray-800/80 text-xs">
                                    <div class="flex items-start sm:items-center gap-1.5 text-gray-800 dark:text-gray-200 flex-1 min-w-0">
                                        <x-heroicon-m-arrow-right-circle class="w-4 h-4 text-emerald-600 dark:text-emerald-400 flex-shrink-0 mt-0.5 sm:mt-0" />
                                        <span class="font-medium truncate">
                                            <strong class="font-bold text-gray-900 dark:text-gray-100">Next Action:</strong>
                                            {{ $acc['recommended_action'] ?? $acc['suggested_action'] }}
                                        </span>
                                    </div>

                                    {{-- Commercial Outcome Presentation & Expand Toggle --}}
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        @php
                                            $outcomePres = $acc['outcome_presentation'] ?? ['state' => 'not_yet_measured', 'label' => 'Not yet measured', 'badge_color' => 'gray', 'description' => 'Measurement begins after outreach'];
                                        @endphp
                                        <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-{{ $outcomePres['badge_color'] }}-50 text-{{ $outcomePres['badge_color'] }}-700 dark:bg-{{ $outcomePres['badge_color'] }}-950 dark:text-{{ $outcomePres['badge_color'] }}-300 border border-{{ $outcomePres['badge_color'] }}-200 dark:border-{{ $outcomePres['badge_color'] }}-800" title="{{ $outcomePres['description'] }}">
                                            Outcome: {{ $outcomePres['label'] }}
                                        </span>

                                        <button
                                            type="button"
                                            @click="expanded = !expanded"
                                            class="inline-flex items-center gap-1 text-[11px] font-medium text-primary-600 dark:text-primary-400 hover:underline px-1.5 py-0.5 rounded hover:bg-primary-50 dark:hover:bg-primary-950/40"
                                        >
                                            <span x-text="expanded ? 'Hide Details' : 'Methodology & Details'"></span>
                                            <x-heroicon-m-chevron-down class="w-3.5 h-3.5 transition-transform duration-150" ::class="{ 'rotate-180': expanded }" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {{-- Expandable Drawer: Methodology, Financial Definitions, Contact Verification, Audit Facts --}}
                            <div x-show="expanded" x-cloak class="border-t border-gray-200 dark:border-gray-800 bg-gray-50/80 dark:bg-gray-800/40 p-4 space-y-3 text-xs">
                                @php
                                    $details = $acc['expandable_details'] ?? [];
                                @endphp
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    {{-- Inactivity & Cadence Calibration Methodology --}}
                                    <div class="space-y-1.5 bg-white dark:bg-gray-900 p-3 rounded-lg border border-gray-200/80 dark:border-gray-700/80">
                                        <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center justify-between">
                                            <div class="flex items-center gap-1.5">
                                                <x-heroicon-m-calculator class="w-3.5 h-3.5 text-primary-500" />
                                                <span>Cadence Calibration & Methodology</span>
                                            </div>
                                            <span class="font-mono text-[10px] text-gray-500 dark:text-gray-400">
                                                v{{ $details['detector_version'] ?? '2.1.0-method-d' }}
                                            </span>
                                        </div>
                                        <p class="text-gray-600 dark:text-gray-400 text-[11px] leading-relaxed">
                                            {{ $details['cadence_methodology'] ?? 'Calculated using account purchase intervals and variability coefficient.' }}
                                        </p>
                                        <div class="text-[10px] text-gray-500 dark:text-gray-400 pt-1 flex flex-wrap items-center justify-between gap-2">
                                            <span>Effective Threshold: <strong class="text-gray-800 dark:text-gray-200">{{ $details['effective_threshold_days'] ?? ($details['calibrated_threshold_days'] ?? 'N/A') }}d</strong>@if (!empty($details['raw_cadence_threshold_days'])) (Raw cadence: {{ $details['raw_cadence_threshold_days'] }}d)@endif</span>
                                            @if (!empty($acc['requires_revalidation']))
                                                <span class="text-rose-600 dark:text-rose-400 font-semibold">Revalidation Required</span>
                                            @else
                                                <span class="text-emerald-600 dark:text-emerald-400 font-semibold">Authoritative</span>
                                            @endif
                                        </div>
                                        @if (!empty($details['last_order_date_formatted']) || !empty($details['pre_dormancy_12m_spend']) || !empty($details['order_line_limitation_note']))
                                            <div class="text-[10px] text-gray-500 dark:text-gray-400 pt-1 border-t border-gray-100 dark:border-gray-800 space-y-0.5">
                                                @if (!empty($details['pre_dormancy_12m_spend']))
                                                    <div>Final Active 12m Spend: <strong class="text-gray-800 dark:text-gray-200">${{ number_format($details['pre_dormancy_12m_spend'], 2) }}</strong> across {{ $details['pre_dormancy_12m_orders'] ?? 0 }} order(s)</div>
                                                @endif
                                                @if (!empty($details['peak_ordering_months']))
                                                    <div>Historical Peak Months: <strong class="text-gray-800 dark:text-gray-200">{{ implode(', ', $details['peak_ordering_months']) }}</strong></div>
                                                @endif
                                                @if (!empty($details['order_line_limitation_note']))
                                                    <div class="text-gray-400 italic">{{ $details['order_line_limitation_note'] }}</div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Financial Definitions & Benchmarks --}}
                                    <div class="space-y-1.5 bg-white dark:bg-gray-900 p-3 rounded-lg border border-gray-200/80 dark:border-gray-700/80">
                                        <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                            <x-heroicon-m-banknotes class="w-3.5 h-3.5 text-emerald-500" />
                                            <span>Financial Definitions & Baseline</span>
                                        </div>
                                        <div class="text-[11px] text-gray-600 dark:text-gray-400 space-y-1">
                                            <div>
                                                <strong class="text-gray-800 dark:text-gray-200">Historical Lifetime Spend:</strong>
                                                <span>{{ $details['financial_definitions']['lifetime_spend_definition'] ?? 'Total historical spend.' }}</span>
                                            </div>
                                            <div>
                                                <strong class="text-gray-800 dark:text-gray-200">Episode Benchmark:</strong>
                                                <span>{{ $details['financial_definitions']['episode_benchmark_definition'] ?? 'Average episode purchasing benchmark.' }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Contact Verification & Buyer Integrity --}}
                                    <div class="space-y-1.5 bg-white dark:bg-gray-900 p-3 rounded-lg border border-gray-200/80 dark:border-gray-700/80">
                                        <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center justify-between">
                                            <div class="flex items-center gap-1.5">
                                                <x-heroicon-m-user-circle class="w-3.5 h-3.5 text-blue-500" />
                                                <span>Contact Verification Provenance</span>
                                            </div>
                                            <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-{{ $acc['contact_badge_color'] ?? 'slate' }}-100 text-{{ $acc['contact_badge_color'] ?? 'slate' }}-800 dark:bg-{{ $acc['contact_badge_color'] ?? 'slate' }}-950 dark:text-{{ $acc['contact_badge_color'] ?? 'slate' }}-300">
                                                {{ $acc['contact_badge'] ?? 'Contact Recorded' }}
                                            </span>
                                        </div>
                                        <p class="text-gray-600 dark:text-gray-400 text-[11px] leading-relaxed">
                                            {{ $details['contact_verification'] ?? ($details['contact_status'] ?? 'Contact status verified from customer records.') }}
                                        </p>
                                        @if (!empty($acc['contact_provenance']))
                                            <div class="text-[10px] text-gray-500 dark:text-gray-400 pt-1 space-y-0.5 border-t border-gray-100 dark:border-gray-800 mt-1">
                                                <div>Source: <strong>{{ $acc['contact_provenance']['verification_source'] }}</strong></div>
                                                <div>Verified On: <strong>{{ $acc['contact_provenance']['verification_date'] ?? 'N/A' }}</strong></div>
                                                <div>Responsible: <strong>{{ $acc['contact_provenance']['verified_by'] ?? 'System' }}</strong></div>
                                                <div>Role: <strong>{{ $acc['contact_provenance']['role_confirmation'] ?? 'Confirmed Authority' }}</strong></div>
                                            </div>
                                        @else
                                            <div class="text-[10px] text-gray-500 dark:text-gray-400 pt-1 flex items-center gap-2">
                                                <span>Signal:</span>
                                                <span class="font-medium text-gray-700 dark:text-gray-300">
                                                    Consistency signal from store records (not independent purchasing proof)
                                                </span>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Audit Facts & Execution Trail --}}
                                    <div class="space-y-1.5 bg-white dark:bg-gray-900 p-3 rounded-lg border border-gray-200/80 dark:border-gray-700/80">
                                        <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                            <x-heroicon-m-finger-print class="w-3.5 h-3.5 text-gray-500" />
                                            <span>Identity & Execution Trail</span>
                                        </div>
                                        <div class="text-[11px] text-gray-600 dark:text-gray-400 space-y-0.5">
                                            <div>Account Hash: <code class="font-mono text-[10px]">{{ $acc['account_hash'] ?? 'N/A' }}</code></div>
                                            <div>Assigned To: <strong>{{ $acc['assigned_user_name'] ?? 'Unassigned' }}</strong></div>
                                            @if (!empty($acc['approved_by_name']))
                                                <div>Approved By: <strong>{{ $acc['approved_by_name'] }}</strong> ({{ $acc['approved_at'] }})</div>
                                            @endif
                                            @if (!empty($acc['resolution_notes']))
                                                <div>Action Notes: <em class="text-gray-700 dark:text-gray-300">"{{ $acc['resolution_notes'] }}"</em></div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @else
                            {{-- Operational / Diagnostic Finding View --}}
                            <div x-data="{ expanded: false }" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm hover:border-gray-300 dark:hover:border-gray-700 transition overflow-hidden">
                                <div class="p-4 space-y-3">
                                    {{-- Top Row: Title, Scope, Team, Freshness & Status/Actions --}}
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="space-y-1">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100">
                                                    {{ $acc['primary_name'] }}
                                                </h4>
                                                @if (!empty($acc['scope_label']))
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700">
                                                        {{ $acc['scope_label'] }}
                                                    </span>
                                                @endif
                                                <span class="px-2 py-0.5 text-[10px] font-semibold rounded-md bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                                    {{ $acc['target_team_label'] }}
                                                </span>
                                                @if (!empty($acc['freshness_badge']))
                                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-md {{ !empty($acc['is_outdated']) ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-300 dark:border-amber-800' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' }}" title="{{ $acc['freshness_note'] ?? '' }}">
                                                        {{ $acc['freshness_badge'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            @if (!empty($acc['freshness_note']) && !empty($acc['is_outdated']))
                                                <div class="text-[11px] text-amber-600 dark:text-amber-400 font-medium">
                                                    {{ $acc['freshness_note'] }}
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Status & Quick Action Buttons --}}
                                        <div class="flex items-center gap-1.5 flex-wrap justify-end">
                                            <span class="px-2 py-0.5 text-[11px] font-semibold rounded-md bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                                {{ $acc['status_label'] }}
                                            </span>
                                            @if ($acc['status'] === 'new')
                                                <button
                                                    type="button"
                                                    wire:click="reviewFinding({{ $acc['finding_id'] }})"
                                                    class="px-2.5 py-1 text-xs font-medium rounded-lg bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800 transition"
                                                >
                                                    Review
                                                </button>
                                            @endif
                                            @if (in_array($acc['status'], ['new', 'under_review']))
                                                <button
                                                    type="button"
                                                    wire:click="approveFinding({{ $acc['finding_id'] }})"
                                                    class="px-2.5 py-1 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 transition"
                                                >
                                                    Approve
                                                </button>
                                            @endif
                                            @if (in_array($acc['status'], ['approved', 'assigned']))
                                                <button
                                                    type="button"
                                                    wire:click="openAssignModal({{ $acc['finding_id'] }})"
                                                    class="px-2.5 py-1 text-xs font-medium rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950 dark:text-blue-300 border border-blue-200 dark:border-blue-800 transition"
                                                >
                                                    Assign
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="openResolveModal({{ $acc['finding_id'] }})"
                                                    class="px-2.5 py-1 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 transition"
                                                >
                                                    Complete
                                                </button>
                                            @endif
                                            @if (!empty($acc['pm_work_item_key']))
                                                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                                    Jira: {{ $acc['pm_work_item_key'] }}
                                                </span>
                                            @elseif (in_array($acc['status'], ['approved', 'assigned']))
                                                <button
                                                    type="button"
                                                    wire:click="openCreatePmModal({{ $acc['finding_id'] }})"
                                                    class="px-2 py-1 text-xs font-medium rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 transition"
                                                >
                                                    + Jira
                                                </button>
                                            @endif
                                            <button
                                                type="button"
                                                wire:click="openAuditModal({{ $acc['finding_id'] }})"
                                                class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded"
                                                title="View Audit Trail"
                                            >
                                                <x-heroicon-m-clipboard-document-list class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Supporting Metric Cards --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1">
                                        @foreach ($acc['supporting_metrics'] ?? [] as $metric)
                                            <div class="px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200/70 dark:border-gray-700/60">
                                                <div class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                    {{ $metric['label'] }}
                                                </div>
                                                <div class="text-xs font-bold text-gray-900 dark:text-gray-100 mt-0.5">
                                                    {{ $metric['value'] }}
                                                </div>
                                                @if (!empty($metric['subtext']))
                                                    <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5 truncate" title="{{ $metric['subtext'] }}">
                                                        {{ $metric['subtext'] }}
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>

                                    {{-- 3-Part Findings Section: Confirmed Observation, Possible Implication, Recommended Action --}}
                                    <div class="space-y-2 pt-1">
                                        @if (!empty($acc['confirmed_observation']))
                                            <div class="p-2.5 rounded-lg bg-blue-50/40 dark:bg-blue-950/20 border border-blue-200/50 dark:border-blue-900/30 text-xs">
                                                <div class="flex items-start gap-2">
                                                    <span class="font-bold text-blue-900 dark:text-blue-300 flex-shrink-0">Confirmed Observation:</span>
                                                    <span class="text-gray-700 dark:text-gray-300 leading-relaxed">{{ $acc['confirmed_observation'] }}</span>
                                                </div>
                                            </div>
                                        @endif

                                        @if (!empty($acc['possible_implication']))
                                            <div class="p-2.5 rounded-lg bg-amber-50/40 dark:bg-amber-950/20 border border-amber-200/50 dark:border-amber-900/30 text-xs">
                                                <div class="flex items-start gap-2">
                                                    <span class="font-bold text-amber-900 dark:text-amber-300 flex-shrink-0">Possible Business Implication:</span>
                                                    <span class="text-gray-700 dark:text-gray-300 leading-relaxed">{{ $acc['possible_implication'] }}</span>
                                                </div>
                                            </div>
                                        @endif

                                        @if (!empty($acc['recommended_action']))
                                            <div class="p-2.5 rounded-lg bg-emerald-50/40 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-900/30 text-xs">
                                                <div class="flex items-start gap-2">
                                                    <x-heroicon-m-arrow-right-circle class="w-4 h-4 text-emerald-600 dark:text-emerald-400 flex-shrink-0 mt-0.5" />
                                                    <div class="leading-relaxed">
                                                        <span class="font-bold text-emerald-900 dark:text-emerald-300">Recommended Action:</span>
                                                        <span class="text-gray-700 dark:text-gray-300 ml-1">{{ $acc['recommended_action'] }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Expand Toggle & Audit Trail / Methodology --}}
                                    <div class="flex items-center justify-between pt-2 border-t border-gray-100 dark:border-gray-800/80 text-xs">
                                        <div class="text-[11px] text-gray-500">
                                            Finding #{{ $acc['finding_id'] }} &bull; Scope: {{ $acc['scope_label'] ?? 'Site-wide' }}
                                        </div>
                                        <button
                                            type="button"
                                            @click="expanded = !expanded"
                                            class="inline-flex items-center gap-1 text-[11px] font-medium text-primary-600 dark:text-primary-400 hover:underline px-1.5 py-0.5 rounded hover:bg-primary-50 dark:hover:bg-primary-950/40"
                                        >
                                            <span x-text="expanded ? 'Hide Diagnostic Details' : 'View Diagnostic Evidence'"></span>
                                            <x-heroicon-m-chevron-down class="w-3.5 h-3.5 transition-transform duration-150" ::class="{ 'rotate-180': expanded }" />
                                        </button>
                                    </div>
                                </div>

                                {{-- Expandable Drawer: Diagnostic Evidence & Audit Trail --}}
                                <div x-show="expanded" x-cloak class="border-t border-gray-200 dark:border-gray-800 bg-gray-50/80 dark:bg-gray-800/40 p-4 space-y-3 text-xs">
                                    @php $details = $acc['expandable_details'] ?? []; @endphp
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div class="space-y-1.5 bg-white dark:bg-gray-900 p-3 rounded-lg border border-gray-200/80 dark:border-gray-700/80">
                                            <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                                <x-heroicon-m-document-magnifying-glass class="w-3.5 h-3.5 text-primary-500" />
                                                <span>Diagnostic Telemetry &amp; Detector Context</span>
                                            </div>
                                            <div class="text-[11px] text-gray-600 dark:text-gray-400 space-y-1">
                                                <div>Detector: <strong class="text-gray-800 dark:text-gray-200">{{ $details['detector_name'] ?? ($acc['opportunity_type'] ?? 'N/A') }}</strong></div>
                                                <div>Source Observation Window: <strong class="text-gray-800 dark:text-gray-200">{{ $acc['observation_period'] ?? 'Monitoring episode' }}</strong></div>
                                                <div>Source Freshness: <strong class="text-gray-800 dark:text-gray-200">{{ $acc['freshness_badge'] ?? 'Unverified' }}</strong></div>
                                                @if (!empty($details['latest_source_data_date']))
                                                    <div>Latest Synced Source Data: <strong class="text-gray-800 dark:text-gray-200">{{ $details['latest_source_data_date'] }}</strong></div>
                                                @endif
                                                @if (!empty($details['first_detected_at']))
                                                    <div>First Detected: <strong class="text-gray-800 dark:text-gray-200">{{ \Carbon\Carbon::parse($details['first_detected_at'])->format('M j, Y H:i') }} UTC</strong></div>
                                                @endif
                                                @if (!empty($details['evaluated_at']))
                                                    <div>Last Evaluated: <strong class="text-gray-800 dark:text-gray-200">{{ \Carbon\Carbon::parse($details['evaluated_at'])->format('M j, Y H:i') }} UTC</strong></div>
                                                @endif
                                                @if (!empty($details['diagnostic_details']['detector_description']))
                                                    <div class="pt-1 text-gray-500 text-[10px] leading-relaxed">
                                                        {{ $details['diagnostic_details']['detector_description'] }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="space-y-1.5 bg-white dark:bg-gray-900 p-3 rounded-lg border border-gray-200/80 dark:border-gray-700/80">
                                            <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                                <x-heroicon-m-finger-print class="w-3.5 h-3.5 text-gray-500" />
                                                <span>Execution &amp; Governance Trail</span>
                                            </div>
                                            <div class="text-[11px] text-gray-600 dark:text-gray-400 space-y-0.5">
                                                <div>Assigned To: <strong>{{ $acc['assigned_user_name'] ?? 'Unassigned' }}</strong></div>
                                                @if (!empty($acc['approved_by_name']))
                                                    <div>Approved By: <strong>{{ $acc['approved_by_name'] }}</strong></div>
                                                @endif
                                                @if (!empty($acc['resolution_notes']))
                                                    <div>Resolution: <em class="text-gray-700 dark:text-gray-300">"{{ $acc['resolution_notes'] }}"</em></div>
                                                @endif
                                                <div class="text-[10px] text-gray-500 pt-1">
                                                    Individual finding approval is recorded in the Forge audit trail.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
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
        <div class="fixed inset-0 z-[60] overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
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
        <div class="fixed inset-0 z-[60] overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
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
        <div class="fixed inset-0 z-[60] overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
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
        @php
            $resolveFindingModel = \App\Models\Finding::find($resolveFindingId);
            $resolveWindowDays = $resolveFindingModel
                ? app(\App\Services\Intelligence\OpportunityExplanationService::class)->resolveObservationWindowDays($resolveFindingModel)
                : $defaultWindowDays;
        @endphp
        <div class="fixed inset-0 z-[60] overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Complete Commercial Action</h3>
                <p class="text-xs text-gray-500">Record commercial outreach or technical work completed. Note: Marking an action completed documents representative execution and initiates the {{ $resolveWindowDays }}-day outcome observation window — it does NOT assume or claim recovered revenue.</p>

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
        <div class="fixed inset-0 z-[60] overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
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
        <div class="fixed inset-0 z-[60] overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
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
        <div class="fixed inset-0 z-[60] overflow-y-auto bg-gray-900/60 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
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
