<?php

namespace App\Filament\Resources;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Filament\Resources\FindingResource\Pages;
use App\Filament\Resources\FindingResource\RelationManagers;
use App\Models\Client;
use App\Models\Finding;
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

    public static function getNavigationBadge(): ?string
    {
        $count = Finding::whereIn('status', [
            FindingStatus::New->value,
            FindingStatus::Investigating->value,
        ])->count();

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
                    ->wrap(),

                Tables\Columns\TextColumn::make('finding_category')
                    ->label('Category')
                    ->badge()
                    ->color(fn (FindingCategory $state) => $state->color())
                    ->formatStateUsing(fn (FindingCategory $state) => $state->label())
                    ->grow(false),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (FindingStatus $state) => $state->color())
                    ->formatStateUsing(fn (FindingStatus $state) => $state->label())
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
                    ->options(Client::pluck('name', 'id'))
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
                    ->multiple()
                    ->default([FindingStatus::New->value, FindingStatus::Investigating->value]),
            ])
            ->actions([
                Tables\Actions\Action::make('mark_investigating')
                    ->label('Investigate')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->visible(fn (Finding $r) => $r->status === FindingStatus::New)
                    ->action(fn (Finding $r) => $r->update(['status' => FindingStatus::Investigating->value]))
                    ->successNotificationTitle('Marked as investigating'),

                Tables\Actions\Action::make('mark_resolved')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Finding $r) => in_array($r->status, [
                        FindingStatus::Investigating, FindingStatus::Accepted,
                    ]))
                    ->action(fn (Finding $r) => $r->update(['status' => FindingStatus::Resolved->value])),

                Tables\Actions\Action::make('generate_ai')
                    ->label('Generate AI Analysis')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(fn (Finding $r) => ! $r->recommendations()->exists())
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
                        ->action(fn ($records) => $records->each(
                            fn ($r) => $r->update(['status' => FindingStatus::Resolved->value])
                        )),
                    Tables\Actions\BulkAction::make('bulk_ignore')
                        ->label('Ignore')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
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
        return parent::getEloquentQuery()->with(['client', 'recommendations.outcome']);
    }
}
