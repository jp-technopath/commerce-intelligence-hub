<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Resources\Pages\ViewRecord;

class ProjectCodePrs extends ViewRecord
{
    protected static string $resource = ProjectResource::class;
    protected static ?string $navigationIcon = 'heroicon-o-code-bracket-square';
    protected static ?string $navigationLabel = 'Code & PRs';
    protected static string $view = 'filament.resources.project-resource.pages.project-sub-tab';

    public function getTabTitle(): string
    {
        return 'Code Repository & Pull Requests';
    }

    public function getTabDescription(): string
    {
        return 'Linked repository branches, commits, active pull requests, and automated review status for this project.';
    }

    public function getTabIcon(): ?string
    {
        return static::$navigationIcon;
    }
}
