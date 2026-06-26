<?php

namespace App\Filament\Widgets;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentSyncActivityWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SyncLog::with('integration.client')
                    ->latest()
                    ->limit(15)
            )
            ->heading('Recent Sync Activity')
            ->columns([
                Tables\Columns\TextColumn::make('integration.client.name')
                    ->label('Client'),

                Tables\Columns\TextColumn::make('integration.integration_type')
                    ->label('Integration')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? $state),

                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state instanceof SyncStatus ? $state->label() : ucfirst($state))
                    ->colors([
                        'success' => SyncStatus::Success->value,
                        'danger'  => SyncStatus::Failed->value,
                        'warning' => SyncStatus::Running->value,
                        'gray'    => SyncStatus::Skipped->value,
                    ]),

                Tables\Columns\TextColumn::make('records_processed')
                    ->label('Records')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->since(),
            ]);
    }
}
