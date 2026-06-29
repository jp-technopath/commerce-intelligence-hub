<x-filament-panels::page>
    <style>
        /* Override Filament's max-width constraint for this page */
        .fi-page-content > div { max-width: none !important; }

        .kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
        @media (max-width: 768px) { .kpi-grid { grid-template-columns: 1fr; } }
        @media (min-width: 769px) and (max-width: 1024px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

        .kpi-card {
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            border: 1px solid rgba(148, 163, 184, 0.15);
            background: white;
            transition: all 0.25s ease;
        }
        .dark .kpi-card { background: rgb(30, 41, 59); border-color: rgba(148, 163, 184, 0.1); }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px -5px rgba(0,0,0,0.08); border-color: rgba(99, 102, 241, 0.3); }
        .dark .kpi-card:hover { box-shadow: 0 8px 25px -5px rgba(0,0,0,0.3); }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 1rem 1rem 0 0;
        }
        .kpi-card[data-color="blue"]::before    { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .kpi-card[data-color="emerald"]::before  { background: linear-gradient(90deg, #10b981, #34d399); }
        .kpi-card[data-color="violet"]::before   { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
        .kpi-card[data-color="sky"]::before      { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }
        .kpi-card[data-color="orange"]::before   { background: linear-gradient(90deg, #f97316, #fb923c); }
        .kpi-card[data-color="amber"]::before    { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .kpi-card[data-color="rose"]::before     { background: linear-gradient(90deg, #f43f5e, #fb7185); }
        .kpi-card[data-color="cyan"]::before     { background: linear-gradient(90deg, #06b6d4, #22d3ee); }
        .kpi-card[data-color="red"]::before      { background: linear-gradient(90deg, #ef4444, #f87171); }

        .funnel-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.12);
        }
        .dark .funnel-header { border-bottom-color: rgba(148, 163, 184, 0.08); }

        .funnel-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.125rem;
        }

        .change-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.025em;
        }
        .change-up   { background: rgba(16,185,129,0.1); color: #059669; }
        .change-down { background: rgba(239,68,68,0.1); color: #dc2626; }
        .change-up-bad   { background: rgba(239,68,68,0.1); color: #dc2626; }
        .change-down-good { background: rgba(16,185,129,0.1); color: #059669; }
        .change-flat { background: rgba(148,163,184,0.1); color: #94a3b8; }
        .dark .change-up   { background: rgba(16,185,129,0.15); color: #34d399; }
        .dark .change-down { background: rgba(239,68,68,0.15); color: #f87171; }
        .dark .change-up-bad { background: rgba(239,68,68,0.15); color: #f87171; }
        .dark .change-down-good { background: rgba(16,185,129,0.15); color: #34d399; }
        .dark .change-flat { background: rgba(148,163,184,0.1); color: #64748b; }

        .bottom-panel {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 1024px) { .bottom-panel { grid-template-columns: 1fr; } }
    </style>

    {{-- ── Top Bar: Client Switcher + Period Selector ────────────────────── --}}
    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 2rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 1rem; border-radius: 0.75rem; background: white; border: 1px solid rgba(148,163,184,0.15); box-shadow: 0 1px 3px rgba(0,0,0,0.04);"
             class="dark:!bg-slate-800 dark:!border-slate-700">
            <div style="width: 2rem; height: 2rem; border-radius: 0.5rem; background: rgba(99,102,241,0.1); display: flex; align-items: center; justify-content: center;">
                <x-heroicon-o-building-office-2 class="w-4 h-4" style="color: #6366f1;" />
            </div>
            <select
                wire:model.live="selectedClientId"
                style="border: none; background: transparent; font-size: 0.875rem; font-weight: 700; cursor: pointer; padding-right: 2rem; outline: none;"
                class="text-gray-900 dark:text-white"
            >
                @foreach($this->getClients() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div style="display: flex; align-items: center; gap: 0.25rem; padding: 0.25rem; border-radius: 0.625rem; background: white; border: 1px solid rgba(148,163,184,0.15); box-shadow: 0 1px 3px rgba(0,0,0,0.04);"
             class="dark:!bg-slate-800 dark:!border-slate-700">
            @foreach(['7' => '7 Days', '14' => '14 Days', '30' => '30 Days', '60' => '60 Days', '90' => '90 Days'] as $val => $lbl)
                <button
                    wire:click="$set('period', '{{ $val }}')"
                    style="padding: 0.375rem 0.875rem; font-size: 0.75rem; font-weight: 600; border-radius: 0.5rem; border: none; cursor: pointer; transition: all 0.2s;
                        {{ $period == $val
                            ? 'background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; box-shadow: 0 2px 8px rgba(99,102,241,0.3);'
                            : 'background: transparent; color: #64748b;' }}"
                    class="{{ $period != $val ? 'hover:!bg-gray-100 dark:hover:!bg-slate-700' : '' }}"
                >
                    {{ $lbl }}
                </button>
            @endforeach
        </div>
    </div>


    {{-- ── 1. Conversion ───────────────────────────────────────────────────── --}}
    @php $conversionKpis = $this->getConversionKpis(); @endphp
    @if(count($conversionKpis) > 0)
        <div style="margin-bottom: 2.5rem;">
            <div class="funnel-header">
                <div class="funnel-icon" style="background: rgba(139,92,246,0.1);">🛒</div>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Conversion</h3>
                    <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8;">Last {{ $period }} days vs prior {{ $period }} days</span>
                </div>
            </div>
            <div class="kpi-grid">
                @foreach($conversionKpis as $kpi)
                    @include('filament.pages.partials.kpi-card', $kpi)
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Purchase Journey Funnel ─────────────────────────────────────────── --}}
    @php $funnel = $this->getPurchaseFunnelData(); @endphp
    @if(!empty($funnel['stages']))
        <div style="margin-bottom: 2.5rem;">
            <div class="funnel-header">
                <div class="funnel-icon" style="background: rgba(99,102,241,0.1);">🔄</div>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Purchase Journey</h3>
                    <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8;">Ecommerce funnel — Last {{ $period }} days (GA4)</span>
                </div>
            </div>

            {{-- Funnel visualization --}}
            <div style="
                background: white;
                border: 1px solid #e2e8f0;
                border-radius: 0.75rem;
                padding: 1.5rem;
                margin-top: 0.75rem;
            " class="dark:bg-gray-800 dark:border-gray-700">
                @php
                    $stages = $funnel['stages'];
                    $maxCount = max(array_column($stages, 'count'));
                @endphp

                @foreach($stages as $i => $stage)
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: {{ $i < count($stages) - 1 ? '0.25rem' : '0' }};">
                        {{-- Label --}}
                        <div style="min-width: 120px; text-align: right;">
                            <div style="font-size: 0.8125rem; font-weight: 600;" class="text-gray-700 dark:text-gray-200">{{ $stage['label'] }}</div>
                        </div>

                        {{-- Bar --}}
                        <div style="flex: 1; position: relative;">
                            <div style="
                                height: 36px;
                                background: {{ $stage['color'] }};
                                border-radius: 6px;
                                width: {{ $maxCount > 0 ? round($stage['count'] / $maxCount * 100) : 0 }}%;
                                min-width: 60px;
                                display: flex;
                                align-items: center;
                                padding: 0 12px;
                                transition: width 0.5s ease;
                            ">
                                <span style="font-size: 0.8125rem; font-weight: 700; color: white; white-space: nowrap;">
                                    {{ number_format($stage['count']) }}
                                </span>
                            </div>
                        </div>

                        {{-- Rate from previous stage --}}
                        <div style="min-width: 100px;">
                            @if($i > 0)
                                <div style="font-size: 0.75rem; font-weight: 600; color: #ef4444;">
                                    ↓ {{ $stage['drop_off'] }}% drop-off
                                </div>
                            @else
                                <div style="font-size: 0.75rem; font-weight: 500; color: #94a3b8;">100%</div>
                            @endif
                        </div>
                    </div>

                    {{-- Arrow between stages --}}
                    @if($i < count($stages) - 1)
                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.25rem;">
                            <div style="min-width: 120px;"></div>
                            <div style="padding-left: 8px;">
                                <span style="font-size: 0.6875rem; color: #94a3b8;">▼ {{ $stage['pass_through'] }}% continue</span>
                            </div>
                            <div style="min-width: 100px;"></div>
                        </div>
                    @endif
                @endforeach

                {{-- Overall conversion summary --}}
                <div style="
                    margin-top: 1rem;
                    padding-top: 1rem;
                    border-top: 1px solid #e2e8f0;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                " class="dark:border-gray-600">
                    <span style="font-size: 0.8125rem; font-weight: 500;" class="text-gray-500 dark:text-gray-400">
                        Overall: View → Purchase
                    </span>
                    <span style="font-size: 0.9375rem; font-weight: 700;" class="text-gray-900 dark:text-white">
                        {{ $funnel['overall_rate'] }}% conversion
                    </span>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Funnel Stage: Revenue ──────────────────────────────────────────── --}}
    @php $revenueKpis = $this->getRevenueKpis(); @endphp
    @if(count($revenueKpis) > 0)
        <div style="margin-bottom: 2.5rem;">
            <div class="funnel-header">
                <div class="funnel-icon" style="background: rgba(16,185,129,0.1);">💰</div>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Revenue</h3>
                    <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8;">Last {{ $period }} days vs prior {{ $period }} days</span>
                </div>
            </div>
            <div class="kpi-grid">
                @foreach($revenueKpis as $kpi)
                    @include('filament.pages.partials.kpi-card', $kpi)
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── 4. Acquisition ──────────────────────────────────────────────────── --}}
    @php $acquisitionKpis = $this->getAcquisitionKpis(); @endphp
    @if(count($acquisitionKpis) > 0)
        <div style="margin-bottom: 2.5rem;">
            <div class="funnel-header">
                <div class="funnel-icon" style="background: rgba(59,130,246,0.1);">📊</div>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Acquisition</h3>
                    <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8;">Last {{ $period }} days vs prior {{ $period }} days</span>
                </div>
            </div>
            <div class="kpi-grid">
                @foreach($acquisitionKpis as $kpi)
                    @include('filament.pages.partials.kpi-card', $kpi)
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── 5. UX & Friction ────────────────────────────────────────────────── --}}
    @php $frictionKpis = $this->getFrictionKpis(); @endphp
    @if(count($frictionKpis) > 0)
        <div style="margin-bottom: 2.5rem;">
            <div class="funnel-header">
                <div class="funnel-icon" style="background: rgba(239,68,68,0.1);">⚡</div>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">UX & Friction</h3>
                    <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8;">Behavioral signals from Clarity</span>
                </div>
            </div>
            <div class="kpi-grid">
                @foreach($frictionKpis as $kpi)
                    @include('filament.pages.partials.kpi-card', array_merge($kpi, ['invert' => $kpi['invert'] ?? false]))
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Funnel Stage: Performance ──────────────────────────────────────── --}}
    @php $performanceKpis = $this->getPerformanceKpis(); @endphp
    @if(count($performanceKpis) > 0)
        <div style="margin-bottom: 2.5rem;">
            <div class="funnel-header">
                <div class="funnel-icon" style="background: rgba(139,92,246,0.1);">🚀</div>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Performance</h3>
                    <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8;">Core Web Vitals & page speed</span>
                </div>
            </div>
            <div class="kpi-grid">
                @foreach($performanceKpis as $kpi)
                    @include('filament.pages.partials.kpi-card', array_merge($kpi, ['invert' => $kpi['invert'] ?? false]))
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── 7. Email Marketing ──────────────────────────────────────────────── --}}
    @php $emailKpis = $this->getEmailMarketingKpis(); @endphp
    @if(count($emailKpis) > 0)
        <div style="margin-bottom: 2.5rem;">
            <div class="funnel-header">
                <div class="funnel-icon" style="background: rgba(139,92,246,0.1);">📧</div>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Email Marketing</h3>
                    <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8;">Flows & campaigns from Klaviyo</span>
                </div>
            </div>
            <div class="kpi-grid">
                @foreach($emailKpis as $kpi)
                    @include('filament.pages.partials.kpi-card', array_merge($kpi, ['invert' => $kpi['invert'] ?? false]))
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── 8. Inventory ────────────────────────────────────────────────────── --}}
    @php $inventoryKpis = $this->getInventoryKpis(); @endphp
    @if(count($inventoryKpis) > 0)
        <div style="margin-bottom: 2.5rem;">
            <div class="funnel-header">
                <div class="funnel-icon" style="background: rgba(245,158,11,0.1);">📦</div>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Inventory</h3>
                    <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8;">Stock levels & availability</span>
                </div>
            </div>
            <div class="kpi-grid">
                @foreach($inventoryKpis as $kpi)
                    @include('filament.pages.partials.kpi-card', array_merge($kpi, ['invert' => $kpi['invert'] ?? false]))
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Revenue Chart + Findings ──────────────────────────────────────── --}}
    <div class="bottom-panel">
        {{-- Revenue Trend Chart --}}
        <div style="border-radius: 1rem; background: white; border: 1px solid rgba(148,163,184,0.15); padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);"
             class="dark:!bg-slate-800 dark:!border-slate-700">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem;">
                <div style="width: 1.75rem; height: 1.75rem; border-radius: 0.5rem; background: rgba(99,102,241,0.1); display: flex; align-items: center; justify-content: center;">
                    <x-heroicon-o-chart-bar class="w-3.5 h-3.5" style="color: #6366f1;" />
                </div>
                <h3 style="font-size: 0.875rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Revenue Trend</h3>
                <span style="font-size: 0.6875rem; font-weight: 500; color: #94a3b8; margin-left: auto;">{{ $period }} day window</span>
            </div>
            @php $chartData = $this->getRevenueChartData(); @endphp
            @if(!empty($chartData['labels']))
                <script id="revenueChartData" type="application/json">@json($chartData)</script>
                <div wire:ignore style="position: relative; height: 300px;">
                    <canvas id="revenueChart"></canvas>
                </div>
                @script
                <script>
                    let chartInstance = null;

                    function loadChartJs() {
                        return new Promise((resolve) => {
                            if (typeof Chart !== 'undefined') return resolve();
                            const s = document.createElement('script');
                            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js';
                            s.onload = resolve;
                            document.head.appendChild(s);
                        });
                    }

                    function renderRevenueChart() {
                        const dataEl = document.getElementById('revenueChartData');
                        const canvas = document.getElementById('revenueChart');
                        if (!dataEl || !canvas) return;

                        const data = JSON.parse(dataEl.textContent);
                        if (!data.labels || !data.labels.length) return;

                        if (chartInstance) chartInstance.destroy();

                        const isDark = document.documentElement.classList.contains('dark');
                        const datasets = [];

                        if (data.ga4) {
                            datasets.push({
                                label: 'GA4 Revenue',
                                data: data.ga4,
                                borderColor: '#6366f1',
                                backgroundColor: function(ctx) {
                                    var g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 300);
                                    g.addColorStop(0, 'rgba(99,102,241,0.15)');
                                    g.addColorStop(1, 'rgba(99,102,241,0.01)');
                                    return g;
                                },
                                fill: true, tension: 0.4, pointRadius: 3,
                                pointBackgroundColor: '#6366f1', pointBorderColor: '#fff',
                                pointBorderWidth: 2, pointHoverRadius: 6, borderWidth: 2.5,
                            });
                        }

                        if (data.adobe) {
                            datasets.push({
                                label: 'Adobe Revenue',
                                data: data.adobe,
                                borderColor: '#f97316',
                                backgroundColor: function(ctx) {
                                    var g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 300);
                                    g.addColorStop(0, 'rgba(249,115,22,0.12)');
                                    g.addColorStop(1, 'rgba(249,115,22,0.01)');
                                    return g;
                                },
                                fill: true, tension: 0.4, pointRadius: 3,
                                pointBackgroundColor: '#f97316', pointBorderColor: '#fff',
                                pointBorderWidth: 2, pointHoverRadius: 6, borderWidth: 2.5,
                            });
                        }

                        if (datasets.length === 0) return;

                        chartInstance = new Chart(canvas, {
                            type: 'line',
                            data: { labels: data.labels, datasets: datasets },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: {
                                        position: 'top', align: 'end',
                                        labels: {
                                            color: isDark ? '#94a3b8' : '#64748b',
                                            font: { size: 11, weight: '600' },
                                            usePointStyle: true, pointStyle: 'circle', padding: 16,
                                        }
                                    },
                                    tooltip: {
                                        backgroundColor: isDark ? '#1e293b' : '#fff',
                                        titleColor: isDark ? '#f1f5f9' : '#0f172a',
                                        bodyColor: isDark ? '#cbd5e1' : '#334155',
                                        borderColor: isDark ? '#334155' : '#e2e8f0',
                                        borderWidth: 1, padding: 14, cornerRadius: 10,
                                        displayColors: true, boxPadding: 6,
                                        callbacks: {
                                            label: function(ctx) { return ' ' + ctx.dataset.label + ': $' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2}); }
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        ticks: { color: isDark ? '#475569' : '#94a3b8', font: { size: 10, weight: '500' }, maxRotation: 45 },
                                        grid: { display: false }, border: { display: false },
                                    },
                                    y: {
                                        ticks: {
                                            color: isDark ? '#475569' : '#94a3b8',
                                            font: { size: 10, weight: '500' },
                                            callback: function(v) { return '$' + v.toLocaleString(); }, padding: 8,
                                        },
                                        grid: { color: isDark ? 'rgba(51,65,85,0.5)' : 'rgba(241,245,249,1)', drawBorder: false },
                                        border: { display: false },
                                    },
                                },
                            },
                        });
                    }

                    // Initial render
                    loadChartJs().then(function() { renderRevenueChart(); });

                    // Re-render after every Livewire update
                    Livewire.hook('morph.updated', function({ el, component }) {
                        if (document.getElementById('revenueChartData')) {
                            loadChartJs().then(function() {
                                setTimeout(renderRevenueChart, 50);
                            });
                        }
                    });
                </script>
                @endscript
            @else
                <div style="display: flex; align-items: center; justify-content: center; height: 16rem; color: #94a3b8; font-size: 0.875rem;">
                    No revenue data for this period.
                </div>
            @endif
        </div>

        {{-- Findings Panel --}}
        <div style="border-radius: 1rem; background: white; border: 1px solid rgba(148,163,184,0.15); padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);"
             class="dark:!bg-slate-800 dark:!border-slate-700">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem;">
                <div style="width: 1.75rem; height: 1.75rem; border-radius: 0.5rem; background: rgba(239,68,68,0.1); display: flex; align-items: center; justify-content: center;">
                    <x-heroicon-o-shield-exclamation class="w-3.5 h-3.5" style="color: #ef4444;" />
                </div>
                <h3 style="font-size: 0.875rem; font-weight: 800; margin: 0;" class="text-gray-900 dark:text-white">Intelligence Findings</h3>
            </div>

            @php $findings = $this->getFindingsSummary(); @endphp
            @if(!empty($findings))
                {{-- Stats Row --}}
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-bottom: 1.25rem;">
                    <div style="text-align: center; padding: 0.75rem; border-radius: 0.75rem; background: rgba(239,68,68,0.06);" class="dark:!bg-red-500/10">
                        <div style="font-size: 1.5rem; font-weight: 900; color: #dc2626;" class="dark:!text-red-400">{{ $findings['critical'] }}</div>
                        <div style="font-size: 0.5625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #ef4444; margin-top: 0.125rem;">Critical</div>
                    </div>
                    <div style="text-align: center; padding: 0.75rem; border-radius: 0.75rem; background: rgba(245,158,11,0.06);" class="dark:!bg-amber-500/10">
                        <div style="font-size: 1.5rem; font-weight: 900; color: #d97706;" class="dark:!text-amber-400">{{ $findings['open'] }}</div>
                        <div style="font-size: 0.5625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #f59e0b; margin-top: 0.125rem;">Open</div>
                    </div>
                    <div style="text-align: center; padding: 0.75rem; border-radius: 0.75rem; background: rgba(16,185,129,0.06);" class="dark:!bg-emerald-500/10">
                        <div style="font-size: 1.5rem; font-weight: 900; color: #059669;" class="dark:!text-emerald-400">{{ $findings['resolved'] }}</div>
                        <div style="font-size: 0.5625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #10b981; margin-top: 0.125rem;">Resolved</div>
                    </div>
                </div>

                {{-- Findings List --}}
                @if($findings['recent']->count() > 0)
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        @foreach($findings['recent'] as $f)
                            <a href="{{ route('filament.admin.resources.findings.view', $f) }}"
                               style="display: block; padding: 0.75rem; border-radius: 0.625rem; border: 1px solid rgba(148,163,184,0.1); text-decoration: none; transition: all 0.2s;"
                               class="hover:!border-indigo-300 dark:hover:!border-indigo-600 hover:!bg-gray-50 dark:hover:!bg-slate-700/50"
                               onmouseover="this.style.transform='translateX(4px)'" onmouseout="this.style.transform='none'">
                                <div style="display: flex; align-items: flex-start; gap: 0.625rem;">
                                    <span style="margin-top: 0.375rem; flex-shrink: 0; width: 0.5rem; height: 0.5rem; border-radius: 9999px;
                                        background: {{ match($f->severity->value) {
                                            'critical' => '#ef4444',
                                            'high' => '#f97316',
                                            'medium' => '#eab308',
                                            default => '#22c55e'
                                        } }};"></span>
                                    <div style="min-width: 0; flex: 1;">
                                        <div style="font-size: 0.75rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" class="text-gray-900 dark:text-white">
                                            {{ Str::limit($f->title, 50) }}
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.375rem;">
                                            <span style="font-size: 0.5625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.125rem 0.375rem; border-radius: 0.25rem;
                                                {{ match($f->severity->value) {
                                                    'critical' => 'background: rgba(239,68,68,0.1); color: #dc2626;',
                                                    'high' => 'background: rgba(249,115,22,0.1); color: #ea580c;',
                                                    'medium' => 'background: rgba(234,179,8,0.1); color: #ca8a04;',
                                                    default => 'background: rgba(34,197,94,0.1); color: #16a34a;'
                                                } }}">
                                                {{ $f->severity->label() }}
                                            </span>
                                            <span style="font-size: 0.625rem; color: #94a3b8;">
                                                {{ $f->detected_at?->diffForHumans() }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div style="text-align: center; color: #94a3b8; font-size: 0.8125rem; padding: 2rem 0;">
                        No open findings 🎉
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-filament-panels::page>
