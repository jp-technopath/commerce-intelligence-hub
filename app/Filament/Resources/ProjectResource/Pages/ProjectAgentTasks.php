<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Resources\Pages\ViewRecord;

class ProjectAgentTasks extends ViewRecord
{
    protected static string $resource = ProjectResource::class;
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationLabel = 'Agent Tasks';
    protected static string $view = 'filament.resources.project-resource.pages.project-sub-tab';

    public function getTabTitle(): string
    {
        return 'Agent Execution Tasks';
    }

    public function getTabDescription(): string
    {
        return 'Autonomous OpenHands and OpenCode coding sessions, task status, execution logs, and runtime metrics for this project.';
    }

    public function getTabIcon(): ?string
    {
        return static::$navigationIcon;
    }
}
