@php
    $user = auth()->user();
    $jiraAccount = $user?->jiraAccount();
@endphp

<div class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
    @if ($jiraAccount)
        <div class="flex items-start gap-4">
            <!-- Connected Icon Glow -->
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 shadow-inner ring-4 ring-emerald-500/10 dark:bg-emerald-950/30 dark:text-emerald-400 dark:ring-emerald-400/10">
                <svg class="h-6 w-6 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h4 class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ $jiraAccount->getCredential('site_name') ?? 'Jira Cloud Site' }}
                    </h4>
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20">
                        Active
                    </span>
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Authorized Site: 
                    <a href="{{ $jiraAccount->getCredential('site_url') }}" target="_blank" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                        {{ $jiraAccount->getCredential('site_url') }}
                    </a>
                </p>
                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                    Connected: {{ $jiraAccount->created_at->diffForHumans() }}
                </p>
            </div>
        </div>
    @else
        <div class="flex items-start gap-4">
            <!-- Disconnected Icon Glow -->
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gray-50 text-gray-400 shadow-inner ring-4 ring-gray-500/5 dark:bg-gray-800/50 dark:text-gray-500">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                </svg>
            </div>
            <div>
                <h4 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                    Not Connected
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20">
                        Action Required
                    </span>
                </h4>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 max-w-xl">
                    Connect your individual Jira account via Atlassian OAuth 2.0. This allows the meeting agent to fetch your specific project ticket statuses and timelines to construct relevant meeting preps.
                </p>
            </div>
        </div>
    @endif
</div>
