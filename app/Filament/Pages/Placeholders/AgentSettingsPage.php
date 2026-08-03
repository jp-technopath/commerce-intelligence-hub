<?php

namespace App\Filament\Pages\Placeholders;

class AgentSettingsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Agent Settings';
    protected static ?string $title = 'Global Agent Settings';
    protected static ?string $slug = 'administration/agent-settings';

    public function getModuleTitle(): string
    {
        return 'Agent Settings';
    }

    public function getModuleDescription(): string
    {
        return 'Global AI agent execution rules, default LLM model routes, fallback providers, system prompt templates, and security constraints.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Update Config';
    }
}
