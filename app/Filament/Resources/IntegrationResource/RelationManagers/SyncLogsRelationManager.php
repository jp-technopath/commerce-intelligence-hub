<?php

namespace App\Filament\Resources\IntegrationResource\RelationManagers;

use App\Enums\SyncStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SyncLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncLogs';

    protected static ?string $title = 'Sync Activity Logs';

    protected static ?string $icon = 'heroicon-o-queue-list';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state instanceof SyncStatus ? $state->label() : ucfirst($state))
                    ->colors([
                        'success' => SyncStatus::Success->value,
                        'danger'  => SyncStatus::Failed->value,
                        'warning' => SyncStatus::Running->value,
                        'gray'    => SyncStatus::Skipped->value,
                    ]),

                Tables\Columns\TextColumn::make('records_processed')
                    ->label('Records Processed')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error / Details')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->error_message)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->since(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->since()
                    ->placeholder('Running...'),
            ]);
    }
}
