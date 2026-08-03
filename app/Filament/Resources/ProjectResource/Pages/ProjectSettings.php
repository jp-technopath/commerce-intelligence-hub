<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Resources\Pages\ViewRecord;

class ProjectSettings extends ViewRecord
{
    protected static string $resource = ProjectResource::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static string $view = 'filament.resources.project-resource.pages.project-sub-tab';

    public function getTabTitle(): string
    {
        return 'Project Settings & Configuration';
    }

    public function getTabDescription(): string
    {
        return 'Specific build parameters, webhook endpoints, repository credentials, and notification thresholds for this project.';
    }

    public function getTabIcon(): ?string
    {
        return static::$navigationIcon;
    }
}
