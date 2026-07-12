<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'User Management';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(3)
                    ->schema([
                        // Main details column (2/3 width)
                        Forms\Components\Section::make('User Account Details')
                            ->description('Basic account info needed for this user to sign in.')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g. Jane Doe'),

                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->placeholder('e.g. jane@technopath.co'),

                                Forms\Components\TextInput::make('password')
                                    ->password()
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                                    ->required(fn (string $context): bool => $context === 'create')
                                    ->placeholder(fn (string $context): string => $context === 'create' ? 'Password' : 'Leave empty to keep existing password')
                                    ->helperText('Minimum 8 characters with letters and numbers.'),
                            ])
                            ->columns(2)
                            ->columnSpan(2),

                        // Sidebar configuration column (1/3 width)
                        Forms\Components\Section::make('Role & Access Level')
                            ->description('Control this user\'s scope of authority.')
                            ->schema([
                                Forms\Components\Toggle::make('is_admin')
                                    ->label('Super Administrator')
                                    ->helperText('Grant absolute access. Bypasses all system permission boundaries.')
                                    ->default(false)
                                    ->onIcon('heroicon-m-shield-check')
                                    ->offIcon('heroicon-m-user')
                                    ->onColor('danger'),

                                Forms\Components\Select::make('roles')
                                    ->relationship('roles', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->label('Assigned Roles')
                                    ->helperText('Select one or more functional roles to define system access levels.')
                                    ->disabled(fn (Forms\Get $get) => $get('is_admin') === true),
                            ])
                            ->columnSpan(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($record->email))) . '?d=identicon&s=80')
                    ->grow(false),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Super Admin' => 'danger',
                        'Manager' => 'warning',
                        'Viewer' => 'gray',
                        default => 'primary',
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_admin')
                    ->label('Super Admin Status')
                    ->boolean()
                    ->trueLabel('Super Admins Only')
                    ->falseLabel('Regular Users Only'),
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
            'index' => Pages\ManageUsers::route('/'),
        ];
    }
}
