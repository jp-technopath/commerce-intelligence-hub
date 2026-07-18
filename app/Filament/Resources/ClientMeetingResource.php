<?php

namespace App\Filament\Resources;

use App\Enums\MeetingSource;
use App\Enums\MeetingStatus;
use App\Filament\Resources\ClientMeetingResource\Pages;
use App\Models\ClientMeeting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientMeetingResource extends Resource
{
    protected static ?string $model = ClientMeeting::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Meetings';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Customer Meetings';
    protected static ?string $slug = 'client-meetings';
    protected static ?string $modelLabel = 'Customer Meeting';
    protected static ?string $pluralModelLabel = 'Customer Meetings';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Meeting Details')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('client_id')
                        ->relationship('client', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->live()
                        ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, ?string $state) {
                            if ($state) {
                                $client = \App\Models\Client::find($state);
                                if ($client && ! empty($client->jira_project_key)) {
                                    $set('project_key', $client->jira_project_key);
                                }
                            }
                        }),

                    Forms\Components\TextInput::make('project_key')
                        ->label('Jira Project Key')
                        ->nullable(),

                    Forms\Components\DateTimePicker::make('meeting_start_at')
                        ->required()
                        ->label('Start Time'),

                    Forms\Components\DateTimePicker::make('meeting_end_at')
                        ->label('End Time'),

                    Forms\Components\TextInput::make('timezone')
                        ->default('UTC'),

                    Forms\Components\Select::make('internal_owner_id')
                        ->relationship('owner', 'name')
                        ->searchable()
                        ->preload()
                        ->default(fn () => auth()->id())
                        ->label('Owner'),

                    Forms\Components\Select::make('status')
                        ->options(collect(MeetingStatus::cases())->mapWithKeys(
                            fn ($case) => [$case->value => $case->label()]
                        ))
                        ->default(MeetingStatus::Detected->value),

                    Forms\Components\Select::make('source')
                        ->options(collect(MeetingSource::cases())->mapWithKeys(
                            fn ($case) => [$case->value => $case->label()]
                        ))
                        ->default(MeetingSource::Manual->value),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->weight(FontWeight::Bold)
                    ->limit(50)
                    ->wrap(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->badge()
                    ->color(fn ($state) => $state ? 'primary' : 'warning')
                    ->default('Unmapped')
                    ->searchable(),

                Tables\Columns\TextColumn::make('meeting_start_at')
                    ->label('Meeting Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Owner')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color(fn (MeetingSource $state) => $state->color())
                    ->formatStateUsing(fn (MeetingSource $state) => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (MeetingStatus $state) => $state->color())
                    ->formatStateUsing(fn (MeetingStatus $state) => $state->label()),

                Tables\Columns\IconColumn::make('has_prep')
                    ->label('Prep')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->prep !== null),

                Tables\Columns\IconColumn::make('has_followup')
                    ->label('Follow-Up')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->followUp !== null),

                Tables\Columns\IconColumn::make('has_gmail_draft')
                    ->label('Draft')
                    ->boolean()
                    ->getStateUsing(fn ($record) => ($record->prep?->gmail_draft_id || $record->followUp?->gmail_draft_id) !== null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(MeetingStatus::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),

                Tables\Filters\SelectFilter::make('client_id')
                    ->relationship('client', 'name')
                    ->label('Client'),

                Tables\Filters\SelectFilter::make('internal_owner_id')
                    ->relationship('owner', 'name')
                    ->label('Owner'),

                Tables\Filters\SelectFilter::make('source')
                    ->options(collect(MeetingSource::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('meeting_start_at', 'desc');
    }

    public static function getRelationManagers(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()?->is_admin) {
            $query->where('internal_owner_id', auth()->id());
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListClientMeetings::route('/'),
            'create' => Pages\CreateClientMeeting::route('/create'),
            'view'   => Pages\ViewClientMeeting::route('/{record}'),
            'edit'   => Pages\EditClientMeeting::route('/{record}/edit'),
        ];
    }
}
