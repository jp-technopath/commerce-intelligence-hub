<?php

namespace App\Filament\Pages\Placeholders;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

abstract class BasePlaceholderPage extends Page
{
    protected static string $view = 'filament.pages.placeholder-module';

    #[Url]
    public string $searchQuery = '';

    #[Url]
    public string $selectedFilter = 'all';

    abstract public function getModuleTitle(): string;
    abstract public function getModuleDescription(): string;
    abstract public function getPrimaryActionLabel(): ?string;

    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        // Hide internal placeholder pages from client portal users
        if ($user->isClientOnly() && static::isInternalOnly()) {
            return false;
        }

        $permission = static::getRequiredPermission();
        if ($permission) {
            return $user->hasPermission($permission);
        }

        return true;
    }

    public static function isInternalOnly(): bool
    {
        return true;
    }

    public static function getRequiredPermission(): ?string
    {
        return null;
    }

    public function getModuleIcon(): ?string
    {
        return static::$navigationIcon;
    }

    public function getPrimaryActionIcon(): string
    {
        return 'heroicon-o-plus';
    }

    public function triggerPrimaryAction(): void
    {
        Notification::make()
            ->title($this->getModuleTitle() . ' Action Triggered')
            ->body('This module is scaffolded. Full functional workflow will be enabled in the upcoming delivery release.')
            ->info()
            ->send();
    }

    public function resetFilters(): void
    {
        $this->searchQuery = '';
        $this->selectedFilter = 'all';
    }
}
