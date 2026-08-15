<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasScopedClientFilter;
use App\Models\Client;
use App\Models\PmWorkItem;
use App\Services\PM\Providers\JiraProvider;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class WorkInProgress extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use HasScopedClientFilter;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Work in Pipeline';

    protected static ?string $title = 'Work in Pipeline';

    protected static ?string $slug = 'work-in-pipeline';

    protected static ?string $navigationGroup = 'Customer Portal';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.work-in-progress';

    public ?int $selected_client_id = null;

    protected $listeners = ['client-changed' => '$refresh'];

    public function mount(): void
    {
        $this->selected_client_id = $this->resolveScopedClientId();

        $this->form->fill([
            'selected_client_id' => $this->selected_client_id,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selected_client_id')
                    ->label('Selected Client')
                    ->options($this->scopedClientsQuery()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        // Ignore any attempt (tampered request, stale option, etc.)
                        // to select a client outside this user's scope.
                        if (! $this->isClientIdInScope((int) $state)) {
                            $this->selected_client_id = session('current_client_id');

                            return;
                        }

                        session(['current_client_id' => (int) $state]);
                        $this->dispatch('client-changed');
                    }),
            ]);
    }

    public function table(Table $table): Table
    {
        // Defense in depth: even if session('current_client_id') were ever
        // tampered with, fall back to a client within the current user's scope.
        $clientId = $this->isClientIdInScope(session('current_client_id'))
            ? (int) session('current_client_id')
            : $this->defaultScopedClientId();

        return $table
            ->query(
                PmWorkItem::query()
                    ->where('client_id', $clientId)
                    ->where('normalized_delivery_status', '!=', 'completed')
                    ->where('normalized_delivery_status', '!=', 'backlog')
                    ->whereRaw('UPPER(external_status) NOT LIKE ?', ['%BACKLOG%'])
                    ->whereHas('project', function ($q) {
                        $q->where('external_project_key', '!=', 'SUP');
                    })
                    ->where('external_item_key', 'NOT LIKE', 'SUP-%')
                    ->whereIn('id', function ($sub) use ($clientId) {
                        $sub->select(\Illuminate\Support\Facades\DB::raw('MAX(id)'))
                            ->from('pm_work_items')
                            ->where('client_id', $clientId)
                            ->groupBy('external_item_key');
                    })
                    ->orderBy('updated_at', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('external_item_key')
                    ->label('Key')
                    ->weight('bold')
                    ->searchable()
                    ->url(fn (PmWorkItem $record): string => "https://technopath.atlassian.net/servicedesk/customer/portal/4/{$record->external_item_key}")
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('summary')
                    ->label('Task / Story Name')
                    ->weight('bold')
                    ->searchable()
                    ->limit(60),

                Tables\Columns\TextColumn::make('item_type')
                    ->label('Type')
                    ->badge(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Priority')
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower($state ?? '')) {
                        'highest', 'high', 'urgent', 'critical' => 'danger',
                        'medium'                                 => 'warning',
                        'low', 'lowest'                         => 'gray',
                        default                                  => 'info',
                    })
                    ->default('Medium'),

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

                Tables\Columns\TextColumn::make('external_status')
                    ->label('Jira Status (Raw)')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('target_due_date')
                    ->label('Target Date')
                    ->date('M d, Y')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('view_scope')
                    ->label('View Scope')
                    ->options([
                        'all_pipeline'    => 'Work in Pipeline (All Active Tasks)',
                        'in_progress'     => 'Work in Progress (Jira status = In Progress)',
                        'planned_ready'   => 'Planned / Ready for Dev',
                        'review_qa'       => 'Review / QA & Testing',
                        'customer_review' => 'Customer Review',
                    ])
                    ->default(fn () => request()->query('scope') ?? 'all_pipeline')
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? 'all_pipeline';

                        return match ($value) {
                            'in_progress' => $query->where(function ($q) {
                                $q->where('normalized_delivery_status', 'in_progress')
                                  ->orWhereRaw('LOWER(external_status) LIKE ?', ['%in progress%']);
                            }),
                            'review_qa' => $query->whereIn('normalized_delivery_status', ['review_qa', 'customer_review']),
                            'customer_review' => $query->where('normalized_delivery_status', 'customer_review'),
                            'planned_ready' => $query->whereIn('normalized_delivery_status', ['planned', 'ready']),
                            'all_pipeline' => $query,
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Action::make('view_details')
                    ->label('Details')
                    ->icon('heroicon-m-information-circle')
                    ->modalWidth(\Filament\Support\Enums\MaxWidth::SixExtraLarge)
                    ->form(function (PmWorkItem $record) {
                        $user = Auth::user();
                        $canViewTaskHours = $user?->hasPermission('hours.view_task_totals') ?? false;

                        $latestVersion = $record->latestEstimateVersion;
                        $approvedHours = $latestVersion ? $latestVersion->estimated_hours : $record->estimated_hours;

                        $descriptionText = $record->description;

                        if ($record->connection) {
                            try {
                                $jiraProvider = app(JiraProvider::class);
                                $issue = $jiraProvider->getWorkItem($record->connection, $record->external_item_key);
                                $descAdf = $issue['fields']['description'] ?? null;
                                $parsedDesc = JiraProvider::parseAdfToMarkdown($descAdf);
                                if ($parsedDesc) {
                                    $descriptionText = $parsedDesc;
                                    $record->update(['description' => $parsedDesc]);
                                }
                            } catch (\Throwable $e) {
                                // Fallback silently
                            }
                        }

                        $statusColors = match ($record->normalized_delivery_status) {
                            'completed'       => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
                            'customer_review' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
                            'review_qa'       => 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800',
                            'in_progress'     => 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800',
                            default           => 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700',
                        };

                        $priorityColors = match (strtolower($record->priority ?? 'medium')) {
                            'highest', 'high', 'urgent', 'critical' => 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-800',
                            'medium'                                 => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
                            default                                  => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
                        };

                        $hoursHtml = $canViewTaskHours ? "
                            <div class='hidden sm:block h-4 w-px bg-gray-200 dark:bg-gray-700'></div>
                            <div class='flex items-center gap-2'>
                                <span class='font-bold text-gray-400 uppercase tracking-widest text-[10px]'>Logged Hours</span>
                                <span class='font-extrabold text-gray-900 dark:text-gray-100 text-xs flex items-center gap-1'>
                                    ⏱️ {$record->time_spent_hours} hrs logged
                                </span>
                            </div>
                        " : "";

                        $cardHtml = new \Illuminate\Support\HtmlString("
                            <div class='space-y-6 text-gray-900 dark:text-gray-100 p-1'>
                                <div class='p-6 bg-slate-50/80 dark:bg-slate-900/60 border border-slate-200/80 dark:border-slate-800 rounded-2xl shadow-sm space-y-3'>
                                    <div class='flex items-center gap-2.5'>
                                        <span class='px-3 py-1 text-xs font-black tracking-wider uppercase rounded-md bg-primary-100 text-primary-800 border border-primary-300 dark:bg-primary-950/80 dark:text-primary-200 dark:border-primary-700 shadow-xs'>
                                            {$record->external_item_key}
                                        </span>
                                        <span class='px-3 py-1 text-xs font-semibold rounded-md bg-gray-200/70 text-gray-700 dark:bg-gray-800 dark:text-gray-300'>
                                            {$record->item_type}
                                        </span>
                                    </div>
                                    <h3 class='text-xl font-extrabold text-gray-900 dark:text-gray-100 leading-snug tracking-tight'>
                                        {$record->summary}
                                    </h3>
                                </div>

                                <div class='flex flex-wrap items-center gap-4 sm:gap-6 p-4 bg-gray-50/80 dark:bg-gray-800/40 rounded-xl border border-gray-200/80 dark:border-gray-800 text-xs'>
                                    <div class='flex items-center gap-2'>
                                        <span class='font-bold text-gray-400 uppercase tracking-widest text-[10px]'>Delivery Status</span>
                                        <span class='px-3 py-0.5 font-bold rounded-full border text-xs {$statusColors}'>
                                            {$record->delivery_status_label}
                                        </span>
                                    </div>

                                    <div class='hidden sm:block h-4 w-px bg-gray-200 dark:bg-gray-700'></div>

                                    <div class='flex items-center gap-2'>
                                        <span class='font-bold text-gray-400 uppercase tracking-widest text-[10px]'>Priority</span>
                                        <span class='px-3 py-0.5 font-bold rounded-full border text-xs {$priorityColors}'>
                                            " . ($record->priority ?: 'Medium') . "
                                        </span>
                                    </div>

                                    {$hoursHtml}
                                </div>

                                <div>
                                    <h4 class='text-xs font-black tracking-widest text-gray-400 uppercase mb-3 flex items-center gap-2'>
                                        <svg class='w-4 h-4 text-primary-600 dark:text-primary-400' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'></path></svg>
                                        Business Scope & Technical Requirements
                                    </h4>
                                    <div class='prose dark:prose-invert max-w-none text-sm text-gray-800 dark:text-gray-200 bg-white dark:bg-gray-900 border border-gray-200/90 dark:border-gray-800 rounded-2xl p-6 shadow-sm space-y-4 leading-relaxed [&_h3]:text-xs [&_h3]:font-black [&_h3]:tracking-wider [&_h3]:uppercase [&_h3]:text-primary-700 [&_h3]:dark:text-primary-300 [&_h3]:bg-primary-50/80 [&_h3]:dark:bg-primary-950/60 [&_h3]:px-3 [&_h3]:py-1.5 [&_h3]:rounded-lg [&_h3]:border [&_h3]:border-primary-200/60 [&_h3]:dark:border-primary-800/50 [&_h3]:mt-6 [&_h3]:mb-3 [&_h3]:inline-block [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:my-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:my-2 [&_li]:leading-relaxed [&_a]:text-primary-600 [&_a]:underline [&_strong]:font-bold [&_strong]:text-gray-900 [&_strong]:dark:text-gray-100'>
                                        " . (! empty($descriptionText) ? \Illuminate\Support\Str::markdown($descriptionText) : '<p class="text-gray-500 italic">No detailed description available in Jira for this task.</p>') . "
                                    </div>
                                </div>
                            </div>
                        ");

                        return [
                            Placeholder::make('formatted_details_card')
                                ->hiddenLabel()
                                ->content($cardHtml),
                        ];
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('change_priority')
                    ->label('Change Priority')
                    ->icon('heroicon-m-arrows-up-down')
                    ->color('warning')
                    ->form([
                        Select::make('new_priority')
                            ->label('New Priority')
                            ->options([
                                'Highest' => 'Highest',
                                'High'    => 'High',
                                'Medium'  => 'Medium',
                                'Low'     => 'Low',
                                'Lowest'  => 'Lowest',
                            ])
                            ->helperText(function (PmWorkItem $record) {
                                $projectId = $record->pm_project_id;
                                $clientId = $record->client_id;
                                $count = PmWorkItem::query()
                                    ->when($projectId, fn ($q) => $q->where('pm_project_id', $projectId))
                                    ->when(! $projectId && $clientId, fn ($q) => $q->where('client_id', $clientId))
                                    ->where('priority', 'Highest')
                                    ->whereNotIn('normalized_delivery_status', ['completed', 'canceled'])
                                    ->count();

                                return "Active 'Highest Priority' tasks in this project workspace: {$count}/3 limit.";
                            })
                            ->default(fn (PmWorkItem $record) => $record->priority ?? 'Medium')
                            ->required(),

                        Textarea::make('reason')
                            ->label('Reason / Note for Priority Change')
                            ->placeholder('Explain why this priority is being changed...')
                            ->rows(3),
                    ])
                    ->action(function (PmWorkItem $record, array $data, JiraProvider $jiraProvider) {
                        $user = Auth::user();
                        $userName = $user?->name ?? 'Customer User';
                        $userEmail = $user?->email ?? '';
                        $oldPriority = $record->priority ?? 'Medium';
                        $newPriority = $data['new_priority'];
                        $reason = trim($data['reason'] ?? '');

                        // 1. Enforce max 3 active Highest Priority items per project
                        if ($newPriority === 'Highest' && $oldPriority !== 'Highest') {
                            $projectId = $record->pm_project_id;
                            $clientId = $record->client_id;

                            $activeHighestCount = PmWorkItem::query()
                                ->when($projectId, fn ($q) => $q->where('pm_project_id', $projectId))
                                ->when(! $projectId && $clientId, fn ($q) => $q->where('client_id', $clientId))
                                ->where('priority', 'Highest')
                                ->whereNotIn('normalized_delivery_status', ['completed', 'canceled'])
                                ->where('id', '!=', $record->id)
                                ->count();

                            if ($activeHighestCount >= 3) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Highest Priority Limit Reached (3/3)')
                                    ->body("This project already has 3 active Highest Priority tasks ({$activeHighestCount}/3). Please lower the priority of another task before promoting this task to Highest.")
                                    ->danger()
                                    ->persistent()
                                    ->send();

                                return;
                            }
                        }

                        // 2. Update priority in Jira & local DB
                        $jiraProvider->updatePriority($record, $newPriority, $user);

                        // 2. Add tracking comment in Jira
                        $nowFormatted = now()->format('F d, Y \a\t H:i T');
                        $comment = "⚡ Priority changed from {$oldPriority} to {$newPriority} by {$userName}" . ($userEmail ? " ({$userEmail})" : '') . " on {$nowFormatted}.";
                        if (! empty($reason)) {
                            $comment .= "\n\nNote / Reason: {$reason}";
                        }

                        $jiraProvider->addComment($record, $comment, $user);

                        // 3. Local update fallback & notification
                        $record->update(['priority' => $newPriority]);

                        \Filament\Notifications\Notification::make()
                            ->title("Priority Updated to {$newPriority} in Jira")
                            ->success()
                            ->send();

                        $this->dispatch('client-changed');
                    }),
            ]);
    }
}
