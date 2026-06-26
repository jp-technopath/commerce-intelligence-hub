<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Jobs\TriggerIntegrationSync;
use App\Models\Integration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class IntegrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'integrations';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('integration_type')
                ->options(collect(IntegrationType::cases())->mapWithKeys(
                    fn ($case) => [$case->value => $case->label()]
                ))
                ->required(),

            Forms\Components\Select::make('status')
                ->options(collect(IntegrationStatus::cases())->mapWithKeys(
                    fn ($case) => [$case->value => $case->label()]
                ))
                ->default(IntegrationStatus::Pending->value)
                ->required(),

            Forms\Components\KeyValue::make('settings_json')
                ->label('Settings')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('integration_type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state) => $state instanceof IntegrationType ? $state->label() : $state)
                    ->badge(),

                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state instanceof IntegrationStatus ? $state->label() : $state)
                    ->colors([
                        'success' => IntegrationStatus::Active->value,
                        'gray'    => IntegrationStatus::Inactive->value,
                        'danger'  => IntegrationStatus::Error->value,
                        'warning' => IntegrationStatus::Pending->value,
                    ]),

                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Last Sync')
                    ->dateTime()
                    ->since()
                    ->placeholder('Never'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (Integration $record): void {
                        // First sync gets 30 days of historical data
                        $days = $record->last_sync_at ? 1 : 30;
                        TriggerIntegrationSync::dispatch($record, $days);
                        Notification::make()
                            ->title('Sync queued successfully')
                            ->body("Sync for {$record->integration_type?->label()} has been queued" . ($days > 1 ? " (30-day backfill)" : "") . ".")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
