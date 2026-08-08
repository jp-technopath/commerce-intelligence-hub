<?php

namespace App\Filament\Widgets\Customer;

use App\Models\ClientMeeting;
use App\Models\ForgeApprovalEvent;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AccountHistoryWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Account History (360-Day Activity View)';

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        return $table
            ->query(
                PmWorklog::query()
                    ->where('client_id', $clientId)
                    ->selectRaw("
                        MIN(id) as id,
                        DATE_FORMAT(worklog_started_at, '%Y-%m-01') as month_key,
                        DATE_FORMAT(worklog_started_at, '%M %Y') as month_name,
                        ROUND(SUM(time_spent_seconds) / 3600, 1) as hours_consumed
                    ")
                    ->groupByRaw("DATE_FORMAT(worklog_started_at, '%Y-%m-01'), DATE_FORMAT(worklog_started_at, '%M %Y')")
                    ->orderByRaw("DATE_FORMAT(worklog_started_at, '%Y-%m-01') DESC")
            )
            ->columns([
                Tables\Columns\TextColumn::make('month_name')
                    ->label('Month')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('hours_consumed')
                    ->label('Hours Consumed')
                    ->suffix(' hrs'),
            ]);
    }
}
