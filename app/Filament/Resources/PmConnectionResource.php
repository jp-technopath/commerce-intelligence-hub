<?php

namespace App\Filament\Resources;

use App\Models\PmConnection;
use App\Models\User;
use App\Jobs\ReconcilePmWorkItemsJob;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PmConnectionResource extends Resource
{
    protected static ?string $model = PmConnection::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'PM Integrations';
    protected static ?string $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if (! $user) return false;

        if ($user->isSuperAdmin()) return true;

        // Hide PM Integrations from client portal users
        if ($user->isClientOnly()) {
            return false;
        }

        return $user->hasPermission('integrations.view_any') || $user->hasPermission('integrations.manage');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Customer PM Scope Details')
                ->schema([
                    Forms\Components\Select::make('client_id')
                        ->relationship('client', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('provider')
                        ->options(['jira' => 'Atlassian Jira'])
                        ->default('jira')
                        ->required(),

                    Forms\Components\TextInput::make('name')
                        ->label('Integration Name')
                        ->placeholder('e.g. Cambro Jira Workspace')
                        ->required(),

                    Forms\Components\TextInput::make('external_workspace_id')
                        ->label('Cloud ID / External Workspace')
                        ->helperText('Atlassian Cloud ID for OAuth endpoints'),

                    Forms\Components\Select::make('default_sync_user_id')
                        ->label('Default Sync Identity User')
                        ->helperText('User account used for background synchronization')
                        ->options(User::all()->pluck('name', 'id'))
                        ->searchable()
                        ->nullable(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Integration Active')
                        ->default(true),
                ])->columns(2),

            Forms\Components\Section::make('Sync Identity Health Status')
                ->schema([
                    Forms\Components\Placeholder::make('sync_health_status')
                        ->label('Sync Identity Health')
                        ->content(function (?PmConnection $record): HtmlString {
                            if (! $record) {
                                return new HtmlString('<span class="text-sm text-gray-500">Save connection first to view identity health.</span>');
                            }

                            $health = $record->sync_identity_health;
                            if ($health['is_healthy']) {
                                return new HtmlString(
                                    '<div class="flex items-center gap-2 text-green-700 font-medium text-sm">'
                                    . '<svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>'
                                    . e($health['message'])
                                    . '</div>'
                                );
                            }

                            return new HtmlString(
                                '<div class="p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-2 text-amber-800 font-medium text-sm">'
                                . '<svg class="w-5 h-5 text-amber-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>'
                                . '<span>⚠ Sync identity requires reconnection: ' . e($health['message']) . '</span>'
                                . '</div>'
                            );
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                Tables\Columns\BadgeColumn::make('provider')->color('primary'),
                Tables\Columns\TextColumn::make('defaultSyncUser.name')
                    ->label('Sync Identity')
                    ->default('None')
                    ->placeholder('None'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('last_synced_at')->label('Last Synced')->dateTime()->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('sync_now')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (PmConnection $record): void {
                        try {
                            $jiraProvider = app(\App\Services\PM\Providers\JiraProvider::class);
                            $syncedProjects = $jiraProvider->syncProjects($record);
                            ReconcilePmWorkItemsJob::dispatch();

                            Notification::make()
                                ->title('PM Integration Sync Started')
                                ->body("Synced " . count($syncedProjects) . " project(s). Work item reconciliation job queued.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Sync Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => PmConnectionResource\Pages\ManagePmConnections::route('/'),
        ];
    }
}
