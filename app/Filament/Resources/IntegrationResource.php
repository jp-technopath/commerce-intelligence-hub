<?php

namespace App\Filament\Resources;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Jobs\TriggerIntegrationSync;
use App\Filament\Resources\IntegrationResource\Pages;
use App\Models\Integration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IntegrationResource extends Resource
{
    protected static ?string $model = Integration::class;
    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static ?string $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Integration Details')
                ->schema([
                    Forms\Components\Select::make('client_id')
                        ->relationship('client', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

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
                ])
                ->columns(2),

            // ── Shopify ──────────────────────────────────────────────────────
            Forms\Components\Section::make('Credentials')
                ->description('All credentials are stored encrypted at rest and never appear in logs or sync output.')
                ->schema([
                    Forms\Components\TextInput::make('shopify_access_token')
                        ->label('Access Token')
                        ->helperText('Custom App Access Token from your Shopify Partner dashboard.')
                        ->password()
                        ->revealable()
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'shopify'),

                    Forms\Components\TextInput::make('shopify_shop_domain')
                        ->label('Shop Domain')
                        ->helperText('e.g. your-store.myshopify.com')
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'shopify'),

                    // ── Adobe Commerce (Admin REST API) ──────────────────────
                    Forms\Components\TextInput::make('adobe_base_url')
                        ->label('Store Base URL')
                        ->helperText('e.g. https://your-store.com (no trailing slash)')
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'adobe_commerce'),

                    Forms\Components\TextInput::make('adobe_admin_username')
                        ->label('Admin Username')
                        ->helperText('Magento admin user with REST API access')
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'adobe_commerce'),

                    Forms\Components\TextInput::make('adobe_admin_password')
                        ->label('Admin Password')
                        ->password()
                        ->revealable()
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'adobe_commerce'),

                    // ── GA4 — OAuth2 flow ─────────────────────────────────────
                    // Step 1: Property ID (always needed)
                    Forms\Components\TextInput::make('ga4_property_id')
                        ->label('GA4 Property ID')
                        ->helperText('Found in GA4 → Admin → Property Details. e.g. 123456789')
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'ga4')
                        ->columnSpanFull(),

                    // Step 2: OAuth status display (read-only, shown on edit)
                    Forms\Components\Placeholder::make('ga4_auth_status')
                        ->label('Google Account Authorization')
                        ->content(function (?Integration $record): \Illuminate\Support\HtmlString {
                            if (! $record) {
                                return new \Illuminate\Support\HtmlString(
                                    '<p class="text-sm text-gray-500">Save this integration first, then authorize your Google account.</p>'
                                );
                            }

                            $creds = $record->credentials_json ?? [];
                            $authorized = ! empty($creds['refresh_token']);

                            if ($authorized) {
                                $email = $creds['authorized_email'] ?? 'Unknown';
                                $date  = isset($creds['authorized_at'])
                                    ? \Carbon\Carbon::parse($creds['authorized_at'])->diffForHumans()
                                    : '';

                                return new \Illuminate\Support\HtmlString(
                                    '<div class="flex items-center gap-3">'
                                    . '<span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800">'
                                    . '<svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414L8.414 15l-4.121-4.121a1 1 0 011.414-1.414L8.414 12.172l7.879-7.879a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>'
                                    . 'Connected'
                                    . '</span>'
                                    . '<span class="text-sm text-gray-600">Authorized as <strong>' . e($email) . '</strong>' . ($date ? " &mdash; {$date}" : '') . '</span>'
                                    . '</div>'
                                );
                            }

                            return new \Illuminate\Support\HtmlString(
                                '<div class="flex items-center gap-3">'
                                . '<span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-800">'
                                . '<svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>'
                                . 'Not authorized'
                                . '</span>'
                                . '<span class="text-sm text-gray-500">Click the button below to authorize your Google account.</span>'
                                . '</div>'
                            );
                        })
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'ga4')
                        ->columnSpanFull(),

                    // Step 3: Authorize / Disconnect button (shown on edit only)
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('authorize_google')
                            ->label('Authorize Google Account')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->color('primary')
                            ->url(fn (?Integration $record) => $record
                                ? route('google.oauth.redirect', $record)
                                : null
                            )
                            ->openUrlInNewTab(false)
                            ->visible(fn (?Integration $record, Forms\Get $get) =>
                                $get('integration_type') === 'ga4'
                                && $record !== null
                                && empty($record->credentials_json['refresh_token'])
                            ),

                        Forms\Components\Actions\Action::make('disconnect_google')
                            ->label('Disconnect Google Account')
                            ->icon('heroicon-o-x-circle')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalHeading('Disconnect Google Account')
                            ->modalDescription('This will revoke the OAuth token and set the integration back to Pending. You will need to re-authorize to resume syncing.')
                            ->url(fn (?Integration $record) => $record
                                ? route('google.oauth.revoke', $record)
                                : null
                            )
                            ->visible(fn (?Integration $record, Forms\Get $get) =>
                                $get('integration_type') === 'ga4'
                                && $record !== null
                                && ! empty($record->credentials_json['refresh_token'])
                            ),
                    ])
                    ->visible(fn (Forms\Get $get) => $get('integration_type') === 'ga4')
                    ->columnSpanFull(),

                    // ── Clarity ───────────────────────────────────────────────
                    Forms\Components\TextInput::make('clarity_bearer_token')
                        ->label('Bearer Token')
                        ->helperText('Generated from Clarity project settings → API access.')
                        ->password()
                        ->revealable()
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'clarity'),

                    Forms\Components\TextInput::make('clarity_project_id')
                        ->label('Clarity Project ID')
                        ->helperText('Found in your Clarity project URL.')
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'clarity'),

                    // ── New Relic ─────────────────────────────────────────────
                    Forms\Components\TextInput::make('newrelic_api_key')
                        ->label('API Key')
                        ->helperText('New Relic User API key. Found in New Relic → API Keys → User type.')
                        ->password()
                        ->revealable()
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'new_relic'),

                    Forms\Components\TextInput::make('newrelic_application_id')
                        ->label('Application ID')
                        ->helperText('Found in New Relic → APM → your app → Settings. The numeric ID in the URL.')
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'new_relic'),

                    // ── Klaviyo ───────────────────────────────────────────────
                    Forms\Components\TextInput::make('klaviyo_api_key')
                        ->label('Private API Key')
                        ->helperText('Found in Klaviyo → Settings → API Keys. Use a Private API key (starts with "pk_").')
                        ->password()
                        ->revealable()
                        ->visible(fn (Forms\Get $get) => $get('integration_type') === 'klaviyo'),

                    // ── Raw JSON (hidden for GA4 — OAuth handles it) ──────────
                    Forms\Components\Textarea::make('credentials_json')
                        ->label('Raw Credentials JSON (Advanced)')
                        ->helperText('Stored encrypted at rest. For GA4 use the Authorize button above.')
                        ->rows(3)
                        ->columnSpanFull()
                        ->hidden(fn (Forms\Get $get) => in_array($get('integration_type'), ['ga4', 'new_relic', 'klaviyo']))
                        ->dehydrateStateUsing(function ($state) {
                            if (is_array($state)) {
                                return $state;
                            }
                            if (is_string($state) && str_starts_with(trim((string) $state), '{')) {
                                $decoded = json_decode($state, true);
                                return is_array($decoded) ? $decoded : null;
                            }
                            return null;
                        }),
                ]),

            Forms\Components\Section::make('Settings')
                ->schema([
                    Forms\Components\KeyValue::make('settings_json')
                        ->label('Configuration')
                        ->columnSpanFull(),
                ])
                ->collapsed(),

            // ── Metrics to Monitor ──────────────────────────────────────────
            Forms\Components\Section::make('Metrics to Monitor')
                ->description('Select which metrics this integration should monitor. Only categories applicable to the selected integration type are shown.')
                ->schema([
                    // Commerce Metrics (Shopify, Adobe Commerce, GA4)
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
                        ->visible(fn (Forms\Get $get) => in_array('commerce', \App\Enums\IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    // Behavioral Metrics (Clarity)
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
                        ->visible(fn (Forms\Get $get) => in_array('behavioral', \App\Enums\IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    // Performance Metrics (GA4, New Relic)
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
                        ->helperText('Core Web Vitals and page speed metrics.')
                        ->visible(fn (Forms\Get $get) => in_array('performance', \App\Enums\IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    // Inventory Metrics (Shopify, Adobe Commerce)
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
                        ->visible(fn (Forms\Get $get) => in_array('inventory', \App\Enums\IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    // Email Marketing Metrics (Klaviyo)
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
                        ->visible(fn (Forms\Get $get) => in_array('email_marketing', \App\Enums\IntegrationType::tryFrom($get('integration_type'))?->metricCategories() ?? [])),

                    // Comparison Period
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
                        ->helperText('How far back the engine looks when comparing current vs previous metrics for this integration.'),
                ])
                ->visible(fn (Forms\Get $get) => $get('integration_type') !== null)
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('integration_type')
                    ->formatStateUsing(fn ($state) => $state instanceof IntegrationType ? $state->label() : $state),

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
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('integration_type')
                    ->options(collect(IntegrationType::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(IntegrationStatus::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
            ])
            ->headerActions([
                Tables\Actions\Action::make('sync_all')
                    ->label('Sync All Integrations')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sync All Integrations')
                    ->modalDescription('This will queue a sync job for every active integration. Are you sure?')
                    ->action(function (): void {
                        $integrations = Integration::where('status', IntegrationStatus::Active->value)->get();
                        $count = 0;

                        foreach ($integrations as $integration) {
                            // Sync enough data to cover current + prior comparison period
                            $days = $integration->getComparisonPeriod() * 2;
                            TriggerIntegrationSync::dispatch($integration, $days);
                            $count++;
                        }

                        Notification::make()
                            ->title('All syncs queued')
                            ->body("{$count} integration sync(s) have been queued.")
                            ->success()
                            ->send();
                    }),
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
                            ->title('Sync queued')
                            ->body("Sync for {$record->integration_type?->label()} queued ({$days}-day window).")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListIntegrations::route('/'),
            'create' => Pages\CreateIntegration::route('/create'),
            'edit'   => Pages\EditIntegration::route('/{record}/edit'),
        ];
    }
}
