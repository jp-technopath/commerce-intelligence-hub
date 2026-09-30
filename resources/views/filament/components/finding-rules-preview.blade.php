@props([
    'preview' => [],
    'config'  => null,
])

<div class="space-y-4 text-sm text-gray-800 dark:text-gray-200">
    @if(empty($preview))
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
            No finding rules configured yet. Download the template to get started.
        </div>
    @else
        <div class="space-y-3">
            @foreach($preview as $item)
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-2xs dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-2 dark:border-gray-800">
                        <div class="font-bold text-gray-900 dark:text-white">
                            {{ $item['name'] }}
                        </div>
                        <div class="flex items-center gap-2">
                            @if($item['enabled'])
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                                    Enabled
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                    Disabled
                                </span>
                            @endif

                            @php
                                $sev = strtolower($item['severity']);
                                $sevColor = match($sev) {
                                    'critical' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400',
                                    'high'     => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400',
                                    'medium'   => 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-400',
                                    default    => 'bg-gray-50 text-gray-700 dark:bg-gray-800 dark:text-gray-400',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $sevColor }}">
                                {{ $item['severity'] }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                        {!! nl2br(e($item['plain_english'])) !!}
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
