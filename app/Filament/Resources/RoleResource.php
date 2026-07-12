<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'User Management';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(3)
                    ->schema([
                        Forms\Components\Section::make('Role Details')
                            ->description('Assign a name and description to group distinct system permissions.')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->placeholder('e.g. Analytics Manager')
                                    ->helperText('The unique display name for this role.'),

                                Forms\Components\Textarea::make('description')
                                    ->maxLength(255)
                                    ->rows(3)
                                    ->placeholder('e.g. Can view analytics and run reports, but cannot manage users.')
                                    ->helperText('Briefly explain who should be assigned this role.'),
                            ])
                            ->columnSpan(1),

                        Forms\Components\Section::make('Permissions Mapping')
                            ->description('Check the permissions that are granted to users with this role.')
                            ->schema([
                                Forms\Components\CheckboxList::make('permissions')
                                    ->relationship('permissions', 'name')
                                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name} — {$record->description}")
                                    ->searchable()
                                    ->bulkToggleable()
                                    ->columns(1)
                                    ->required()
                                    ->helperText('Select at least one permission key for this role.'),
                            ])
                            ->columnSpan(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('description')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Assigned Users')
                    ->counts('users')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permissions Granted')
                    ->counts('permissions')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->slideOver(),
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
            'index' => Pages\ManageRoles::route('/'),
        ];
    }
}
