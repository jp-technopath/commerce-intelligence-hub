<?php

namespace App\Filament\Widgets\Manager;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Finding;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class ManagerActionCenterWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Action Center (Operational Bottlenecks & Findings)';

    public function table(Table $table): Table
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $clientIds = $user ? $user->getAssignedClientIds() : [];

        $operationalCategories = [
            FindingCategory::WorkPriority->value,
            FindingCategory::WorkflowDelay->value,
            FindingCategory::ResponseTime->value,
            FindingCategory::ProjectRisk->value,
            FindingCategory::TimeTracking->value,
            FindingCategory::MeetingPreparation->value,
            FindingCategory::MeetingFollowUp->value,
        ];

        return $table
            ->query(
                Finding::query()
                    ->with(['client', 'responsibleUser'])
                    ->whereIn('finding_category', $operationalCategories)
                    ->whereNotIn('status', [FindingStatus::Resolved->value, FindingStatus::Dismissed->value])
                    ->where(function ($q) {
                        $q->whereNull('snoozed_until')
                          ->orWhere('snoozed_until', '<=', now());
                    })
                    ->when(! empty(array_diff($clientIds, ['*'])), fn ($q) => $q->whereIn('client_id', array_diff($clientIds, ['*'])))
                    ->orderByRaw("CASE WHEN severity = 'critical' THEN 1 WHEN severity = 'high' THEN 2 WHEN severity = 'medium' THEN 3 ELSE 4 END")
                    ->latest('detected_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('finding_category')
                    ->label('Category')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof FindingCategory ? $state->label() : ucfirst(str_replace('_', ' ', (string) $state)))
                    ->color(fn ($state) => match ($state instanceof FindingCategory ? $state->value : (string) $state) {
                        'workflow_delay', 'project_risk' => 'danger',
                        'time_tracking'                  => 'warning',
                        'meeting_preparation'            => 'info',
                        default                          => 'gray',
                    }),

                Tables\Columns\TextColumn::make('title')
                    ->label('Finding Title')
                    ->weight('semibold')
                    ->limit(55)
                    ->searchable(),

                Tables\Columns\TextColumn::make('severity')
                    ->label('Severity')
                    ->badge()
                    ->color(fn ($state) => match ($state instanceof FindingSeverity ? $state->value : (string) $state) {
                        'critical', 'high' => 'danger',
                        'medium'           => 'warning',
                        default            => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof FindingStatus ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state) => match ($state instanceof FindingStatus ? $state->value : (string) $state) {
                        'new'          => 'danger',
                        'acknowledged' => 'info',
                        'snoozed'      => 'gray',
                        default        => 'success',
                    }),

                Tables\Columns\TextColumn::make('responsibleUser.name')
                    ->label('Owner')
                    ->placeholder('Unassigned'),

                Tables\Columns\TextColumn::make('detected_at')
                    ->label('Detected')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Action::make('acknowledge')
                    ->label('Acknowledge')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->visible(fn (Finding $record): bool => $record->status === FindingStatus::New)
                    ->action(function (Finding $record) {
                        $record->acknowledge();
                        Notification::make()
                            ->title('Finding Acknowledged')
                            ->body('Finding moved to Acknowledged status.')
                            ->success()
                            ->send();
                    }),

                Action::make('snooze')
                    ->label('Snooze')
                    ->icon('heroicon-m-clock')
                    ->color('gray')
                    ->form([
                        Select::make('days')
                            ->label('Snooze Duration')
                            ->options([
                                1 => '1 Day',
                                3 => '3 Days',
                                7 => '1 Week',
                            ])
                            ->default(1)
                            ->required(),
                    ])
                    ->action(function (Finding $record, array $data) {
                        $days = (int) $data['days'];
                        $record->snooze(now()->addDays($days));
                        Notification::make()
                            ->title('Finding Snoozed')
                            ->body("Finding hidden for {$days} day(s).")
                            ->success()
                            ->send();
                    }),

                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->action(function (Finding $record) {
                        $record->resolve();
                        Notification::make()
                            ->title('Finding Resolved')
                            ->body('Finding marked as resolved.')
                            ->success()
                            ->send();
                    }),

                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->form([
                        Textarea::make('reason')
                            ->label('Dismissal Rationale')
                            ->placeholder('e.g., False positive, accepted risk, or handled out-of-band')
                            ->required(),
                    ])
                    ->action(function (Finding $record, array $data) {
                        $record->dismiss($data['reason']);
                        Notification::make()
                            ->title('Finding Dismissed')
                            ->body('Finding dismissed from active action items.')
                            ->warning()
                            ->send();
                    }),
            ]);
    }
}
