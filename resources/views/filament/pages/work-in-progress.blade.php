<x-filament-panels::page class="relative">
    {{-- Glassmorphism Full Loading Overlay --}}
    <div wire:loading.flex class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-md flex flex-col items-center justify-center transition-opacity duration-300">
        <div class="p-6 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-2xl flex flex-col items-center gap-4 max-w-sm w-full mx-4 text-center">
            <div class="relative flex items-center justify-center">
                <div class="absolute w-12 h-12 rounded-full border-4 border-primary-500/20 animate-ping"></div>
                <svg class="animate-spin w-10 h-10 text-primary-600 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Loading Active Work Items...</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Fetching live delivery pipeline and task statuses.</p>
            </div>
        </div>
    </div>

    {{-- Top Customer Selector Bar --}}
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-4 mb-4 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2.5 bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 rounded-lg">
                <x-heroicon-m-building-office-2 class="w-6 h-6" />
            </div>
            <div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Customer Account Filter</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Select a customer account to inspect active tasks currently in delivery.</p>
            </div>
        </div>
        <div class="w-full sm:w-80">
            {{ $this->form }}
        </div>
    </div>

    {{-- Active Work Items Table --}}
    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
