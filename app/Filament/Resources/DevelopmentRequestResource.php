<?php

namespace App\Filament\Resources;

use App\Enums\AgentCapabilityTier;
use App\Enums\DevelopmentRequestStatus;
use App\Filament\Resources\DevelopmentRequestResource\Pages;
use App\Models\DevelopmentRequest;
use App\Models\PmWorkItem;
use App\Models\Project;
use App\Models\ProjectEnvironmentMapping;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class DevelopmentRequestResource extends Resource
{
    protected static ?string $model = DevelopmentRequest::class;

    protected static ?string $slug = 'delivery/requests';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Delivery';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Development Requests';

    protected static ?string $modelLabel = 'development request';

    protected static ?string $pluralModelLabel = 'Development Requests';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Request details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('request_type')
                            ->label('Work type')
                            ->options([
                                'investigation' => 'Investigation',
                                'development' => 'Development',
                            ])
                            ->native(false)
                            ->required(),

                        Forms\Components\Select::make('priority')
                            ->options([
                                'low' => 'Low',
                                'medium' => 'Medium',
                                'high' => 'High',
                                'critical' => 'Critical',
                            ])
                            ->default('medium')
                            ->native(false)
                            ->required(),

                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('description')
                            ->label('Instructions and acceptance criteria')
                            ->rows(8)
                            ->maxLength(50000)
                            ->columnSpanFull()
                            ->helperText('Add only the context the agent needs beyond the Jira ticket.'),
                    ]),

                Forms\Components\Section::make('Execution routing')
                    ->description('Choose friendly routing options. Forge resolves the approved VM, repository, workspace, and model group behind the scenes.')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Project')
                            ->options(fn (): array => static::projectOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('environment_key', null);
                                $set('selected_capability_tier', null);
                                $set('pm_work_item_id', null);
                            }),

                        Forms\Components\Select::make('environment_key')
                            ->label('Environment')
                            ->options(fn (Get $get): array => static::environmentOptions($get('project_id')))
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                $mapping = static::mappingFor($get('project_id'), $state);
                                $set('selected_capability_tier', $mapping?->default_capability_tier?->value);
                                $set('pm_work_item_id', null);
                            }),

                        Forms\Components\Select::make('selected_capability_tier')
                            ->label('Agent seniority')
                            ->options(fn (Get $get): array => static::capabilityTierOptions(
                                $get('project_id'),
                                $get('environment_key')
                            ))
                            ->native(false)
                            ->required()
                            ->live()
                            ->helperText('Seniority selects an approved model group; it does not expose provider credentials.'),

                        Forms\Components\Placeholder::make('routing_preview')
                            ->label('Routing check')
                            ->content(fn (Get $get): HtmlString => static::routingPreview(
                                $get('project_id'),
                                $get('environment_key'),
                                $get('selected_capability_tier')
                            ))
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Jira task')
                    ->description('Choose the existing Jira issue that defines the work. Jira issues never start an agent automatically.')
                    ->schema([
                        Forms\Components\Select::make('pm_work_item_id')
                            ->label('Jira ticket')
                            ->placeholder('Search by ticket ID or title')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->options(fn (Get $get): array => static::jiraWorkItemOptions(
                                $get('project_id'),
                                $get('environment_key')
                            ))
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => static::jiraWorkItemOptions(
                                $get('project_id'),
                                $get('environment_key'),
                                $search
                            ))
                            ->getOptionLabelUsing(fn ($value): ?string => static::jiraWorkItemLabel($value))
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                if (! $state || filled($get('title'))) {
                                    return;
                                }

                                $workItem = PmWorkItem::query()->find($state);
                                if ($workItem) {
                                    $set('title', $workItem->summary);
                                }
                            })
                            ->helperText('The ticket must already be available through the Jira integration.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('pmWorkItem.external_item_key')
                    ->label('Jira')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->limit(55)
                    ->tooltip(fn (DevelopmentRequest $record): string => $record->title)
                    ->wrap(),

                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('request_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'investigation' ? 'info' : 'primary'),

                Tables\Columns\TextColumn::make('selected_capability_tier')
                    ->label('Seniority')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Not routed')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('state')
                    ->badge()
                    ->icon(fn (DevelopmentRequestStatus $state): string => $state->icon())
                    ->color(fn (DevelopmentRequestStatus $state): string => $state->color())
                    ->formatStateUsing(fn (DevelopmentRequestStatus $state): string => $state->label())
                    ->sortable(),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Owner')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')
                    ->options(collect(DevelopmentRequestStatus::cases())->mapWithKeys(
                        fn (DevelopmentRequestStatus $status): array => [$status->value => $status->label()]
                    )->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('request_type')
                    ->label('Type')
                    ->options([
                        'investigation' => 'Investigation',
                        'development' => 'Development',
                    ]),

                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Project')
                    ->options(fn (): array => static::projectOptions())
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('No development requests yet')
            ->emptyStateDescription('Create a request from an existing Jira ticket, then review its routing before starting an agent.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('New Request'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Request overview')
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('state')
                            ->badge()
                            ->icon(fn (DevelopmentRequestStatus $state): string => $state->icon())
                            ->color(fn (DevelopmentRequestStatus $state): string => $state->color())
                            ->formatStateUsing(fn (DevelopmentRequestStatus $state): string => $state->label()),

                        Infolists\Components\TextEntry::make('request_type')
                            ->label('Type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                        Infolists\Components\TextEntry::make('priority')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state))
                            ->color(fn (string $state): string => match ($state) {
                                'critical' => 'danger',
                                'high' => 'warning',
                                'low' => 'gray',
                                default => 'info',
                            }),

                        Infolists\Components\TextEntry::make('owner.name')
                            ->label('Owner'),

                        Infolists\Components\TextEntry::make('project.name')
                            ->label('Project'),

                        Infolists\Components\TextEntry::make('environment_key')
                            ->label('Environment')
                            ->badge(),

                        Infolists\Components\TextEntry::make('selected_capability_tier')
                            ->label('Agent seniority')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                        Infolists\Components\TextEntry::make('correlation_identifier')
                            ->label('Request ID')
                            ->fontFamily('mono')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('title')
                            ->weight('bold')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('description')
                            ->label('Instructions and acceptance criteria')
                            ->markdown()
                            ->default('No additional instructions were provided.')
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make('Jira snapshot')
                    ->description('This is the Jira context captured when the request was last saved.')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('pmWorkItem.external_item_key')
                            ->label('Ticket')
                            ->badge()
                            ->color('gray'),

                        Infolists\Components\TextEntry::make('jira_snapshot.issue_type')
                            ->label('Issue type'),

                        Infolists\Components\TextEntry::make('jira_snapshot.status')
                            ->label('Jira status'),

                        Infolists\Components\TextEntry::make('jira_snapshot.summary')
                            ->label('Summary')
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make('Resolved execution target')
                    ->description('The routing snapshot is fixed when this request is saved, so later mapping changes do not silently redirect it.')
                    ->columns(3)
                    ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                    ->collapsed()
                    ->schema([
                        Infolists\Components\TextEntry::make('routing_snapshot.mapping_version')
                            ->label('Mapping version'),
                        Infolists\Components\TextEntry::make('routing_snapshot.default_branch')
                            ->label('Base branch')
                            ->fontFamily('mono'),
                        Infolists\Components\TextEntry::make('routing_snapshot.workspace_path')
                            ->label('Workspace')
                            ->fontFamily('mono'),
                        Infolists\Components\TextEntry::make('routing_snapshot.gcp.vm_name')
                            ->label('VM')
                            ->fontFamily('mono'),
                        Infolists\Components\TextEntry::make('routing_snapshot.gcp.zone')
                            ->label('Zone')
                            ->fontFamily('mono'),
                        Infolists\Components\TextEntry::make('resolved_model_group')
                            ->label('Model group')
                            ->fontFamily('mono')
                            ->getStateUsing(fn (DevelopmentRequest $record): ?string => data_get(
                                $record->routing_snapshot,
                                'model_group_aliases.'.$record->selected_capability_tier
                            )),
                    ]),

                Infolists\Components\Section::make('Status timeline')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('statusHistory')
                            ->hiddenLabel()
                            ->schema([
                                Infolists\Components\TextEntry::make('new_state')
                                    ->label('Status / event')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => DevelopmentRequestStatus::tryFrom($state)?->label()
                                        ?? ucfirst(str_replace('_', ' ', $state))),
                                Infolists\Components\TextEntry::make('actor_label')
                                    ->label('Actor')
                                    ->default('System'),
                                Infolists\Components\TextEntry::make('reason')
                                    ->columnSpan(2),
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Time')
                                    ->dateTime('M j, Y g:i A'),
                            ])
                            ->columns(5),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'client',
            'project',
            'owner',
            'pmWorkItem',
            'projectEnvironmentMapping',
            'statusHistory.actor',
        ]);

        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $projectIds = $user->getAssignedProjectIds();
        $clientIds = $user->getAssignedClientIds();

        return $query->where(function (Builder $scope) use ($clientIds, $projectIds): void {
            if (! in_array('*', $projectIds, true)) {
                $scope->whereIn('project_id', $projectIds);
            }

            if (! in_array('*', $clientIds, true)) {
                $scope->orWhereIn('client_id', $clientIds);
            }
        });
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereNotIn('state', DevelopmentRequestStatus::terminalValues())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevelopmentRequests::route('/'),
            'create' => Pages\CreateDevelopmentRequest::route('/create'),
            'view' => Pages\ViewDevelopmentRequest::route('/{record}'),
            'edit' => Pages\EditDevelopmentRequest::route('/{record}/edit'),
        ];
    }

    public static function projectOptions(): array
    {
        $query = Project::query()
            ->whereHas('environmentMappings', fn (Builder $mapping): Builder => $mapping->where('is_active', true))
            ->orderBy('name');

        /** @var User|null $user */
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        if (! $user->isSuperAdmin()) {
            $projectIds = $user->getAssignedProjectIds();
            if (! in_array('*', $projectIds, true)) {
                $query->whereIn('id', $projectIds);
            }
        }

        return $query->pluck('name', 'id')->all();
    }

    public static function environmentOptions($projectId): array
    {
        if (! $projectId) {
            return [];
        }

        return ProjectEnvironmentMapping::query()
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->orderBy('environment_key')
            ->pluck('environment_key', 'environment_key')
            ->map(fn (string $environment): string => ucfirst(str_replace(['_', '-'], ' ', $environment)))
            ->all();
    }

    public static function capabilityTierOptions($projectId, $environmentKey): array
    {
        $mapping = static::mappingFor($projectId, $environmentKey);
        if (! $mapping) {
            return [];
        }

        return collect($mapping->allowed_capability_tiers ?? [])
            ->mapWithKeys(fn (string $tier): array => [
                $tier => ucfirst(AgentCapabilityTier::tryFrom($tier)?->value ?? $tier),
            ])
            ->all();
    }

    public static function jiraWorkItemOptions($projectId, $environmentKey, ?string $search = null): array
    {
        if (! $projectId) {
            return [];
        }

        $project = Project::query()->find($projectId);
        if (! $project) {
            return [];
        }

        $mapping = static::mappingFor($projectId, $environmentKey);
        $query = PmWorkItem::query()
            ->where('client_id', $project->client_id)
            ->latest('external_updated_at')
            ->limit(50);

        if ($mapping?->pm_project_id) {
            $query->where('pm_project_id', $mapping->pm_project_id);
        }

        if (filled($search)) {
            $query->where(function (Builder $filter) use ($search): void {
                $filter->where('external_item_key', 'like', "%{$search}%")
                    ->orWhere('summary', 'like', "%{$search}%");
            });
        }

        return $query->get()->mapWithKeys(fn (PmWorkItem $item): array => [
            $item->getKey() => "{$item->external_item_key} — {$item->summary}",
        ])->all();
    }

    public static function jiraWorkItemLabel($workItemId): ?string
    {
        if (! $workItemId) {
            return null;
        }

        $item = PmWorkItem::query()->find($workItemId);

        return $item ? "{$item->external_item_key} — {$item->summary}" : null;
    }

    public static function mappingFor($projectId, $environmentKey): ?ProjectEnvironmentMapping
    {
        if (! $projectId || ! $environmentKey) {
            return null;
        }

        return ProjectEnvironmentMapping::query()
            ->with('repository')
            ->where('project_id', $projectId)
            ->where('environment_key', $environmentKey)
            ->where('is_active', true)
            ->first();
    }

    private static function routingPreview($projectId, $environmentKey, $tier): HtmlString
    {
        if (! $projectId || ! $environmentKey) {
            return new HtmlString('<span class="text-sm text-gray-500">Select a project and environment to verify routing.</span>');
        }

        $mapping = static::mappingFor($projectId, $environmentKey);
        if (! $mapping) {
            return new HtmlString('<span class="text-sm font-medium text-danger-600">No active execution mapping is available.</span>');
        }

        if (! $tier || ! in_array($tier, $mapping->allowed_capability_tiers ?? [], true)) {
            return new HtmlString('<span class="text-sm font-medium text-warning-600">Choose one of the seniority levels approved for this environment.</span>');
        }

        $label = ucfirst((string) $tier);
        $message = "Ready: {$label} capability is approved for ".ucfirst((string) $environmentKey).'.';

        if (Auth::user()?->isSuperAdmin()) {
            $modelGroup = data_get($mapping->model_group_aliases, $tier, 'not configured');
            $repository = $mapping->repository?->name ?? 'repository';
            $message .= ' Target: '.e($repository).' / '.e($mapping->default_branch)
                .'; model group: '.e((string) $modelGroup).'.';
        }

        return new HtmlString('<span class="text-sm font-medium text-success-600">'.e($message).'</span>');
    }
}
