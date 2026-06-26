<?php

namespace App\Filament\Resources;

use App\Enums\FindingCategory;
use App\Filament\Resources\KnowledgeBaseResource\Pages;
use App\Models\Client;
use App\Models\IntelligenceMemory;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeBaseResource extends Resource
{
    protected static ?string $model = IntelligenceMemory::class;

    protected static ?string $navigationIcon  = 'heroicon-o-book-open';
    protected static ?string $navigationLabel = 'Knowledge Base';
    protected static ?string $navigationGroup = 'Intelligence';
    protected static ?int    $navigationSort  = 5;
    protected static ?string $modelLabel       = 'Knowledge Entry';
    protected static ?string $pluralModelLabel = 'Knowledge Base';
    protected static ?string $slug = 'knowledge-base';

    public static function getNavigationBadge(): ?string
    {
        $count = IntelligenceMemory::count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Table
    // ─────────────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('finding_category')
                    ->label('Category')
                    ->badge()
                    ->color(fn ($state) => $state instanceof FindingCategory ? $state->color() : 'gray')
                    ->formatStateUsing(fn ($state) => $state instanceof FindingCategory ? $state->label() : ($state ?? '—')),

                Tables\Columns\TextColumn::make('pattern_description')
                    ->label('Pattern')
                    ->limit(60)
                    ->searchable()
                    ->wrap()
                    ->tooltip(fn ($record) => $record->pattern_description),

                Tables\Columns\TextColumn::make('root_cause')
                    ->label('Root Cause')
                    ->limit(50)
                    ->wrap()
                    ->tooltip(fn ($record) => $record->root_cause),

                Tables\Columns\TextColumn::make('resolution')
                    ->label('Resolution')
                    ->limit(50)
                    ->wrap()
                    ->tooltip(fn ($record) => $record->resolution),

                Tables\Columns\TextColumn::make('outcome')
                    ->label('Outcome')
                    ->limit(30),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Captured')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('finding_category')
                    ->label('Category')
                    ->options(collect(FindingCategory::cases())->mapWithKeys(
                        fn ($c) => [$c->value => $c->label()]
                    ))
                    ->multiple(),

                Tables\Filters\SelectFilter::make('client_id')
                    ->label('Client')
                    ->options(Client::pluck('name', 'id'))
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->emptyStateHeading('Knowledge base is empty')
            ->emptyStateDescription('Entries are automatically created when findings are resolved with investigation notes.')
            ->emptyStateIcon('heroicon-o-book-open');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infolist (view page)
    // ─────────────────────────────────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Pattern Details')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('client.name')
                        ->label('Client'),

                    Infolists\Components\TextEntry::make('finding_category')
                        ->label('Category')
                        ->badge()
                        ->color(fn ($state) => $state instanceof FindingCategory ? $state->color() : 'gray')
                        ->formatStateUsing(fn ($state) => $state instanceof FindingCategory ? $state->label() : ($state ?? '—')),

                    Infolists\Components\TextEntry::make('finding_type')
                        ->label('Finding Type')
                        ->fontFamily('mono')
                        ->badge()
                        ->color('gray'),

                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Captured')
                        ->dateTime('M j, Y g:i A'),
                ]),

            Infolists\Components\Section::make('Pattern Description')
                ->schema([
                    Infolists\Components\TextEntry::make('pattern_description')
                        ->hiddenLabel()
                        ->markdown(),
                ]),

            Infolists\Components\Section::make('Root Cause')
                ->visible(fn ($record) => ! empty($record->root_cause))
                ->schema([
                    Infolists\Components\TextEntry::make('root_cause')
                        ->hiddenLabel()
                        ->markdown(),
                ]),

            Infolists\Components\Section::make('Resolution')
                ->visible(fn ($record) => ! empty($record->resolution))
                ->schema([
                    Infolists\Components\TextEntry::make('resolution')
                        ->hiddenLabel()
                        ->markdown(),
                ]),

            Infolists\Components\Section::make('Outcome')
                ->visible(fn ($record) => ! empty($record->outcome))
                ->schema([
                    Infolists\Components\TextEntry::make('outcome')
                        ->hiddenLabel()
                        ->markdown(),
                ]),

            Infolists\Components\Section::make('Metadata')
                ->collapsed()
                ->visible(fn ($record) => ! empty($record->metadata_json))
                ->schema([
                    Infolists\Components\TextEntry::make('metadata_display')
                        ->hiddenLabel()
                        ->fontFamily('mono')
                        ->getStateUsing(
                            fn ($record): string =>
                                json_encode($record->metadata_json ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        )
                        ->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 0.78rem;']),
                ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pages (read-only — no create/edit)
    // ─────────────────────────────────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKnowledgeBase::route('/'),
            'view'  => Pages\ViewKnowledgeBase::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('client');
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
