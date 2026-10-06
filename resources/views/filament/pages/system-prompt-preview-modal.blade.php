<div class="space-y-4 text-sm text-gray-800 dark:text-gray-200">
    {{-- Validation Status Banner --}}
    @if($preview['valid'])
        <div class="p-3 rounded-lg border border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300 text-xs font-semibold flex items-center gap-2 text-emerald-800">
            <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <span>All template variables match registered definitions. This draft is valid and ready to publish.</span>
        </div>
    @else
        <div class="p-3 rounded-lg border border-rose-200 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-300 text-xs font-semibold flex items-start gap-2 text-rose-800">
            <svg class="w-4 h-4 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div>
                <div>Unregistered variables detected: <span class="font-mono underline">{{ implode(', ', $preview['unregistered_variables']) }}</span></div>
                <div class="mt-1 font-normal text-rose-600 dark:text-rose-400">Publishing this draft will be blocked until these unknown placeholders are removed or registered in code.</div>
            </div>
        </div>
    @endif

    {{-- System Instructions Box --}}
    <div class="space-y-1">
        <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400">
            System Instructions (Persona & Rules)
        </label>
        <div class="p-3 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg font-mono text-xs text-gray-800 dark:text-gray-200 whitespace-pre-wrap max-h-56 overflow-y-auto leading-relaxed select-text">
{{ $preview['system_prompt'] }}
        </div>
    </div>

    {{-- User Prompt Box --}}
    <div class="space-y-1">
        <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400">
            Interpolated User Prompt (Sample Variables Substituted)
        </label>
        <div class="p-3 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg font-mono text-xs text-gray-800 dark:text-gray-200 whitespace-pre-wrap max-h-80 overflow-y-auto leading-relaxed select-text">
{{ $preview['user_prompt'] }}
        </div>
    </div>

    {{-- Sample Variables Used --}}
    @if(!empty($preview['sample_data']))
        <details class="text-xs text-gray-500 dark:text-gray-400 pt-1">
            <summary class="cursor-pointer font-medium hover:text-gray-700 dark:hover:text-gray-300">
                View Sample Context Data ({{ count($preview['sample_data']) }} variables)
            </summary>
            <div class="mt-2 p-2.5 bg-gray-100 dark:bg-gray-950 rounded border border-gray-200 dark:border-gray-800 font-mono text-[11px] overflow-x-auto space-y-1">
                @foreach($preview['sample_data'] as $varKey => $varVal)
                    <div>
                        <span class="text-blue-600 dark:text-blue-400">&#123;&#123; {{ $varKey }} &#125;&#125;</span>:
                        <span class="text-gray-700 dark:text-gray-300">{{ is_string($varVal) ? Str::limit($varVal, 100) : json_encode($varVal) }}</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</div>
