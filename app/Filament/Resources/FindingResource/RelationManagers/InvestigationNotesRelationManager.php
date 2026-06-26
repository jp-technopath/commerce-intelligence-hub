<?php

namespace App\Filament\Resources\FindingResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InvestigationNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'investigationNotes';

    protected static ?string $title = 'Investigation Notes';

    protected static ?string $icon = 'heroicon-o-clipboard-document-list';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('root_cause')
                ->label('Root Cause Analysis')
                ->placeholder('What caused this issue? E.g., "Checkout JS bundle failed to load due to CDN cache invalidation after deployment."')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('fix_implemented')
                ->label('Fix / Action Taken')
                ->placeholder('What was done to fix it? E.g., "Rolled back CDN config, added cache-busting query params to checkout.js."')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('outcome')
                ->label('Outcome')
                ->placeholder('What was the result? E.g., "Conversion rate recovered to baseline within 24 hours."')
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('lessons_learned')
                ->label('Lessons Learned')
                ->placeholder('What should we do differently next time? E.g., "Always verify CDN cache after deployment."')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('root_cause')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Author')
                    ->icon('heroicon-o-user')
                    ->searchable(),

                Tables\Columns\TextColumn::make('root_cause')
                    ->label('Root Cause')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->root_cause)
                    ->wrap(),

                Tables\Columns\TextColumn::make('fix_implemented')
                    ->label('Fix Applied')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->fix_implemented)
                    ->wrap(),

                Tables\Columns\TextColumn::make('outcome')
                    ->label('Outcome')
                    ->limit(40)
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Note')
                    ->icon('heroicon-o-plus-circle')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading(fn ($record) => str_contains($record->root_cause ?? '', 'AI Investigation')
                        ? '🔍 AI Investigation Results'
                        : 'Investigation Note')
                    ->modalWidth('7xl')
                    ->form([
                        Forms\Components\Placeholder::make('root_cause_display')
                            ->label('Analysis')
                            ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                                '<div class="investigation-content" style="font-size: 0.875rem; line-height: 1.75; max-height: 60vh; overflow-y: auto; padding-right: 0.5rem;">'
                                . '<style>.investigation-content h1,.investigation-content h2,.investigation-content h3{font-weight:700;margin:0.75em 0 0.4em}.investigation-content h3{font-size:1.05em;color:#3b82f6}.investigation-content h2{font-size:1.15em}.investigation-content strong{font-weight:700}.investigation-content ol,.investigation-content ul{padding-left:1.5em;margin:0.5em 0}.investigation-content li{margin:0.3em 0}.investigation-content p{margin:0.5em 0}.investigation-content code{background:rgba(59,130,246,0.1);padding:0.15em 0.4em;border-radius:4px;font-size:0.85em}.investigation-content pre{background:rgba(0,0,0,0.05);padding:1em;border-radius:8px;overflow-x:auto;margin:0.75em 0}.investigation-content blockquote{border-left:3px solid #3b82f6;padding-left:1em;margin:0.75em 0;color:#64748b}.investigation-content table{width:100%;border-collapse:collapse;margin:0.75em 0}.investigation-content th,.investigation-content td{border:1px solid rgba(148,163,184,0.3);padding:0.5em 0.75em;text-align:left}.investigation-content th{background:rgba(59,130,246,0.08);font-weight:600}.investigation-content::-webkit-scrollbar{width:6px}.investigation-content::-webkit-scrollbar-track{background:transparent}.investigation-content::-webkit-scrollbar-thumb{background:rgba(148,163,184,0.3);border-radius:3px}</style>'
                                . \Illuminate\Support\Str::markdown($record->root_cause ?? 'N/A')
                                . '</div>'
                            ))
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('fix_display')
                            ->label('Next Steps / Fix Applied')
                            ->content(fn ($record) => $record->fix_implemented
                                ? new \Illuminate\Support\HtmlString(
                                    '<div class="investigation-content" style="font-size: 0.875rem; line-height: 1.75; max-height: 30vh; overflow-y: auto; padding-right: 0.5rem;">'
                                    . \Illuminate\Support\Str::markdown($record->fix_implemented)
                                    . '</div>'
                                )
                                : 'None recorded')
                            ->columnSpanFull()
                            ->visible(fn ($record) => ! empty($record->fix_implemented)),
                        Forms\Components\Placeholder::make('outcome_display')
                            ->label('Correlations / Outcome')
                            ->content(fn ($record) => $record->outcome
                                ? new \Illuminate\Support\HtmlString(
                                    '<div class="investigation-content" style="font-size: 0.875rem; line-height: 1.75; max-height: 30vh; overflow-y: auto; padding-right: 0.5rem;">'
                                    . \Illuminate\Support\Str::markdown($record->outcome)
                                    . '</div>'
                                )
                                : 'None recorded')
                            ->columnSpanFull()
                            ->visible(fn ($record) => ! empty($record->outcome)),
                        Forms\Components\Placeholder::make('lessons_display')
                            ->label('Key Data Points / Lessons')
                            ->content(fn ($record) => $record->lessons_learned
                                ? new \Illuminate\Support\HtmlString(
                                    '<div class="investigation-content" style="font-size: 0.875rem; line-height: 1.75; max-height: 30vh; overflow-y: auto; padding-right: 0.5rem;">'
                                    . \Illuminate\Support\Str::markdown($record->lessons_learned)
                                    . '</div>'
                                )
                                : 'None recorded')
                            ->columnSpanFull()
                            ->visible(fn ($record) => ! empty($record->lessons_learned)),
                        Forms\Components\Placeholder::make('meta_display')
                            ->label('')
                            ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                                '<div style="font-size: 0.75rem; color: #94a3b8; border-top: 1px solid rgba(148,163,184,0.2); padding-top: 0.75rem; margin-top: 0.5rem;">'
                                . 'By ' . e($record->user?->name ?? 'System') . ' · ' . ($record->created_at?->format('M j, Y g:i A') ?? '')
                                . '</div>'
                            ))
                            ->columnSpanFull(),
                    ]),
                Tables\Actions\EditAction::make()
                    ->visible(fn ($record) => $record->user_id === auth()->id()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => $record->user_id === auth()->id()),
            ])
            ->emptyStateHeading('No investigation notes yet')
            ->emptyStateDescription('Add a note to document root causes, fixes, and lessons learned.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }
}
