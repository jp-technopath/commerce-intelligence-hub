<?php

namespace App\Filament\Resources\SystemPromptResource\Pages;

use App\Filament\Resources\SystemPromptResource;
use Filament\Resources\Pages\ListRecords;

class ListSystemPrompts extends ListRecords
{
    protected static string $resource = SystemPromptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
