<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-4">
            <x-filament::button type="submit">
                Save changes
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
