<?php

namespace App\Filament\Resources\FindingResource\RelationManagers;

use App\Models\RecommendationOutcome;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RecommendationsRelationManager extends RelationManager
{
    protected static string $relationship = 'recommendations';

    protected static ?string $title = 'AI Recommendations';

    protected static ?string $icon = 'heroicon-o-sparkles';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ai_summary')
                    ->label('Summary')
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn ($record) => $record->ai_summary),

                Tables\Columns\TextColumn::make('recommendation_text')
                    ->label('Recommended Actions')
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn ($record) => $record->recommendation_text),

                Tables\Columns\TextColumn::make('model_used')
                    ->label('Model')
                    ->badge()
                    ->color('gray')
                    ->fontFamily('mono'),

                Tables\Columns\IconColumn::make('outcome_status')
                    ->label('Outcome')
                    ->getStateUsing(function ($record) {
                        $outcome = $record->outcome;
                        if (! $outcome) return 'pending';
                        return $outcome->implemented ? 'implemented' : 'recorded';
                    })
                    ->icon(fn (string $state) => match ($state) {
                        'implemented' => 'heroicon-o-check-circle',
                        'recorded'    => 'heroicon-o-clock',
                        default       => 'heroicon-o-minus-circle',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'implemented' => 'success',
                        'recorded'    => 'warning',
                        default       => 'gray',
                    })
                    ->tooltip(fn (string $state) => match ($state) {
                        'implemented' => 'Implemented',
                        'recorded'    => 'Outcome recorded (not yet implemented)',
                        default       => 'No outcome recorded',
                    }),

                Tables\Columns\TextColumn::make('outcome.actual_impact')
                    ->label('Actual Impact')
                    ->formatStateUsing(fn ($state) => $state ? '$' . number_format($state, 2) : '—')
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray')),
            ])
            ->actions([
                Tables\Actions\Action::make('view_detail')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Recommendation Detail')
                    ->modalWidth('2xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->infolist([
                        \Filament\Infolists\Components\TextEntry::make('ai_summary')
                            ->label('Executive Summary')
                            ->markdown()
                            ->columnSpanFull(),

                        \Filament\Infolists\Components\TextEntry::make('recommendation_text')
                            ->label('Recommended Actions')
                            ->markdown()
                            ->columnSpanFull(),

                        \Filament\Infolists\Components\TextEntry::make('confidence_reasoning')
                            ->label('Confidence Reasoning')
                            ->markdown()
                            ->columnSpanFull(),
                    ]),

                Tables\Actions\Action::make('record_outcome')
                    ->label('Record Outcome')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('primary')
                    ->modalHeading('Record Recommendation Outcome')
                    ->modalDescription('Track whether this recommendation was implemented and its actual business impact.')
                    ->modalWidth('lg')
                    ->fillForm(function ($record): array {
                        $outcome = $record->outcome;
                        if (! $outcome) return ['implemented' => false];
                        return [
                            'implemented'      => $outcome->implemented,
                            'implemented_at'    => $outcome->implemented_at,
                            'estimated_impact'  => $outcome->estimated_impact,
                            'actual_impact'     => $outcome->actual_impact,
                            'outcome_notes'     => $outcome->outcome_notes,
                        ];
                    })
                    ->form([
                        Forms\Components\Toggle::make('implemented')
                            ->label('Was this recommendation implemented?')
                            ->live()
                            ->default(false),

                        Forms\Components\DateTimePicker::make('implemented_at')
                            ->label('Implementation Date')
                            ->visible(fn (Forms\Get $get) => $get('implemented'))
                            ->default(now()),

                        Forms\Components\TextInput::make('estimated_impact')
                            ->label('Estimated Revenue Impact')
                            ->numeric()
                            ->prefix('$')
                            ->placeholder('e.g. 5000')
                            ->helperText('Estimated dollar impact of implementing this recommendation'),

                        Forms\Components\TextInput::make('actual_impact')
                            ->label('Actual Revenue Impact')
                            ->numeric()
                            ->prefix('$')
                            ->placeholder('e.g. 3200')
                            ->helperText('Measured dollar impact after implementation (positive = gain, negative = loss)'),

                        Forms\Components\Textarea::make('outcome_notes')
                            ->label('Notes')
                            ->placeholder('Describe what happened after implementation...')
                            ->rows(3),
                    ])
                    ->action(function ($record, array $data): void {
                        RecommendationOutcome::updateOrCreate(
                            ['recommendation_id' => $record->id],
                            [
                                'implemented'      => $data['implemented'] ?? false,
                                'implemented_at'    => $data['implemented'] ? ($data['implemented_at'] ?? now()) : null,
                                'estimated_impact'  => $data['estimated_impact'] ?? null,
                                'actual_impact'     => $data['actual_impact'] ?? null,
                                'outcome_notes'     => $data['outcome_notes'] ?? null,
                            ]
                        );

                        Notification::make()
                            ->title('Outcome recorded')
                            ->body($data['implemented'] ? 'Marked as implemented' : 'Outcome notes saved')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No AI recommendations yet')
            ->emptyStateDescription('Generate AI analysis from the finding view to get recommendations.')
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
