<?php

namespace App\Filament\Pages\Placeholders;

class ProjectBriefsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Intelligence';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Project Briefs';
    protected static ?string $title = 'Project Briefs';
    protected static ?string $slug = 'intelligence/project-briefs';

    public function getModuleTitle(): string
    {
        return 'Project Briefs';
    }

    public function getModuleDescription(): string
    {
        return 'Living intelligence briefs summarizing client objectives, technical architecture, business rules, risks, and agent context.';
    }

    public static function isInternalOnly(): bool
    {
        return false;
    }

    public static function getRequiredPermission(): ?string
    {
        return 'project_briefs.view';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Create Project Brief';
    }
}
