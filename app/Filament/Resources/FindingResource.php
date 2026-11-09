<?php

namespace App\Filament\Resources;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Filament\Resources\FindingResource\Pages;
use App\Filament\Resources\FindingResource\RelationManagers;
use App\Models\Client;
use App\Models\Finding;
use App\Models\User;
use App\Services\Intelligence\AIAnalyst;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FindingResource extends Resource
{
    protected static ?string $model = Finding::class;

    protected static ?string $navigationIcon  = 'heroicon-o-magnifying-glass-circle';
    protected static ?string $navigationLabel = 'Findings';
    protected static ?string $navigationGroup = 'Intelligence';
    protected static ?int    $navigationSort  = 1;

    public static function canViewAny(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('findings.view_any') || $user->hasPermission('findings.view');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = static::getEloquentQuery()
            ->whereIn('status', [
                FindingStatus::New->value,
                FindingStatus::Investigating->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Form (for editing status / notes inline)
    // ─────────────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('status')
                ->options(collect(FindingStatus::cases())->mapWithKeys(
                    fn ($s) => [$s->value => $s->label()]
                ))
                ->required(),

            Forms\Components\Select::make('severity')
                ->options(collect(FindingSeverity::cases())->mapWithKeys(
                    fn ($s) => [$s->value => $s->label()]
                ))
                ->required(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Table
    // ─────────────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('detected_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->color(fn (FindingSeverity $state) => $state->color())
                    ->formatStateUsing(fn (FindingSeverity $state) => $state->label())
                    ->sortable()
                    ->grow(false),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->limit(20)
                    ->tooltip(fn (Finding $record) => $record->client?->name)
                    ->grow(false),

                Tables\Columns\TextColumn::make('title')
                    ->label('Finding')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (Finding $record) => $record->title)
                    ->description(function (Finding $record): ?string {
                        if (!empty($record->account_identity_hash)) {
                            $display = app(\App\Services\Intelligence\CustomerPurchaseAnalysisService::class)
                                ->resolveCustomerIdentityDisplay($record->client_id, $record->account_identity_hash);
                            return $display['account_label'];
                        }
                        return null;
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('finding_category')
                    ->label('Category')
                    ->badge()
                    ->color(fn (FindingCategory $state) => $state->color())
                    ->formatStateUsing(fn (FindingCategory $state) => $state->label())
                    ->grow(false),

                Tables\Columns\TextColumn::make('estimated_revenue_impact')
                    ->label('Impact')
                    ->money('USD')
                    ->description(fn (Finding $record): ?string => $record->impact_nature instanceof \App\Enums\ImpactNature
                        ? $record->impact_nature->label()
                        : ($record->impact_nature ? \Illuminate\Support\Str::headline($record->impact_nature) : null))
                    ->sortable()
                    ->grow(false),

                Tables\Columns\TextColumn::make('responsibleUser.name')
                    ->label('Assigned')
                    ->placeholder('Unassigned')
                    ->sortable()
                    ->grow(false),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (FindingStatus $state) => $state->color())
                    ->formatStateUsing(fn (FindingStatus $state) => $state->label())
                    ->description(function (Finding $record): ?string {
                        if ($record->isActionCompleted()) {
                            if ($record->isOutcomeMeasured()) {
                                if ($record->hasObservedPostActionOrders()) {
                                    $observed = number_format($record->getObservedPostActionRevenue(), 0);
                                    if ($record->hasModeledIncrementalLift()) {
                                        $lift = number_format($record->getModeledIncrementalLift(), 0);
                                        return "Observed: \${$observed} (Modeled Lift: \${$lift})";
                                    }
                                    return "Observed: \${$observed} (Within Baseline)";
                                }
                                return 'Outcome Measured (0 Orders)';
                            }
                            return 'Action Done (Awaiting Results)';
                        }
                        return null;
                    })
                    ->sortable()
                    ->grow(false),

                Tables\Columns\TextColumn::make('confidence_score')
                    ->label('Conf.')
                    ->formatStateUsing(fn ($state) => $state ? round($state * 100) . '%' : '—')
                    ->sortable()
                    ->grow(false)
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('detected_at')
                    ->label('Detected')
                    ->date('M j')
                    ->sortable()
                    ->grow(false),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client_id')
                    ->label('Client')
                    ->options(function () {
                        /** @var User|null $user */
                        $user = \Illuminate\Support\Facades\Auth::user();
                        if (! $user) return [];

                        $query = Client::external();
                        if (! $user->isSuperAdmin()) {
                            $assignedClientIds = $user->getAssignedClientIds();
                            if (! in_array('*', $assignedClientIds)) {
                                $query->whereIn('id', $assignedClientIds);
                            }
                        }
                        return $query->pluck('name', 'id')->toArray();
                    })
                    ->searchable(),

                Tables\Filters\SelectFilter::make('severity')
                    ->options(collect(FindingSeverity::cases())->mapWithKeys(
                        fn ($s) => [$s->value => $s->label()]
                    ))
                    ->multiple(),

                Tables\Filters\SelectFilter::make('finding_category')
                    ->label('Category')
                    ->options(collect(FindingCategory::cases())->mapWithKeys(
                        fn ($c) => [$c->value => $c->label()]
                    ))
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(FindingStatus::cases())->mapWithKeys(
                        fn ($s) => [$s->value => $s->label()]
                    ))
                    ->multiple(),

                Tables\Filters\SelectFilter::make('visibility_classification')
                    ->label('Visibility')
                    ->options([
                        'customer_visible' => 'Customer Visible',
                        'internal'         => 'Internal Only',
                    ])
                    ->visible(fn () => ! auth()->user()?->isClientOnly()),
            ])
            ->actions([
                Tables\Actions\Action::make('mark_under_review')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->visible(fn (Finding $r) => ! auth()->user()?->isClientOnly() && $r->status === FindingStatus::New)
                    ->action(function (Finding $r) {
                        $r->transitionTo(FindingStatus::UnderReview, auth()->user(), 'Marked under review in table');
                    })
                    ->successNotificationTitle('Marked as under review'),

                Tables\Actions\Action::make('mark_approved')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Finding $r) => ! auth()->user()?->isClientOnly() && in_array($r->status, [FindingStatus::New, FindingStatus::UnderReview, FindingStatus::Investigating]))
                    ->action(function (Finding $r) {
                        $r->transitionTo(FindingStatus::Approved, auth()->user(), 'Approved in table');
                    })
                    ->successNotificationTitle('Approved finding'),

                Tables\Actions\Action::make('assign_user')
                    ->label('Assign')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->visible(fn () => ! auth()->user()?->isClientOnly())
                    ->form([
                        Forms\Components\Select::make('responsible_user_id')
                            ->label('Forge Team Member')
                            ->options(fn (Finding $r) => User::getAuthorizedUsersForClient($r->client_id)->pluck('name', 'id'))
                            ->default(fn (Finding $r) => $r->responsible_user_id ?? auth()->id())
                            ->required()
                            ->searchable(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Assignment Instructions / Notes')
                            ->placeholder('Optional instructions for the assignee...'),
                    ])
                    ->action(function (Finding $r, array $data) {
                        $assignee = User::find($data['responsible_user_id']);
                        $r->transitionTo(FindingStatus::Assigned, auth()->user(), $data['notes'] ?? null, $assignee);
                    })
                    ->successNotificationTitle('Finding assigned'),

                Tables\Actions\Action::make('mark_resolved')
                    ->label('Complete Action')
                    ->modalHeading('Complete Commercial Action')
                    ->modalDescription('Record representative outreach or technical action taken. Note: Marking an action complete documents operational execution and transfers the finding to outcome observation — it does NOT assume or claim recovered revenue.')
                    ->modalSubmitActionLabel('Mark Action Completed')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Finding $r) => ! auth()->user()?->isClientOnly() && ! in_array($r->status, [FindingStatus::Resolved, FindingStatus::Dismissed]))
                    ->form([
                        Forms\Components\Select::make('business_outcome')
                            ->label('Action Type / Category')
                            ->options([
                                'reorder_secured'       => 'Reorder Secured',
                                'meeting_held'          => 'Client Meeting Held',
                                'quote_submitted'       => 'Quote / Proposal Submitted',
                                'cadence_normalized'    => 'Account Cadence Normalized',
                                'technical_fix_deployed'=> 'Technical Fix Deployed',
                                'no_action_needed'      => 'Reviewed - No Immediate Action',
                            ])
                            ->default('reorder_secured')
                            ->required(),
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Action Taken & Resolution Notes')
                            ->required()
                            ->minLength(10)
                            ->placeholder('Record what action was taken (min 10 characters)...'),
                    ])
                    ->action(function (Finding $r, array $data) {
                        $r->transitionTo(FindingStatus::Resolved, auth()->user(), $data['resolution_notes'], null, $data['business_outcome'] ?? null);
                    })
                    ->successNotificationTitle('Action marked completed; observing revenue outcome'),

                Tables\Actions\Action::make('mark_dismissed')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (Finding $r) => ! auth()->user()?->isClientOnly() && ! in_array($r->status, [FindingStatus::Resolved, FindingStatus::Dismissed]))
                    ->form([
                        Forms\Components\Textarea::make('dismissal_reason')
                            ->label('Dismissal Reason')
                            ->required()
                            ->placeholder('Specify why this finding is being dismissed...'),
                    ])
                    ->action(function (Finding $r, array $data) {
                        $r->transitionTo(FindingStatus::Dismissed, auth()->user(), $data['dismissal_reason']);
                    })
                    ->successNotificationTitle('Finding dismissed'),

                Tables\Actions\Action::make('audit_trail')
                    ->label('Audit Trail')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Finding $r) => "Audit History: {$r->title}")
                    ->modalContent(fn (Finding $r) => view('filament.components.finding-audit-trail', ['finding' => $r]))
                    ->modalSubmitAction(false),

                Tables\Actions\Action::make('generate_ai')
                    ->label('AI Analysis')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(fn (Finding $r) => ! auth()->user()?->isClientOnly() && ! $r->recommendations()->exists())
                    ->action(function (Finding $r): void {
                        $result = (new AIAnalyst())->analyse($r);
                        if ($result) {
                            Notification::make()->title('AI analysis generated')->success()->send();
                        } else {
                            Notification::make()
                                ->title('AI analysis failed')
                                ->body('Check that GEMINI_API_KEY is configured in .env')
                                ->warning()
                                ->send();
                        }
                    }),

                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_resolve')
                        ->label('Mark Resolved')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn () => ! auth()->user()?->isClientOnly())
                        ->action(fn ($records) => $records->each(
                            fn ($r) => $r->update(['status' => FindingStatus::Resolved->value])
                        )),
                    Tables\Actions\BulkAction::make('bulk_ignore')
                        ->label('Ignore')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->visible(fn () => ! auth()->user()?->isClientOnly())
                        ->action(fn ($records) => $records->each(
                            fn ($r) => $r->update(['status' => FindingStatus::Ignored->value])
                        )),
                ]),
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infolist (view page)
    // ─────────────────────────────────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\ViewEntry::make('ai_polling_indicator')
                ->view('filament.resources.finding-resource.entries.polling-indicator')
                ->columnSpanFull(),

            Infolists\Components\Section::make('Finding Details')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('client.name')
                        ->label('Client'),

                    Infolists\Components\TextEntry::make('severity')
                        ->badge()
                        ->color(fn (FindingSeverity $state) => $state->color())
                        ->formatStateUsing(fn (FindingSeverity $state) => $state->label()),

                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->color(fn (FindingStatus $state) => $state->color())
                        ->formatStateUsing(fn (FindingStatus $state) => $state->label()),

                    Infolists\Components\TextEntry::make('finding_category')
                        ->label('Category')
                        ->badge()
                        ->color(fn (FindingCategory $state) => $state->color())
                        ->formatStateUsing(fn (FindingCategory $state) => $state->label()),

                    Infolists\Components\TextEntry::make('confidence_score')
                        ->label('Confidence')
                        ->formatStateUsing(fn ($state) => $state ? round($state * 100) . '%' : '—'),

                    Infolists\Components\TextEntry::make('estimated_revenue_impact')
                        ->label('Est. Revenue Impact')
                        ->formatStateUsing(fn ($state) => $state ? '$' . number_format($state, 2) : '—'),

                    Infolists\Components\TextEntry::make('detected_at')
                        ->label('Detected')
                        ->dateTime('M j, Y g:i A'),

                    Infolists\Components\TextEntry::make('finding_type')
                        ->label('Type')
                        ->fontFamily('mono'),

                    Infolists\Components\TextEntry::make('customer_account_display')
                        ->label('Customer Account')
                        ->visible(fn (Finding $record): bool => !empty($record->account_identity_hash))
                        ->state(function (Finding $record): string {
                            $display = app(\App\Services\Intelligence\CustomerPurchaseAnalysisService::class)
                                ->resolveCustomerIdentityDisplay($record->client_id, $record->account_identity_hash);
                            return $display['account_label'];
                        })
                        ->helperText(function (Finding $record): ?string {
                            $display = app(\App\Services\Intelligence\CustomerPurchaseAnalysisService::class)
                                ->resolveCustomerIdentityDisplay($record->client_id, $record->account_identity_hash);
                            return $display['secondary_badge'] ? "Reference: {$display['secondary_badge']}" : null;
                        }),
                ]),

            Infolists\Components\Section::make('Description')
                ->schema([
                    Infolists\Components\TextEntry::make('description')
                        ->hiddenLabel()
                        ->markdown(),
                ]),

            Infolists\Components\Section::make('AI Investigation Report')
                ->icon('heroicon-o-sparkles')
                ->iconColor('warning')
                ->visible(fn (Finding $record) => $record->recommendations()->exists())
                ->schema([
                    Infolists\Components\RepeatableEntry::make('recommendations')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\ViewEntry::make('formatted_report')
                                ->hiddenLabel()
                                ->view('filament.infolists.investigation-report')
                                ->columnSpanFull(),

                            Infolists\Components\TextEntry::make('model_used')
                                ->label('Model')
                                ->fontFamily('mono')
                                ->badge()
                                ->color('gray'),
                        ]),
                ]),

            Infolists\Components\Section::make('Supporting Data')
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('metadata_display')
                        ->hiddenLabel()
                        ->fontFamily('mono')
                        ->getStateUsing(
                            fn (Finding $record): string =>
                                json_encode($record->metadata_json ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        )
                        ->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 0.78rem;']),
                ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pages
    // ─────────────────────────────────────────────────────────────────────────

    public static function getRelations(): array
    {
        return [
            RelationManagers\RecommendationsRelationManager::class,
            RelationManagers\InvestigationNotesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFindings::route('/'),
            'view'  => Pages\ViewFinding::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['client', 'recommendations.outcome']);

        // Findings resource tracks external client intelligence; exclude internal agency entities
        $query->whereHas('client', fn ($q) => $q->external());

        /** @var User|null $currentUser */
        $currentUser = \Illuminate\Support\Facades\Auth::user();

        if (! $currentUser || $currentUser->isSuperAdmin()) {
            return $query;
        }

        $assignedClientIds = $currentUser->getAssignedClientIds();
        $query->whereIn('client_id', $assignedClientIds);

        // Filter by visibility classification if customer role
        if ($currentUser->hasRole([\App\Models\Role::ROLE_CUSTOMER_ADMIN, \App\Models\Role::ROLE_CLIENT_USER])
            && ! $currentUser->hasRole([\App\Models\Role::ROLE_PRODUCT_OWNER, \App\Models\Role::ROLE_ANALYST, \App\Models\Role::ROLE_ENGINEER])) {
            $query->customerVisible()
                ->whereNotIn('visibility_classification', [
                    \App\Services\VisibilityService::CLASSIFICATION_RESTRICTED_TECHNICAL,
                    \App\Services\VisibilityService::CLASSIFICATION_RESTRICTED_FINANCIAL,
                ]);
        }

        return $query;
    }
}
