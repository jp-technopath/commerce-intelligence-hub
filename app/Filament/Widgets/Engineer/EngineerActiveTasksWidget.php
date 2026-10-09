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

    public ?int $userId = null;

    protected $listeners = [
        'engineer-user-changed' => 'handleEngineerUserChanged',
    ];

    public function handleEngineerUserChanged(?int $userId = null): void
    {
        $this->userId = $userId;
    }

    public function getTargetUser(): ?\App\Models\User
    {
        $id = $this->userId ?? session('engineer_dashboard_user_id') ?? Auth::id();

        if (! $id) {
            return Auth::user();
        }

        return \App\Models\User::find($id) ?? Auth::user();
    }

    public function table(Table $table): Table
    {
        /** @var \App\Models\User|null $user */
        $user = $this->getTargetUser();

        return $table
            ->heading($user && $user->id !== Auth::id() ? "{$user->name}'s Assigned Tasks (All Active)" : 'My Assigned Tasks (All Active)')
            ->query(
                PmWorkItem::query()
                    ->with(['client', 'project', 'pmConnection'])
                    ->when($user, function ($q) use ($user) {
                        $q->where(function ($sub) use ($user) {
                            $sub->where('user_id', $user->id)
                                ->orWhere('assignee_name', $user->name);
                        });
                    }, fn ($q) => $q->whereRaw('1 = 0'))
                    ->excludeBacklogAndOnHold()
                    ->whereNotIn('normalized_delivery_status', ['completed', 'cancelled', 'canceled'])
                    ->orderByDesc('is_blocked')
                    ->orderBy('target_due_date', 'asc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('external_item_key')
                    ->label('Key')
                    ->weight('bold')
                    ->searchable()
                    ->copyable()
                    ->url(fn (PmWorkItem $record): ?string => $record->jira_url)
                    ->openUrlInNewTab()
                    ->color('primary'),

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
                    ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                        'critical', 'highest' => 'danger',
                        'high'                => 'warning',
                        'medium'              => 'info',
                        default               => 'gray',
                    }),

                Tables\Columns\TextColumn::make('normalized_delivery_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ((string) $state) {
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
                Action::make('jira')
                    ->label('Open in Jira')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (PmWorkItem $record): ?string => $record->jira_url)
                    ->openUrlInNewTab(),

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
            ])
;
    }
}
