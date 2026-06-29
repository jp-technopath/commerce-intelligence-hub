<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Jobs\TriggerIntegrationSync;
use App\Models\Integration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class IntegrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'integrations';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('integration_type')
                ->options(collect(IntegrationType::cases())->mapWithKeys(
                    fn ($case) => [$case->value => $case->label()]
                ))
                ->required()
                ->live(),

            Forms\Components\Select::make('status')
                ->options(collect(IntegrationStatus::cases())->mapWithKeys(
                    fn ($case) => [$case->value => $case->label()]
                ))
                ->default(IntegrationStatus::Pending->value)
                ->required(),

            Forms\Components\KeyValue::make('settings_json')
                ->label('Settings')
                ->columnSpanFull(),

            // ── Metrics to Monitor ──────────────────────────────────────
            Forms\Components\Section::make('Metrics to Monitor')
                ->schema([
                    Forms\Components\CheckboxList::make('monitoring_config.enabled_metrics.commerce')
                        ->label('Commerce Metrics')
                        ->options([
                            'revenue'         => 'Revenue',
                            'orders'          => 'Orders',
                            'conversion_rate' => 'Conversion Rate',
                            'aov'             => 'Average Order Value',
                            'sessions'        => 'Sessions',
                            'new_customers'   => 'New Customers',
                            'return_rate'     => 'Customer Return Rate',
                        ])
                        ->default(['revenue', 'orders', 'conversion_rate', 'aov', 'sessions', 'new_customers', 'return_rate'])
                        ->columns(3)
                        ->visible(fn (Forms\Get $get) => in_array('commerce', IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    Forms\Components\CheckboxList::make('monitoring_config.enabled_metrics.behavioral')
                        ->label('Behavioral Metrics')
                        ->options([
                            'rage_clicks'    => 'Rage Clicks',
                            'dead_clicks'    => 'Dead Clicks',
                            'quick_backs'    => 'Quick Backs',
                            'script_errors'  => 'Script Errors',
                            'error_clicks'   => 'Error Clicks',
                            'friction_score' => 'Friction Score',
                        ])
                        ->default(['rage_clicks', 'dead_clicks', 'quick_backs', 'script_errors', 'error_clicks', 'friction_score'])
                        ->columns(3)
                        ->visible(fn (Forms\Get $get) => in_array('behavioral', IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    Forms\Components\CheckboxList::make('monitoring_config.enabled_metrics.performance')
                        ->label('Performance Metrics')
                        ->options([
                            'lcp'            => 'LCP (Largest Contentful Paint)',
                            'inp'            => 'INP (Interaction to Next Paint)',
                            'cls'            => 'CLS (Cumulative Layout Shift)',
                            'ttfb'           => 'TTFB (Time to First Byte)',
                            'page_load_time' => 'Page Load Time',
                            'bounce_rate'    => 'Bounce Rate',
                        ])
                        ->default(['lcp', 'inp', 'cls', 'ttfb', 'page_load_time', 'bounce_rate'])
                        ->columns(3)
                        ->visible(fn (Forms\Get $get) => in_array('performance', IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    Forms\Components\CheckboxList::make('monitoring_config.enabled_metrics.inventory')
                        ->label('Inventory Metrics')
                        ->options([
                            'out_of_stock_count' => 'Out of Stock Count',
                            'low_stock_count'    => 'Low Stock Count',
                            'out_of_stock_rate'  => 'Out of Stock Rate',
                            'inventory_turnover' => 'Inventory Turnover',
                        ])
                        ->default(['out_of_stock_count', 'low_stock_count', 'out_of_stock_rate', 'inventory_turnover'])
                        ->columns(2)
                        ->visible(fn (Forms\Get $get) => in_array('inventory', IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    Forms\Components\CheckboxList::make('monitoring_config.enabled_metrics.email_marketing')
                        ->label('Email Marketing Metrics')
                        ->options([
                            'open_rate'    => 'Open Rate',
                            'click_rate'   => 'Click Rate',
                            'conversions'  => 'Conversions',
                            'revenue'      => 'Revenue',
                            'unsubscribes' => 'Unsubscribes',
                            'bounces'      => 'Bounces',
                        ])
                        ->default(['open_rate', 'click_rate', 'conversions', 'revenue', 'unsubscribes', 'bounces'])
                        ->columns(3)
                        ->visible(fn (Forms\Get $get) => in_array('email_marketing', IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    Forms\Components\Select::make('monitoring_config.comparison_period_days')
                        ->label('Comparison Period')
                        ->options([
                            '7'  => '7 Days',
                            '14' => '14 Days',
                            '30' => '30 Days',
                            '60' => '60 Days',
                            '90' => '90 Days',
                        ])
                        ->default('7')
                        ->helperText('How far back the engine looks when comparing current vs previous metrics.'),
                ])
                ->visible(fn (Forms\Get $get) => $get('integration_type') !== null)
                ->collapsible()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('integration_type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state) => $state instanceof IntegrationType ? $state->label() : $state)
                    ->badge(),

                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state instanceof IntegrationStatus ? $state->label() : $state)
                    ->colors([
                        'success' => IntegrationStatus::Active->value,
                        'gray'    => IntegrationStatus::Inactive->value,
                        'danger'  => IntegrationStatus::Error->value,
                        'warning' => IntegrationStatus::Pending->value,
                    ]),

                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Last Sync')
                    ->dateTime()
                    ->since()
                    ->placeholder('Never'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (Integration $record): void {
                        // Sync enough data to cover current + prior comparison period
                        $days = $record->getComparisonPeriod() * 2;
                        TriggerIntegrationSync::dispatch($record, $days);
                        Notification::make()
                            ->title('Sync queued successfully')
                            ->body("Sync for {$record->integration_type?->label()} has been queued ({$days}-day window).")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
