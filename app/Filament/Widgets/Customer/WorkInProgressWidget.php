<?php

namespace App\Filament\Widgets\Customer;

use App\Models\PmWorkItem;
use Filament\Forms\Components\Placeholder;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class WorkInProgressWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Work in Progress (Jira Status = In Progress)';

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        return $table
            ->query(
                PmWorkItem::query()
                    ->where('client_id', $clientId)
                    ->where(function ($q) {
                        $q->where('normalized_delivery_status', 'in_progress')
                          ->orWhereRaw('LOWER(external_status) LIKE ?', ['%in progress%']);
                    })
                    ->whereHas('project', function ($q) {
                        $q->where('external_project_key', '!=', 'SUP');
                    })
                    ->where('external_item_key', 'NOT LIKE', 'SUP-%')
                    ->orderBy('updated_at', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('external_item_key')
                    ->label('Key')
                    ->weight('bold')
                    ->searchable()
                    ->url(fn (PmWorkItem $record): string => "https://technopath.atlassian.net/browse/{$record->external_item_key}")
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('summary')
                    ->label('Task / Story Name')
                    ->weight('bold')
                    ->searchable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('item_type')
                    ->label('Type')
                    ->badge(),

                Tables\Columns\TextColumn::make('normalized_delivery_status')
                    ->label('Delivery Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed'       => 'success',
                        'customer_review' => 'warning',
                        'review_qa'       => 'info',
                        'in_progress'     => 'primary',
                        'ready'           => 'gray',
                        default           => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'planned'         => 'Planned',
                        'ready'           => 'Ready for Dev',
                        'in_progress'     => 'In Progress',
                        'review_qa'       => 'Review / QA',
                        'customer_review' => 'Customer Review',
                        'completed'       => 'Completed',
                        default           => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('estimate_approval_status')
                    ->label('Estimate Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved'            => 'success',
                        'pending_approval'    => 'warning',
                        'reapproval_required' => 'danger',
                        'revision_requested' => 'info',
                        default               => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                Tables\Columns\TextColumn::make('estimated_hours')
                    ->label('Approved Hrs')
                    ->suffix(' hrs'),

                Tables\Columns\TextColumn::make('assignee_name')
                    ->label('Assignee')
                    ->default('Unassigned'),

                Tables\Columns\TextColumn::make('target_due_date')
                    ->label('Target Date')
                    ->date('M d, Y')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Action::make('view_details')
                    ->label('Details')
                    ->icon('heroicon-m-information-circle')
                    ->form(function (PmWorkItem $record) {
                        $user = Auth::user();
                        $canViewTaskHours = $user?->hasPermission('hours.view_task_totals') ?? false;

                        $latestVersion = $record->latestEstimateVersion;
                        $approvedHours = $latestVersion ? $latestVersion->estimated_hours : $record->estimated_hours;

                        $fields = [
                            Placeholder::make('task_title')
                                ->label('Task')
                                ->content("{$record->external_item_key}: {$record->summary}"),

                            Placeholder::make('description')
                                ->label('Business Scope / Description')
                                ->content($record->description ?: 'No detailed description available.'),

                            Placeholder::make('delivery_status')
                                ->label('Delivery Status')
                                ->content($record->delivery_status_label),

                            Placeholder::make('estimate_status')
                                ->label('Estimate Approval Status')
                                ->content(ucfirst(str_replace('_', ' ', $record->estimate_approval_status)) . " ({$approvedHours} hrs)"),
                        ];

                        if ($canViewTaskHours) {
                            $fields[] = Placeholder::make('logged_hours')
                                ->label('Logged Hours (Task Total)')
                                ->content("{$record->time_spent_hours} hrs logged");
                        }

                        return $fields;
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }
}
