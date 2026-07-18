<?php

namespace App\Filament\Resources;

use App\Enums\ClientStatus;
use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Client')
                ->tabs([

                    // ── Tab 1: Details ───────────────────────────────
                    Forms\Components\Tabs\Tab::make('Details')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Forms\Components\Section::make('Client Information')
                                ->schema([
                                    Forms\Components\TextInput::make('name')
                                        ->required()
                                        ->maxLength(255)
                                        ->columnSpan(2),

                                    Forms\Components\TextInput::make('industry')
                                        ->maxLength(255),

                                    Forms\Components\TextInput::make('platform_type')
                                        ->label('Primary Platform')
                                        ->maxLength(255)
                                        ->placeholder('Shopify, Adobe Commerce, etc.'),

                                    Forms\Components\TextInput::make('jira_project_key')
                                        ->label('Jira Project Key')
                                        ->placeholder('e.g. TECH')
                                        ->maxLength(50)
                                        ->nullable(),

                                    Forms\Components\Select::make('status')
                                        ->options(collect(ClientStatus::cases())->mapWithKeys(
                                            fn ($case) => [$case->value => $case->label()]
                                        ))
                                        ->default(ClientStatus::Active->value)
                                        ->required(),

                                    Forms\Components\Textarea::make('notes')
                                        ->rows(4)
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                        ]),

                    // ── Tab 2: Monitoring Profile ────────────────────
                    Forms\Components\Tabs\Tab::make('Monitoring Profile')
                        ->icon('heroicon-o-chart-bar')
                        ->schema([

                            // Business Context
                            Forms\Components\Textarea::make('business_context')
                                ->label('Business Context')
                                ->helperText('Describe this client\'s business model, key goals, seasonal patterns, and anything the AI analyst should know when generating recommendations.')
                                ->placeholder('e.g. B2B wholesale supplier. High AOV ($500+), low order volume (~20/day). Peak season is Q4. Primary concern: mobile cart abandonment.')
                                ->rows(4)
                                ->columnSpanFull(),

                            // Findings Comparison Period
                            Forms\Components\Select::make('findings_comparison_period')
                                ->label('Findings Comparison Period')
                                ->helperText('How the findings engine compares metrics: current N days vs prior N days.')
                                ->options([
                                    7  => 'Week over Week (7 days)',
                                    14 => 'Bi-weekly (14 days)',
                                    30 => 'Month over Month (30 days)',
                                ])
                                ->default(7)
                                ->required()
                                ->columnSpan(1),

                            // Threshold Overrides
                            Forms\Components\Repeater::make('monitoring_config.thresholds')
                                ->label('Alert Threshold Overrides')
                                ->helperText('Only add overrides here — metrics not listed use the global defaults (10% for revenue, 20% for behavioral).')
                                ->schema([
                                    Forms\Components\Select::make('metric')
                                        ->label('Metric')
                                        ->options([
                                            'revenue_decrease'            => 'Revenue Drop',
                                            'revenue_increase'            => 'Revenue Spike',
                                            'conversion_decrease'         => 'Conversion Rate Drop',
                                            'conversion_increase'         => 'Conversion Rate Spike',
                                            'aov_change'                  => 'AOV Change',
                                            'returning_customer_decrease' => 'Returning Customer Drop',
                                            'return_rate_decrease'        => 'Return Rate Drop',
                                            'rage_clicks_increase'        => 'Rage Clicks Spike',
                                            'dead_clicks_increase'        => 'Dead Clicks Spike',
                                            'quickbacks_increase'         => 'Quick Backs Spike',
                                            'script_errors_increase'      => 'Script Errors Spike',
                                            'error_clicks_increase'       => 'Error Clicks Spike',
                                            'friction_score_increase'     => 'Friction Score Spike',
                                            'lcp_increase'                => 'LCP Degradation',
                                            'cls_increase'                => 'CLS Degradation',
                                            'page_load_increase'          => 'Page Load Degradation',
                                            'bounce_rate_increase'        => 'Bounce Rate Spike',
                                            'out_of_stock_increase'       => 'Out of Stock Spike',
                                            'low_stock_increase'          => 'Low Stock Spike',
                                        ])
                                        ->required(),

                                    Forms\Components\TextInput::make('value')
                                        ->label('Threshold %')
                                        ->numeric()
                                        ->suffix('%')
                                        ->helperText('e.g. 15 means alert at 15% change')
                                        ->dehydrateStateUsing(fn ($state) => $state !== null ? round((float) $state / 100, 4) : null)
                                        ->afterStateHydrated(fn (Forms\Components\TextInput $component, $state) => $component->state($state !== null ? round((float) $state * 100) : null))
                                        ->required(),

                                    Forms\Components\Select::make('severity')
                                        ->label('Override Severity (optional)')
                                        ->options([
                                            'critical' => 'Critical',
                                            'high'     => 'High',
                                            'medium'   => 'Medium',
                                            'low'      => 'Low',
                                        ]),
                                ])
                                ->columns(3)
                                ->defaultItems(0)
                                ->addActionLabel('Add Threshold Override')
                                ->collapsible()
                                ->cloneable(),
                        ]),

                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('industry')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('platform_type')
                    ->label('Platform')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state instanceof ClientStatus ? $state->label() : $state)
                    ->colors([
                        'success' => ClientStatus::Active->value,
                        'gray'    => ClientStatus::Inactive->value,
                        'warning' => ClientStatus::Onboarding->value,
                        'info'    => ClientStatus::Paused->value,
                    ]),

                Tables\Columns\TextColumn::make('open_findings_count')
                    ->label('Open Findings')
                    ->counts('openFindings')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('integrations_count')
                    ->label('Integrations')
                    ->counts('integrations')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(ClientStatus::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelationManagers(): array
    {
        return [
            RelationManagers\IntegrationsRelationManager::class,
            RelationManagers\FindingsRelationManager::class,
            RelationManagers\DeploymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'view'   => Pages\ViewClient::route('/{record}'),
            'edit'   => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
