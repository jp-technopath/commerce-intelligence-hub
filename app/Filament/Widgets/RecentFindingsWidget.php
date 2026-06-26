<?php

namespace App\Filament\Widgets;

use App\Enums\FindingStatus;
use App\Models\Finding;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentFindingsWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Finding::with('client')
                    ->whereIn('status', [
                        FindingStatus::New->value,
                        FindingStatus::Investigating->value,
                    ])
                    ->latest('detected_at')
                    ->limit(10)
            )
            ->heading('Recent Open Findings')
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client'),

                Tables\Columns\TextColumn::make('title')
                    ->limit(60),

                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\FindingSeverity ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state) => $state instanceof \App\Enums\FindingSeverity ? $state->color() : 'gray'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\FindingStatus ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state) => $state instanceof \App\Enums\FindingStatus ? $state->color() : 'gray'),

                Tables\Columns\TextColumn::make('detected_at')
                    ->since()
                    ->label('Detected'),
            ]);
    }
}
