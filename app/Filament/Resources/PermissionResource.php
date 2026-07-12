<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PermissionResource\Pages;
use App\Models\Permission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'User Management';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Permission Information')
                    ->description('Define permissions that restrict access to specific parts of the system.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->regex('/^[a-z0-9_]+$/')
                            ->validationMessages([
                                'regex' => 'The permission code must contain only lowercase letters, numbers, and underscores.',
                            ])
                            ->placeholder('e.g. manage_users')
                            ->helperText('Use snake_case. This is the permission key used in code/gates.'),

                        Forms\Components\TextInput::make('description')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Manage user accounts, roles, and permissions.')
                            ->helperText('A clear description of what actions this permission authorizes.'),
                    ])
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Permission Key')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Permission key copied')
                    ->tooltip('Click to copy permission key'),

                Tables\Columns\TextColumn::make('description')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Assigned Roles')
                    ->badge()
                    ->color('info')
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
            'index' => Pages\ManagePermissions::route('/'),
        ];
    }
}
