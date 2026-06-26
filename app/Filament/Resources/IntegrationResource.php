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
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (Integration $record): void {
                        // First sync gets 30 days of historical data
                        $days = $record->last_sync_at ? 1 : 30;
                        TriggerIntegrationSync::dispatch($record, $days);
                        Notification::make()
                            ->title('Sync queued')
                            ->body("Sync for {$record->integration_type?->label()} queued" . ($days > 1 ? " (30-day backfill)" : "") . ".")
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
