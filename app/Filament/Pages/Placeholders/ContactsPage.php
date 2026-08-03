<?php

namespace App\Filament\Pages\Placeholders;

class ContactsPage extends BasePlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Contacts';
    protected static ?string $title = 'Client Contacts';
    protected static ?string $slug = 'clients/contacts';

    public function getModuleTitle(): string
    {
        return 'Client Contacts';
    }

    public function getModuleDescription(): string
    {
        return 'Directory of client stakeholders, technical managers, decision makers, and meeting attendees.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        return 'Add Contact';
    }
}
