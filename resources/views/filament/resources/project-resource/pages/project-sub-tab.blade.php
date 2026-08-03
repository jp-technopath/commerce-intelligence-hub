<x-filament-panels::page>
    <div class="space-y-6">
        <div class="p-6 bg-white border border-gray-200 rounded-xl shadow-sm dark:bg-gray-900 dark:border-gray-800">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ $this->getTabTitle() }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->getTabDescription() }}
                    </p>
                </div>
                <span class="inline-flex items-center gap-x-1.5 rounded-md bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10 dark:bg-blue-400/10 dark:text-blue-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-blue-600 dark:bg-blue-400"></span>
                    Project Module
                </span>
            </div>
        </div>

        <div class="flex flex-col items-center justify-center p-12 bg-white border border-dashed border-gray-300 rounded-xl text-center dark:bg-gray-900 dark:border-gray-800 min-h-[300px]">
            <div class="p-4 bg-primary-50 text-primary-600 rounded-full dark:bg-primary-900/30 dark:text-primary-400 mb-4">
                @if($this->getTabIcon())
                    @svg($this->getTabIcon(), 'w-10 h-10')
                @else
                    <x-heroicon-o-folder-open class="w-10 h-10" />
                @endif
            </div>

            <h4 class="text-base font-semibold text-gray-900 dark:text-white">
                No {{ strtolower($this->getTabTitle()) }} records found for {{ $record->name }}
            </h4>

            <p class="mt-2 text-sm text-gray-500 max-w-md dark:text-gray-400">
                This project sub-module is active and ready for live integration. Data linked to project {{ $record->code ?? $record->name }} will appear here once connected.
            </p>
        </div>
    </div>
</x-filament-panels::page>
