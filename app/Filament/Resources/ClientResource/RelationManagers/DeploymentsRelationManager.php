<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\DeploymentType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DeploymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'deployments';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Select::make('deployment_type')
                ->options(collect(DeploymentType::cases())->mapWithKeys(
                    fn ($case) => [$case->value => $case->label()]
                ))
                ->required(),

            Forms\Components\TextInput::make('deployed_by')
                ->maxLength(255),

            Forms\Components\DateTimePicker::make('deployed_at')
                ->required()
                ->default(now()),

            Forms\Components\Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\KeyValue::make('metadata_json')
                ->label('Additional Context (optional)')
                ->helperText('e.g. version number, ticket reference, branch name')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->limit(50),

                Tables\Columns\BadgeColumn::make('deployment_type')
                    ->formatStateUsing(fn ($state) => $state instanceof DeploymentType ? $state->label() : $state),

                Tables\Columns\TextColumn::make('deployed_by')
                    ->label('By')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('deployed_at')
                    ->label('Deployed')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('deployed_at', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Log Deployment'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
