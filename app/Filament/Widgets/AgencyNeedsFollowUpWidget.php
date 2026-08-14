<?php

namespace App\Filament\Widgets;

use App\Models\CustomerAttentionItem;
use App\Models\PmWorkItem;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class AgencyNeedsFollowUpWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Needs Internal Follow-Up & Attention';

    public static function canView(): bool
    {
        $user = Auth::user();
        if ($user?->isClientOnly()) {
            return false;
        }

        return PmWorkItem::where('is_blocked', true)
            ->orWhere('normalized_delivery_status', 'review_qa')
            ->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->query(
                PmWorkItem::query()
                    ->where(function ($q) {
                        $q->where('is_blocked', true)
                          ->orWhere('normalized_delivery_status', 'review_qa');
                    })
                    ->where('normalized_delivery_status', '!=', 'completed')
                    ->orderBy('updated_at', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('external_item_key')
                    ->label('Key')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('summary')
                    ->label('Task')
                    ->weight('bold')
                    ->limit(50),

                Tables\Columns\TextColumn::make('normalized_delivery_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state, PmWorkItem $record): string => $record->is_blocked ? 'danger' : 'info')
                    ->formatStateUsing(fn (string $state, PmWorkItem $record): string => $record->is_blocked ? 'Blocked' : 'Internal QA / Review'),

                Tables\Columns\TextColumn::make('assignee_name')
                    ->label('Assignee')
                    ->default('Unassigned'),

                Tables\Columns\TextColumn::make('blocked_reason')
                    ->label('Attention Reason')
                    ->placeholder('Internal Review / Verification Required')
                    ->limit(60),
            ]);
    }
}
