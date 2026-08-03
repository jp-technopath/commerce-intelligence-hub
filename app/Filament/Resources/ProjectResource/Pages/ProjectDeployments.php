<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Resources\Pages\ViewRecord;

class ProjectDeployments extends ViewRecord
{
    protected static string $resource = ProjectResource::class;
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?string $navigationLabel = 'Deployments';
    protected static string $view = 'filament.resources.project-resource.pages.project-sub-tab';

    public function getTabTitle(): string
    {
        return 'Project Deployments';
    }

    public function getTabDescription(): string
    {
        return 'Deployment history, environment releases, staging verification, and deployment logs for this project.';
    }

    public function getTabIcon(): ?string
    {
        return static::$navigationIcon;
    }
}
