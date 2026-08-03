<?php

namespace App\Filament\Resources;

use App\Enums\DeploymentType;
use App\Filament\Resources\DeploymentResource\Pages;
use App\Models\Deployment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DeploymentResource extends Resource
{
    protected static ?string $model = Deployment::class;
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?string $navigationGroup = 'Deployments';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Release History';

    public static function canViewAny(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = \Illuminate\Support\Facades\Auth::user();
        if (! $user) return false;

        if ($user->isSuperAdmin()) return true;

        if ($user->isClientOnly()) {
            return false;
        }

        return $user->hasPermission('deployments.view_any') || $user->hasPermission('deployments.view');
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        /** @var \App\Models\User|null $currentUser */
        $currentUser = \Illuminate\Support\Facades\Auth::user();

        if (! $currentUser || $currentUser->isSuperAdmin()) {
            return $query;
        }

        $assignedClientIds = $currentUser->getAssignedClientIds();

        return $query->whereIn('client_id', $assignedClientIds);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Deployment Details')
                ->schema([
                    Forms\Components\Select::make('client_id')
                        ->relationship('client', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

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
                        ->label('Deployed By')
                        ->maxLength(255)
                        ->placeholder('Name or team'),

                    Forms\Components\DateTimePicker::make('deployed_at')
                        ->label('Deployment Date/Time')
                        ->required()
                        ->default(now())
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Additional Context')
                ->description('Add version numbers, ticket references, branch names, etc.')
                ->schema([
                    Forms\Components\KeyValue::make('metadata_json')
                        ->label(false)
                        ->columnSpanFull(),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->limit(50),

                Tables\Columns\BadgeColumn::make('deployment_type')
                    ->formatStateUsing(fn ($state) => $state instanceof DeploymentType ? $state->label() : $state),

                Tables\Columns\TextColumn::make('deployed_by')
                    ->label('By')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('deployed_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('deployed_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('client')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('deployment_type')
                    ->options(collect(DeploymentType::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDeployments::route('/'),
            'create' => Pages\CreateDeployment::route('/create'),
            'edit'   => Pages\EditDeployment::route('/{record}/edit'),
        ];
    }
}
