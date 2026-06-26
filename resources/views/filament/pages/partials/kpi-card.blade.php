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
    ];
    $sourceColorMap = [
        'GA4'     => '#3b82f6',
        'Adobe'   => '#f97316',
        'Clarity' => '#06b6d4',
    ];
@endphp

<div class="kpi-card" data-color="{{ $cardColor }}">
    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;">
        <div style="flex: 1; min-width: 0;">
            <div style="display: flex; align-items: center; gap: 0.625rem; margin-bottom: 0.875rem;">
                <div style="width: 2rem; height: 2rem; border-radius: 0.5rem; background: {{ $iconBgMap[$cardColor] ?? $iconBgMap['blue'] }}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    @svg($icon, 'w-4 h-4', ['style' => 'color: ' . ($iconColorMap[$cardColor] ?? $iconColorMap['blue'])])
                </div>
                <span style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8;">{{ $label }}</span>
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
        @endif
    </div>
</div>
