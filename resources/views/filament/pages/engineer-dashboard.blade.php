<x-filament-panels::page>
    {{-- User Switcher Bar --}}
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2.5 bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 rounded-lg">
                <x-heroicon-m-user-circle class="w-6 h-6" />
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Engineer View
                    </h2>
                    @if ($this->selected_user_id && $this->selected_user_id !== auth()->id())
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                            Viewing: {{ $this->getSelectedUser()?->name }}
                        </span>
                    @endif
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Switch between team members to review their daily priority queue, logged hours, and in-flight tasks.
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 w-full md:w-auto">
            <div class="w-full md:w-72">
                {{ $this->form }}
            </div>

            @if ($this->selected_user_id && $this->selected_user_id !== auth()->id())
                <button
                    type="button"
                    wire:click="resetToMe"
                    wire:loading.attr="disabled"
                    class="whitespace-nowrap px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg border border-gray-300 dark:border-gray-700 transition"
                    title="Switch back to my dashboard"
                >
                    Reset to Me
                </button>
            @endif
        </div>
    </div>
</x-filament-panels::page>
