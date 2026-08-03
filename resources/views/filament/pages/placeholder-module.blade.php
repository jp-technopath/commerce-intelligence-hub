<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Module Header Banner -->
        <div class="p-6 bg-white border border-gray-200 rounded-xl shadow-sm dark:bg-gray-900 dark:border-gray-800">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-xl font-bold tracking-tight text-gray-900 dark:text-white">
                            {{ $this->getModuleTitle() }}
                        </h2>
                        <span class="inline-flex items-center gap-x-1.5 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">
                            <span class="h-1.5 w-1.5 rounded-full bg-blue-600 dark:bg-blue-400"></span>
                            Planned Module
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->getModuleDescription() }}
                    </p>
                </div>

                @if($this->getPrimaryActionLabel())
                    <div>
                        <x-filament::button
                            wire:click="triggerPrimaryAction"
                            color="primary"
                            icon="{{ $this->getPrimaryActionIcon() }}"
                        >
                            {{ $this->getPrimaryActionLabel() }}
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 p-4 bg-white border border-gray-200 rounded-xl shadow-sm dark:bg-gray-900 dark:border-gray-800">
            <div class="w-full sm:w-80 relative">
                <input
                    type="text"
                    wire:model.live="searchQuery"
                    placeholder="Search {{ strtolower($this->getModuleTitle()) }}..."
                    class="w-full pl-9 pr-4 py-2 text-sm bg-gray-50 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-800 dark:border-gray-700 dark:text-white"
                />
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                    <x-heroicon-o-magnifying-glass class="w-4 h-4" />
                </div>
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                <select wire:model.live="selectedFilter" class="px-3 py-2 text-sm bg-gray-50 border border-gray-300 rounded-lg text-gray-700 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300">
                    <option value="all">All Records</option>
                    <option value="recent">Recently Updated</option>
                    <option value="active">Active Only</option>
                </select>

                <x-filament::button
                    color="gray"
                    icon="heroicon-o-funnel"
                    size="sm"
                    wire:click="resetFilters"
                >
                    Reset
                </x-filament::button>
            </div>
        </div>

        <!-- Placeholder Empty State Component -->
        <div class="flex flex-col items-center justify-center p-12 bg-white border border-dashed border-gray-300 rounded-xl text-center dark:bg-gray-900 dark:border-gray-800 min-h-[360px]">
            <div class="p-4 bg-blue-50 text-blue-600 rounded-full dark:bg-blue-900/30 dark:text-blue-400 mb-4">
                @if($this->getModuleIcon())
                    @svg($this->getModuleIcon(), 'w-10 h-10')
                @else
                    <x-heroicon-o-folder-open class="w-10 h-10" />
                @endif
            </div>

            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                No {{ strtolower($this->getModuleTitle()) }} found
            </h3>

            <p class="mt-2 text-sm text-gray-500 max-w-md dark:text-gray-400">
                This area is scaffolded and ready for delivery intelligence integration. Real-time data sync for {{ strtolower($this->getModuleTitle()) }} will be enabled in an upcoming release.
            </p>

            @if($this->getPrimaryActionLabel())
                <div class="mt-6">
                    <x-filament::button
                        wire:click="triggerPrimaryAction"
                        color="gray"
                        icon="{{ $this->getPrimaryActionIcon() }}"
                        size="sm"
                    >
                        {{ $this->getPrimaryActionLabel() }}
                    </x-filament::button>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
