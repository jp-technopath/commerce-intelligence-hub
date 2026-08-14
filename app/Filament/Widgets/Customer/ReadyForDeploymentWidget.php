<?php

namespace App\Filament\Widgets\Customer;

use App\Models\PmWorkItem;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class ReadyForDeploymentWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Ready for Deployment / Scheduled Releases';

    public static function canView(): bool
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        return PmWorkItem::where('client_id', $clientId)
            ->where('normalized_delivery_status', 'ready_for_deployment')
            ->exists();
    }

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        return $table
            ->paginated(false)
            ->query(
                PmWorkItem::query()
                    ->where('client_id', $clientId)
                    ->where('normalized_delivery_status', 'ready_for_deployment')
                    ->orderBy('updated_at', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('external_item_key')
                    ->label('Key')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('summary')
                    ->label('Task Summary')
                    ->weight('bold')
                    ->limit(60),

                Tables\Columns\TextColumn::make('normalized_delivery_status')
                    ->label('Release Status')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn () => 'Ready for Deployment'),

                Tables\Columns\TextColumn::make('assignee_name')
                    ->label('Lead Engineer')
                    ->default('Technopath Release Team'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Staged Date')
                    ->dateTime('M j, Y g:i A'),
            ]);
    }
}
