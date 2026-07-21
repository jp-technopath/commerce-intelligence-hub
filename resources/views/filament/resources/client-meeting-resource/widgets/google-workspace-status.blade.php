@php
    $ws = $this->getWorkspaceStatus();
@endphp

<x-filament-widgets::widget>
    <div class="fi-wi-custom">
        @if ($ws['status'] === 'not_connected')
            <div class="rounded-xl bg-red-50 p-4 ring-1 ring-red-200 dark:bg-red-900/20 dark:ring-red-800">
                <div class="flex items-start gap-3">
                    <svg class="h-6 w-6 shrink-0 text-red-500 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-red-800 dark:text-red-200">
                            Google Workspace not connected.
                        </p>
                        <p class="mt-1 text-sm text-red-700 dark:text-red-300">
                            Connect to enable calendar scanning, Gmail drafts, and Google Docs.
                        </p>
                        <a
                            href="{{ $ws['connect_url'] }}"
                            style="display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 0.5rem; background-color: #2563eb; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600; color: white; text-decoration: none;"
                            onmouseover="this.style.backgroundColor='#1d4ed8'"
                            onmouseout="this.style.backgroundColor='#2563eb'"
                        >
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                            </svg>
                            Connect Google Workspace
                        </a>
                    </div>
                </div>
            </div>

        @elseif ($ws['status'] === 'needs_reconnect')
            <div class="rounded-xl bg-yellow-50 p-4 ring-1 ring-yellow-200 dark:bg-yellow-900/20 dark:ring-yellow-800">
                <div class="flex items-start gap-3">
                    <svg class="h-6 w-6 shrink-0 text-yellow-500 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-yellow-800 dark:text-yellow-200">
                            Google Workspace needs reconnection.
                        </p>
                        @if (! empty($ws['last_error']))
                            <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                                Last error: {{ $ws['last_error'] }}
                            </p>
                        @endif
                        <a
                            href="{{ $ws['connect_url'] }}"
                            class="mt-3 inline-flex items-center gap-x-2 rounded-lg bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-yellow-500"
                        >
                            Reconnect
                        </a>
                    </div>
                </div>
            </div>

        @elseif ($ws['status'] === 'missing_scopes')
            <div class="rounded-xl bg-yellow-50 p-4 ring-1 ring-yellow-200 dark:bg-yellow-900/20 dark:ring-yellow-800">
                <div class="flex items-start gap-3">
                    <svg class="h-6 w-6 shrink-0 text-yellow-500 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-yellow-800 dark:text-yellow-200">
                            Connected as <strong>{{ $ws['email'] }}</strong>, but missing permissions:
                        </p>
                        <ul class="mt-1 list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300">
                            @foreach ($ws['missing_scopes'] as $scope)
                                <li>{{ $scope }}</li>
                            @endforeach
                        </ul>
                        <a
                            href="{{ $ws['connect_url'] }}"
                            class="mt-3 inline-flex items-center gap-x-2 rounded-lg bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-yellow-500"
                        >
                            Reconnect
                        </a>
                    </div>
                </div>
            </div>

        @elseif ($ws['status'] === 'connected')
            <div class="rounded-xl bg-green-50 p-4 ring-1 ring-green-200 dark:bg-green-900/20 dark:ring-green-800">
                <div class="flex items-start gap-3">
                    <svg class="h-6 w-6 shrink-0 text-green-500 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-green-800 dark:text-green-200">
                            Connected as <strong>{{ $ws['email'] }}</strong>
                        </p>
                        <p class="mt-1 text-sm text-green-700 dark:text-green-300">
                            Calendar, Gmail, and Google Drive permissions granted.
                        </p>
                        <a
                            href="{{ $ws['revoke_url'] }}"
                            style="display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 0.5rem; background-color: #dc2626; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600; color: white; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: background-color 0.2s;"
                            onmouseover="this.style.backgroundColor='#b91c1c'"
                            onmouseout="this.style.backgroundColor='#dc2626'"
                        >
                            Disconnect
                        </a>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
