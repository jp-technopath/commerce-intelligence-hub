<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class FindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->limit(60),

                Tables\Columns\BadgeColumn::make('finding_category')
                    ->formatStateUsing(fn ($state) => $state instanceof FindingCategory ? $state->label() : $state),

                Tables\Columns\BadgeColumn::make('severity')
                    ->formatStateUsing(fn ($state) => $state instanceof FindingSeverity ? $state->label() : $state)
                    ->colors([
                        'success' => FindingSeverity::Low->value,
                        'warning' => FindingSeverity::Medium->value,
                        'danger'  => FindingSeverity::High->value,
                    ]),

                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state instanceof FindingStatus ? $state->label() : $state)
                    ->colors([
                        'danger'  => FindingStatus::New->value,
                        'warning' => FindingStatus::Investigating->value,
                        'primary' => FindingStatus::Accepted->value,
                        'success' => FindingStatus::Resolved->value,
                        'gray'    => FindingStatus::Ignored->value,
                    ]),

                Tables\Columns\TextColumn::make('detected_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('detected_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(FindingStatus::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
                Tables\Filters\SelectFilter::make('severity')
                    ->options(collect(FindingSeverity::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
                Tables\Filters\SelectFilter::make('finding_category')
                    ->label('Category')
                    ->options(collect(FindingCategory::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
            ]);
    }
}
