<?php

namespace App\Filament\Widgets\Engineer;

use App\Models\PmWorkItem;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class EngineerActiveTasksWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'My Assigned Tasks (All Active)';

    public function table(Table $table): Table
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        return $table
            ->query(
                PmWorkItem::query()
                    ->with(['client', 'project'])
                    ->where(function ($q) use ($user) {
                        $q->where('user_id', $user?->id)
                          ->orWhere('assignee_name', $user?->name);
                    })
                    ->whereNotIn('normalized_delivery_status', ['completed', 'cancelled'])
                    ->orderByDesc('is_blocked')
                    ->orderBy('target_due_date', 'asc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('external_item_key')
                    ->label('Key')
                    ->weight('bold')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Customer')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('summary')
                    ->label('Summary')
                    ->weight('medium')
                    ->limit(55)
                    ->searchable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Priority')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'critical', 'highest' => 'danger',
                        'high'                => 'warning',
                        'medium'              => 'info',
                        default               => 'gray',
                    }),

                Tables\Columns\TextColumn::make('normalized_delivery_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'in_progress'     => 'primary',
                        'review_qa'       => 'warning',
                        'customer_review' => 'info',
                        'blocked'         => 'danger',
                        'ready'           => 'success',
                        default           => 'gray',
                    })
                    ->formatStateUsing(fn (PmWorkItem $record): string => $record->delivery_status_label),

                Tables\Columns\IconColumn::make('is_blocked')
                    ->label('Blocked')
                    ->boolean()
                    ->trueIcon('heroicon-s-no-symbol')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('target_due_date')
                    ->label('Due Date')
                    ->date('M j, Y')
                    ->color(fn (PmWorkItem $record): ?string => $record->target_due_date && $record->target_due_date->isPast() ? 'danger' : null),

                Tables\Columns\TextColumn::make('estimated_hours')
                    ->label('Est (h)')
                    ->suffix('h')
                    ->alignRight(),

                Tables\Columns\TextColumn::make('time_spent_hours')
                    ->label('Logged (h)')
                    ->suffix('h')
                    ->alignRight(),
            ])
            ->actions([
                Action::make('start')
                    ->label('Start')
                    ->icon('heroicon-m-play')
                    ->color('primary')
                    ->visible(fn (PmWorkItem $record): bool => $record->normalized_delivery_status !== 'in_progress' && ! $record->is_blocked)
                    ->action(function (PmWorkItem $record) {
                        $record->update([
                            'normalized_delivery_status' => 'in_progress',
                            'external_status'            => 'In Progress',
                        ]);
                        Notification::make()
                            ->title('Status Updated')
                            ->body("[{$record->external_item_key}] is now In Progress.")
                            ->success()
                            ->send();
                    }),

                Action::make('submit_qa')
                    ->label('Submit QA')
                    ->icon('heroicon-m-arrow-right-circle')
                    ->color('warning')
                    ->visible(fn (PmWorkItem $record): bool => $record->normalized_delivery_status === 'in_progress')
                    ->action(function (PmWorkItem $record) {
                        $record->update([
                            'normalized_delivery_status' => 'review_qa',
                            'external_status'            => 'Review / QA',
                        ]);
                        Notification::make()
                            ->title('Moved to QA')
                            ->body("[{$record->external_item_key}] submitted for QA verification.")
                            ->success()
                            ->send();
                    }),

                Action::make('log_hours')
                    ->label('Log Time')
                    ->icon('heroicon-m-clock')
                    ->color('gray')
                    ->form([
                        TextInput::make('hours')
                            ->label('Hours Spent')
                            ->numeric()
                            ->required()
                            ->default(1.0)
                            ->minValue(0.25)
                            ->maxValue(24.0),
                    ])
                    ->action(function (PmWorkItem $record, array $data) {
                        $hours = (float) $data['hours'];
                        $seconds = (int) ($hours * 3600);

                        \App\Models\PmWorklog::create([
                            'client_id'           => $record->client_id,
                            'user_id'             => Auth::id(),
                            'pm_connection_id'    => $record->pm_connection_id,
                            'pm_work_item_id'     => $record->id,
                            'external_worklog_id' => 'local_' . uniqid(),
                            'author_name'         => Auth::user()->name,
                            'time_spent_seconds'  => $seconds,
                            'worklog_started_at'  => now(),
                            'last_synced_at'      => now(),
                        ]);

                        $record->increment('time_spent_seconds', $seconds);

                        Notification::make()
                            ->title('Time Logged')
                            ->body("Logged {$hours}h to [{$record->external_item_key}].")
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
