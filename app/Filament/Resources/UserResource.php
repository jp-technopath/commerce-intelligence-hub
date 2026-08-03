<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Client;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user) return false;

        if ($user->isSuperAdmin()) return true;

        if ($user->isClientOnly()) {
            return false;
        }

        return $user->hasPermission('users.view_any') || $user->hasPermission('users.view') || $user->hasRole(\App\Models\Role::ROLE_CUSTOMER_ADMIN);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        /** @var User|null $currentUser */
        $currentUser = Auth::user();

        if (! $currentUser) {
            return $query;
        }

        if ($currentUser->isSuperAdmin()) {
            return $query;
        }

        if ($currentUser->hasRole(Role::ROLE_CUSTOMER_ADMIN)) {
            $clientIds = $currentUser->getAssignedClientIds();

            return $query->whereHas('roleAssignments', function ($q) use ($clientIds) {
                $q->whereIn('client_id', $clientIds);
            });
        }

        return $query->where('id', $currentUser->id);
    }

    public static function form(Form $form): Form
    {
        /** @var User|null $currentUser */
        $currentUser = Auth::user();

        return $form
            ->schema([
                Forms\Components\Grid::make(3)
                    ->schema([
                        // Account details (2/3 width)
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
                                    ->placeholder(fn (string $context): string => $context === 'create' ? 'Password' : 'Leave empty to keep existing password'),
                            ])
                            ->columns(2)
                            ->columnSpan(2),

                        // Super Admin Flag (1/3 width, Super Admin only)
                        Forms\Components\Section::make('System Super Admin')
                            ->description('Platform-level global bypass flag.')
                            ->schema([
                                Forms\Components\Toggle::make('is_admin')
                                    ->label('Super Administrator')
                                    ->helperText('Global platform administrator.')
                                    ->default(false)
                                    ->disabled(fn () => ! $currentUser?->isSuperAdmin()),
                            ])
                            ->columnSpan(1),

                        // Scoped Role Assignments Repeater (Full width)
                        Forms\Components\Section::make('Scoped Role Assignments')
                            ->description('Assign functional roles scoped to specific clients, projects, or repositories.')
                            ->schema([
                                Forms\Components\Repeater::make('roleAssignments')
                                    ->relationship('roleAssignments')
                                    ->schema([
                                        Forms\Components\Select::make('role_id')
                                            ->label('Role')
                                            ->options(function () use ($currentUser) {
                                                $query = Role::query();
                                                if ($currentUser && $currentUser->hasRole(Role::ROLE_CUSTOMER_ADMIN) && ! $currentUser->isSuperAdmin()) {
                                                    $query->whereIn('name', [Role::ROLE_CUSTOMER_ADMIN, Role::ROLE_CLIENT_USER]);
                                                }
                                                return $query->pluck('name', 'id')
                                                    ->map(fn ($name) => match ($machineName = $name) {
                                                        Role::ROLE_CLIENT_USER => 'Client',
                                                        Role::ROLE_SUPER_ADMIN => 'Super Admin',
                                                        Role::ROLE_CUSTOMER_ADMIN => 'Customer Admin',
                                                        Role::ROLE_PRODUCT_OWNER => 'Product Owner',
                                                        Role::ROLE_ANALYST => 'Analyst',
                                                        Role::ROLE_ENGINEER => 'Engineer',
                                                        default => $machineName,
                                                    });
                                            })
                                            ->required()
                                            ->reactive(),

                                        Forms\Components\Select::make('client_id')
                                            ->label('Client Scope')
                                            ->options(function () use ($currentUser) {
                                                $query = Client::query();
                                                if ($currentUser && ! $currentUser->isSuperAdmin()) {
                                                    $query->whereIn('id', $currentUser->getAssignedClientIds());
                                                }
                                                return $query->pluck('name', 'id');
                                            })
                                            ->searchable()
                                            ->placeholder('Global / No Client Filter')
                                            ->reactive(),

                                        Forms\Components\Select::make('project_id')
                                            ->label('Project Scope')
                                            ->options(function (Get $get) use ($currentUser) {
                                                $clientId = $get('client_id');
                                                $query = Project::query();
                                                if ($clientId) {
                                                    $query->where('client_id', $clientId);
                                                } elseif ($currentUser && ! $currentUser->isSuperAdmin()) {
                                                    $query->whereIn('id', $currentUser->getAssignedProjectIds());
                                                }
                                                return $query->pluck('name', 'id');
                                            })
                                            ->searchable()
                                            ->placeholder('All Client Projects'),

                                        Forms\Components\Select::make('repository_id')
                                            ->label('Repository Scope')
                                            ->options(fn () => Repository::pluck('name', 'id'))
                                            ->searchable()
                                            ->placeholder('No Repo Filter'),

                                        Forms\Components\DateTimePicker::make('starts_at')
                                            ->label('Start Date'),

                                        Forms\Components\DateTimePicker::make('expires_at')
                                            ->label('Expiration Date'),

                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true),
                                    ])
                                    ->columns(3)
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->addActionLabel('Add Scoped Role Assignment'),
                            ])
                            ->columnSpan(3),
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

                Tables\Columns\TextColumn::make('activeRoleAssignments.role.name')
                    ->label('Assigned Roles')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        Role::ROLE_CLIENT_USER => 'Client',
                        Role::ROLE_SUPER_ADMIN => 'Super Admin',
                        Role::ROLE_CUSTOMER_ADMIN => 'Customer Admin',
                        Role::ROLE_PRODUCT_OWNER => 'Product Owner',
                        Role::ROLE_ANALYST => 'Analyst',
                        Role::ROLE_ENGINEER => 'Engineer',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Role::ROLE_SUPER_ADMIN => 'danger',
                        Role::ROLE_CUSTOMER_ADMIN => 'warning',
                        Role::ROLE_PRODUCT_OWNER => 'info',
                        Role::ROLE_ANALYST => 'primary',
                        Role::ROLE_ENGINEER => 'success',
                        Role::ROLE_CLIENT_USER => 'gray',
                        default => 'primary',
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('activeRoleAssignments.client.name')
                    ->label('Assigned Clients')
                    ->badge()
                    ->color('gray')
                    ->wrap(),

                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Super Admin')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-x-circle'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->slideOver(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (User $record, Tables\Actions\DeleteAction $action) {
                        if (UserPolicy::isFinalCustomerAdmin($record)) {
                            \Filament\Notifications\Notification::make()
                                ->title('Cannot delete user')
                                ->body('This user is the final remaining Customer Admin for their customer account.')
                                ->danger()
                                ->send();
                            $action->halt();
                        }
                    }),
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
