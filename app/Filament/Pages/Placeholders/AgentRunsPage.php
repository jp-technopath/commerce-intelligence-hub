<?php

namespace App\Filament\Pages\Placeholders;

class AgentRunsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationGroup = 'Delivery';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Agent Runs';
    protected static ?string $title = 'Agent Execution Runs';
    protected static ?string $slug = 'delivery/agent-runs';

    public function getModuleTitle(): string
    {
        return 'Agent Runs';
    }

    public function getModuleDescription(): string
    {
        return 'Autonomous OpenHands and OpenCode execution sessions, task status, real-time logs, and runtime cost metrics.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Launch Agent Run';
    }
}
