@props([
    'versions' => collect(),
    'client'   => null,
])

<div class="space-y-4 text-sm text-gray-800 dark:text-gray-200">
    @if($versions->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-6 text-center text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
            No configuration versions saved yet.
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
            <table class="w-full text-left text-xs">
                <thead class="bg-gray-50 text-[0.7rem] uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2.5">Version</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5">Rules Count</th>
                        <th class="px-4 py-2.5">Created At</th>
                        <th class="px-4 py-2.5">Activated At</th>
                        <th class="px-4 py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                    @foreach($versions as $ver)
                        <tr>
                            <td class="px-4 py-3 font-bold text-gray-900 dark:text-white">
                                v{{ $ver->version }}
                            </td>
                            <td class="px-4 py-3">
                                @if($ver->status === 'active')
                                    <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                        ● Active
                                    </span>
                                @elseif($ver->status === 'draft')
                                    <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                        Draft
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                        Archived
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono">
                                {{ $ver->rules_count }} rule(s)
                            </td>
                            <td class="px-4 py-3 text-gray-500">
                                {{ $ver->created_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-gray-500">
                                {{ $ver->activated_at?->format('M j, Y H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($ver->status !== 'active')
                                    <form method="POST" action="{{ route('clients.findings-config.activate', ['client' => $client->id, 'config' => $ver->id]) }}" class="inline">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="rounded bg-indigo-600 px-2 py-1 text-[0.7rem] font-semibold text-white shadow-2xs hover:bg-indigo-500 focus:outline-none"
                                        >
                                            {{ $ver->status === 'draft' ? 'Activate' : 'Rollback to this' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-emerald-600 font-medium">Currently Active</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
