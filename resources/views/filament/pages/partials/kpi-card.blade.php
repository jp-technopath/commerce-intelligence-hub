@php
    $isNegativeGood = $invert ?? false;
    $changeValue = $change ?? null;
    $isPositive = $changeValue !== null && $changeValue > 0;
    $isNegative = $changeValue !== null && $changeValue < 0;

    if ($isNegativeGood) {
        $pillClass = $isPositive ? 'change-up-bad' : ($isNegative ? 'change-down-good' : 'change-flat');
    } else {
        $pillClass = $isPositive ? 'change-up' : ($isNegative ? 'change-down' : 'change-flat');
    }

    $cardColor = $color ?? 'blue';
    $sourceLabel = $source ?? null;
    $meta = $metadata ?? null;

    $iconBgMap = [
        'blue'    => 'rgba(59,130,246,0.1)',
        'emerald' => 'rgba(16,185,129,0.1)',
        'violet'  => 'rgba(139,92,246,0.1)',
        'sky'     => 'rgba(14,165,233,0.1)',
        'orange'  => 'rgba(249,115,22,0.1)',
        'amber'   => 'rgba(245,158,11,0.1)',
        'rose'    => 'rgba(244,63,94,0.1)',
        'cyan'    => 'rgba(6,182,212,0.1)',
        'red'     => 'rgba(239,68,68,0.1)',
    ];
    $iconColorMap = [
        'blue'    => '#3b82f6',
        'emerald' => '#10b981',
        'violet'  => '#8b5cf6',
        'sky'     => '#0ea5e9',
        'orange'  => '#f97316',
        'amber'   => '#f59e0b',
        'rose'    => '#f43f5e',
        'cyan'    => '#06b6d4',
        'red'     => '#ef4444',
    ];
    $sourceBgMap = [
        'GA4'     => 'rgba(59,130,246,0.08)',
        'Adobe'   => 'rgba(249,115,22,0.08)',
        'Clarity' => 'rgba(6,182,212,0.08)',
        'CrUX'    => 'rgba(139,92,246,0.08)',
    ];
    $sourceColorMap = [
        'GA4'     => '#3b82f6',
        'Adobe'   => '#f97316',
        'Clarity' => '#06b6d4',
        'CrUX'    => '#8b5cf6',
    ];
@endphp

<div class="kpi-card" data-color="{{ $cardColor }}" x-data="{ openMeta: false }">
    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;">
        <div style="flex: 1; min-width: 0;">
            <div style="display: flex; align-items: center; gap: 0.625rem; margin-bottom: 0.875rem;">
                <div style="width: 2rem; height: 2rem; border-radius: 0.5rem; background: {{ $iconBgMap[$cardColor] ?? $iconBgMap['blue'] }}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    @svg($icon, 'w-4 h-4', ['style' => 'color: ' . ($iconColorMap[$cardColor] ?? $iconColorMap['blue'])])
                </div>
                <span style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8;">{{ $label }}</span>
                
                @if($meta)
                    <button type="button" @click="openMeta = !openMeta" class="text-slate-400 hover:text-slate-200 transition-colors focus:outline-none" title="View KPI Formula & Calculation Metadata">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                    </button>
                @endif

                @if($sourceLabel)
                    <span style="margin-left: auto; font-size: 0.5625rem; font-weight: 700; padding: 0.125rem 0.4rem; border-radius: 0.25rem; letter-spacing: 0.04em;
                        background: {{ $sourceBgMap[$sourceLabel] ?? 'rgba(148,163,184,0.08)' }};
                        color: {{ $sourceColorMap[$sourceLabel] ?? '#94a3b8' }};">
                        {{ $sourceLabel }}
                    </span>
                @endif
            </div>
            <div style="font-size: 1.625rem; font-weight: 900; letter-spacing: -0.025em; line-height: 1;" class="text-gray-900 dark:text-white">
                {{ $value }}
            </div>
            <div style="margin-top: 0.5rem; font-size: 0.6875rem; color: #94a3b8; font-weight: 500;">
                Prior: {{ $previous ?? '—' }}
            </div>
        </div>

        @if($changeValue !== null)
            <div class="change-pill {{ $pillClass }}">
                @if($isPositive)
                    <svg style="width: 0.625rem; height: 0.625rem;" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"/></svg>
                @elseif($isNegative)
                    <svg style="width: 0.625rem; height: 0.625rem;" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5l15 15m0 0V8.25m0 11.25H8.25"/></svg>
                @endif
                {{ $changeValue > 0 ? '+' : '' }}{{ $changeValue }}%
            </div>
        @else
            <div class="change-pill change-flat" title="New metric or zero prior baseline">
                New
            </div>
        @endif
    </div>

    @if($meta)
        <div x-show="openMeta" x-cloak @click.away="openMeta = false" style="margin-top: 0.75rem; padding: 0.75rem; border-radius: 0.5rem; background: rgba(15, 23, 42, 0.95); border: 1px solid rgba(255, 255, 255, 0.1); font-size: 0.6875rem; color: #cbd5e1; line-height: 1.4;">
            <div style="font-weight: 700; color: #38bdf8; margin-bottom: 0.25rem;">{{ $meta['display_name'] ?? $label }} ({{ $meta['calculation_version'] ?? 'v1.0.0' }})</div>
            <div style="margin-bottom: 0.375rem; font-style: italic; color: #94a3b8;">{{ $meta['business_definition'] ?? '' }}</div>
            <div style="font-family: monospace; background: rgba(0,0,0,0.3); padding: 0.25rem 0.375rem; border-radius: 0.25rem; margin-bottom: 0.375rem; color: #f1f5f9;">
                <strong>Formula:</strong> {{ $meta['formula'] ?? 'N/A' }}
            </div>
            <div><strong>Data Source:</strong> {{ $meta['data_source'] ?? 'N/A' }}</div>
            <div><strong>Timezone:</strong> {{ $meta['reporting_timezone'] ?? 'America/New_York' }}</div>
            <div><strong>Validation:</strong> <span style="color: #34d399; font-weight: 600;">{{ $meta['validation_status'] ?? 'Validated' }}</span></div>
        </div>
    @endif
</div>
