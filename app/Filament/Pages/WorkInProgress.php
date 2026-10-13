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
use Livewire\Attributes\Url;

class WorkInProgress extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use HasScopedClientFilter;

    #[Url(keep: true)]
    public string $quick_filter = 'all';

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

        $incomingQuickFilter = request()->query('quick_filter');
        $incomingScope = request()->query('scope');

        if ($incomingQuickFilter && in_array($incomingQuickFilter, ['all', 'pipeline', 'ready', 'in_progress', 'ready_for_deployment', 'backlog', 'done'], true)) {
            $this->quick_filter = $incomingQuickFilter;
        } elseif ($incomingScope) {
            $this->quick_filter = match ($incomingScope) {
                'in_progress'                 => 'in_progress',
                'all_pipeline'                => 'pipeline',
                'planned_ready', 'ready'      => 'ready',
                'ready_for_deployment'        => 'ready_for_deployment',
                'backlog', 'on_hold'          => 'backlog',
                'done', 'completed'           => 'done',
                default                       => 'all',
            };
        }
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

        $query = PmWorkItem::query()
            ->where('client_id', $clientId)
            ->whereHas('project', function ($q) {
                $q->where('external_project_key', '!=', 'SUP');
            })
            ->where('external_item_key', 'NOT LIKE', 'SUP-%')
            ->whereIn('id', function ($sub) use ($clientId) {
                $sub->select(\Illuminate\Support\Facades\DB::raw('MAX(id)'))
                    ->from('pm_work_items')
                    ->where('client_id', $clientId)
                    ->groupBy('external_item_key');
            });

        match ($this->quick_filter) {
            'pipeline' => $query->where(function ($q) {
                $q->whereIn('normalized_delivery_status', ['ready', 'in_progress'])
                  ->orWhereRaw('LOWER(external_status) LIKE ?', ['%in progress%']);
            }),
            'ready' => $query->where('normalized_delivery_status', 'ready'),
            'in_progress' => $query->where(function ($q) {
                $q->where('normalized_delivery_status', 'in_progress')
                  ->orWhereRaw('LOWER(external_status) LIKE ?', ['%in progress%']);
            }),
            'ready_for_deployment' => $query->where('normalized_delivery_status', 'ready_for_deployment'),
            'backlog' => $query->whereIn('normalized_delivery_status', ['backlog', 'on_hold']),
            'done' => $query->whereIn('normalized_delivery_status', ['completed', 'resolved']),
            default => $query->whereNotIn('normalized_delivery_status', ['cancelled', 'canceled', 'rejected', 'aborted']),
        };

        $query->orderBy('updated_at', 'desc');

        return $table
            ->query($query)
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
                        'completed', 'ready_for_deployment' => 'success',
                        'customer_review'                   => 'warning',
                        'review_qa'                         => 'info',
                        'in_progress'                       => 'primary',
                        'rework'                            => 'danger',
                        'on_hold'                           => 'gray',
                        'ready'                             => 'gray',
                        default                             => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'planned'               => 'Planned',
                        'ready'                 => 'Ready for Dev',
                        'in_progress'           => 'In Progress',
                        'review_qa'             => 'Review / QA',
                        'customer_review'       => 'Customer Review',
                        'ready_for_deployment' => 'Ready for Deployment',
                        'completed'             => 'Completed',
                        'on_hold'               => 'On Hold',
                        'rework'                => 'Rework',
                        default                 => ucfirst(str_replace('_', ' ', $state)),
                    }),

                Tables\Columns\TextColumn::make('external_status')
                    ->label('Jira Status (Raw)')
                    ->badge()
                    ->color('info')
                    ->visible(fn () => ! auth()->user()?->isClientOnly()),

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
                        'on_hold'         => 'On Hold',
                        'rework'          => 'Rework',
                    ])
                    ->query(function ($query, array $data) {
                        if ($this->quick_filter !== 'all') {
                            return $query;
                        }

                        $value = $data['value'] ?? 'all_pipeline';

                        return match ($value) {
                            'in_progress' => $query->where(function ($q) {
                                $q->where('normalized_delivery_status', 'in_progress')
                                  ->orWhereRaw('LOWER(external_status) LIKE ?', ['%in progress%']);
                            }),
                            'review_qa'       => $query->whereIn('normalized_delivery_status', ['review_qa', 'customer_review']),
                            'customer_review' => $query->where('normalized_delivery_status', 'customer_review'),
                            'planned_ready'   => $query->whereIn('normalized_delivery_status', ['planned', 'ready']),
                            'on_hold'         => $query->where('normalized_delivery_status', 'on_hold'),
                            'rework'          => $query->where('normalized_delivery_status', 'rework'),
                            'all_pipeline'    => $query,
                            default           => $query,
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
                                $rawAttachments = $issue['fields']['attachment'] ?? [];
                                $normalizedAttachments = [];
                                if (is_array($rawAttachments)) {
                                    foreach ($rawAttachments as $att) {
                                        $normalizedAttachments[] = [
                                            'id'            => (string) ($att['id'] ?? ''),
                                            'filename'      => $att['filename'] ?? 'attachment',
                                            'size'          => (int) ($att['size'] ?? 0),
                                            'mime_type'     => $att['mimeType'] ?? ($att['mime_type'] ?? 'application/octet-stream'),
                                            'url'           => $att['content'] ?? ($att['url'] ?? null),
                                            'thumbnail_url' => $att['thumbnail'] ?? ($att['thumbnail_url'] ?? null),
                                            'author_name'   => $att['author']['displayName'] ?? ($att['author_name'] ?? null),
                                            'created_at'    => $att['created'] ?? ($att['created_at'] ?? null),
                                        ];
                                    }
                                }
                                $activeAttachments = ! empty($normalizedAttachments) ? $normalizedAttachments : ($record->attachments_json ?? []);
                                $parsedDesc = JiraProvider::parseAdfToMarkdown($descAdf, $activeAttachments, $record->external_item_key);
                                
                                $updateData = [];
                                if ($parsedDesc) {
                                    $descriptionText = $parsedDesc;
                                    $updateData['description'] = $parsedDesc;
                                }
                                if (! empty($normalizedAttachments)) {
                                    $updateData['attachments_json'] = $normalizedAttachments;
                                    $record->attachments_json = $normalizedAttachments;
                                }
                                if (! empty($updateData)) {
                                    $record->update($updateData);
                                }
                            } catch (\Throwable $e) {
                                // Fallback silently
                            }
                        }

                        $attachments = $record->attachments_json ?? [];
                        $attCount = count($attachments);

                        if ($attCount === 0) {
                            $attachmentsHtml = "
                                <div class='p-4 bg-gray-50/60 dark:bg-gray-800/30 rounded-xl border border-gray-200/60 dark:border-gray-800 text-xs text-gray-400 italic flex items-center gap-2'>
                                    <svg class='w-4 h-4 text-gray-400 shrink-0' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13'></path></svg>
                                    No attachments uploaded for this task in Jira.
                                </div>
                            ";
                        } else {
                            $cards = [];
                            foreach ($attachments as $att) {
                                $attId = htmlspecialchars($att['id'] ?? '', ENT_QUOTES);
                                $filename = htmlspecialchars($att['filename'] ?? 'attachment', ENT_QUOTES);
                                $mime = $att['mime_type'] ?? '';
                                $sizeBytes = (int) ($att['size'] ?? 0);
                                $sizeFormatted = $sizeBytes >= 1048576 
                                    ? round($sizeBytes / 1048576, 1) . ' MB' 
                                    : round(max(1, $sizeBytes / 1024)) . ' KB';
                                
                                $isImage = str_starts_with($mime, 'image/') || (bool) preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $filename);
                                $itemKey = htmlspecialchars($record->external_item_key ?? '', ENT_QUOTES);
                                $viewUrl = "/pm/attachments/{$itemKey}/{$attId}";
                                $thumbUrl = "/pm/attachments/{$itemKey}/{$attId}?thumbnail=1";
                                $downloadUrl = "/pm/attachments/{$itemKey}/{$attId}?download=1";

                                if ($isImage) {
                                    $previewHtml = "
                                        <a href='{$viewUrl}' target='_blank' class='block aspect-video w-full rounded-lg bg-gray-100 dark:bg-gray-800 overflow-hidden mb-2.5 relative group border border-gray-200/80 dark:border-gray-700/80'>
                                            <img src='{$thumbUrl}' alt='{$filename}' class='w-full h-full object-cover group-hover:scale-105 transition-transform duration-200' loading='lazy' />
                                            <div class='absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center'>
                                                <span class='opacity-0 group-hover:opacity-100 px-2 py-1 text-[10px] font-bold bg-black/75 text-white rounded shadow-sm transition-opacity'>View Full</span>
                                            </div>
                                        </a>
                                    ";
                                } else {
                                    $ext = strtoupper(pathinfo($filename, PATHINFO_EXTENSION) ?: 'FILE');
                                    $previewHtml = "
                                        <a href='{$viewUrl}' target='_blank' class='flex flex-col items-center justify-center aspect-video w-full rounded-lg bg-gray-100/80 dark:bg-gray-800/80 mb-2.5 relative group border border-gray-200/80 dark:border-gray-700/80 text-gray-400 hover:text-primary-600 transition-colors'>
                                            <svg class='w-8 h-8 mb-1' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'></path></svg>
                                            <span class='px-1.5 py-0.5 text-[9px] font-black rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 tracking-wider'>{$ext}</span>
                                        </a>
                                    ";
                                }

                                $cards[] = "
                                    <div class='p-3 bg-white dark:bg-gray-900 border border-gray-200/80 dark:border-gray-800 rounded-xl shadow-xs flex flex-col justify-between hover:border-primary-400/60 transition-colors'>
                                        <div>
                                            {$previewHtml}
                                            <div class='font-bold text-xs text-gray-900 dark:text-gray-100 truncate' title='{$filename}'>
                                                {$filename}
                                            </div>
                                            <div class='text-[10px] text-gray-400 mt-0.5'>
                                                {$sizeFormatted}
                                            </div>
                                        </div>
                                        <div class='flex items-center gap-2 mt-3 pt-2 border-t border-gray-100 dark:border-gray-800/80 text-xs'>
                                            <a href='{$viewUrl}' target='_blank' class='flex-1 py-1 px-2 text-center text-[11px] font-semibold text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-md transition-colors'>
                                                Preview
                                            </a>
                                            <a href='{$downloadUrl}' class='flex-1 py-1 px-2 text-center text-[11px] font-semibold text-primary-700 dark:text-primary-300 bg-primary-50 dark:bg-primary-950/60 hover:bg-primary-100 dark:hover:bg-primary-900/60 rounded-md transition-colors'>
                                                Download
                                            </a>
                                        </div>
                                    </div>
                                ";
                            }
                            $attachmentsHtml = "<div class='grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5'>" . implode('', $cards) . "</div>";
                        }

                        $statusColors = match ($record->normalized_delivery_status) {
                            'completed'       => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
                            'customer_review' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
                            'review_qa'       => 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800',
                            'in_progress'     => 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800',
                            'rework'          => 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-800',
                            'on_hold'         => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
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
                                    <div class='prose dark:prose-invert max-w-none text-sm text-gray-800 dark:text-gray-200 bg-white dark:bg-gray-900 border border-gray-200/90 dark:border-gray-800 rounded-2xl p-6 shadow-sm space-y-4 leading-relaxed [&_h3]:text-xs [&_h3]:font-black [&_h3]:tracking-wider [&_h3]:uppercase [&_h3]:text-primary-700 [&_h3]:dark:text-primary-300 [&_h3]:bg-primary-50/80 [&_h3]:dark:bg-primary-950/60 [&_h3]:px-3 [&_h3]:py-1.5 [&_h3]:rounded-lg [&_h3]:border [&_h3]:border-primary-200/60 [&_h3]:dark:border-primary-800/50 [&_h3]:mt-6 [&_h3]:mb-3 [&_h3]:inline-block [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:my-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:my-2 [&_li]:leading-relaxed [&_a]:text-primary-600 [&_a]:underline [&_img]:rounded-xl [&_img]:max-w-full [&_img]:h-auto [&_img]:my-4 [&_img]:border [&_img]:border-gray-200/80 [&_img]:dark:border-gray-700 [&_img]:shadow-sm [&_strong]:font-bold [&_strong]:text-gray-900 [&_strong]:dark:text-gray-100'>
                                        " . (! empty($descriptionText) ? \Illuminate\Support\Str::markdown($descriptionText) : '<p class="text-gray-500 italic">No detailed description available in Jira for this task.</p>') . "
                                    </div>
                                </div>

                                <div>
                                    <h4 class='text-xs font-black tracking-widest text-gray-400 uppercase mb-3 flex items-center gap-2'>
                                        <svg class='w-4 h-4 text-primary-600 dark:text-primary-400' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13'></path></svg>
                                        Attachments & Media ({$attCount})
                                    </h4>
                                    {$attachmentsHtml}
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
                                    ->whereNotIn('normalized_delivery_status', ['completed', 'canceled', 'cancelled'])
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
                                ->whereNotIn('normalized_delivery_status', ['completed', 'canceled', 'cancelled'])
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
            ])
            ->emptyStateHeading(fn () => match ($this->quick_filter) {
                'pipeline'             => 'No active pipeline tasks',
                'ready'                => 'No tasks ready for development',
                'in_progress'          => 'No tasks currently in progress',
                'ready_for_deployment' => 'No tasks ready for deployment',
                'backlog'              => 'No tasks in backlog',
                'done'                 => 'No completed tasks found',
                default                => 'No work items found',
            })
            ->emptyStateDescription(fn () => match ($this->quick_filter) {
                'pipeline'             => 'There are currently no tasks in Ready for Dev or In Progress for this client.',
                'ready'                => 'There are currently no tasks waiting to be picked up for development.',
                'in_progress'          => 'There is no work actively being worked on by engineers at this time.',
                'ready_for_deployment' => 'There are no completed features awaiting staging or production deployment.',
                'backlog'              => 'There are no pending or parked items in the backlog for this client.',
                'done'                 => 'No finished work items match the selected client filter.',
                default                => 'Try selecting a different filter or customer account above.',
            })
            ->emptyStateActions([
                Action::make('clear_quick_filter')
                    ->label('View All Tasks')
                    ->icon('heroicon-m-arrow-path')
                    ->visible(fn () => $this->quick_filter !== 'all')
                    ->action(fn () => $this->setQuickFilter('all')),
            ]);
    }

    public function setQuickFilter(string $filter): void
    {
        $this->quick_filter = $filter;
        $this->resetTable();
    }

    public function clearQuickFilter(): void
    {
        $this->setQuickFilter('all');
    }

    public function getQuickFilterCounts(): array
    {
        $clientId = $this->isClientIdInScope(session('current_client_id'))
            ? (int) session('current_client_id')
            : $this->defaultScopedClientId();

        if (! $clientId) {
            return [
                'all' => 0,
                'pipeline' => 0,
                'ready' => 0,
                'in_progress' => 0,
                'ready_for_deployment' => 0,
                'backlog' => 0,
                'done' => 0,
            ];
        }

        $subQuery = \Illuminate\Support\Facades\DB::table('pm_work_items')
            ->select(\Illuminate\Support\Facades\DB::raw('MAX(id) as id'))
            ->where('client_id', $clientId)
            ->groupBy('external_item_key');

        $grouped = PmWorkItem::query()
            ->where('client_id', $clientId)
            ->where('external_item_key', 'NOT LIKE', 'SUP-%')
            ->whereHas('project', function ($q) {
                $q->where('external_project_key', '!=', 'SUP');
            })
            ->whereIn('id', $subQuery)
            ->select('normalized_delivery_status', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('normalized_delivery_status')
            ->pluck('count', 'normalized_delivery_status')
            ->toArray();

        $ready = (int) ($grouped['ready'] ?? 0);
        $inProgress = (int) ($grouped['in_progress'] ?? 0);
        $readyDeploy = (int) ($grouped['ready_for_deployment'] ?? 0);
        $backlog = (int) ($grouped['backlog'] ?? 0) + (int) ($grouped['on_hold'] ?? 0);
        $done = (int) ($grouped['completed'] ?? 0) + (int) ($grouped['resolved'] ?? 0);

        $all = 0;
        foreach ($grouped as $status => $cnt) {
            if (! in_array($status, ['cancelled', 'canceled', 'rejected', 'aborted'], true)) {
                $all += (int) $cnt;
            }
        }

        return [
            'all' => $all,
            'pipeline' => $ready + $inProgress,
            'ready' => $ready,
            'in_progress' => $inProgress,
            'ready_for_deployment' => $readyDeploy,
            'backlog' => $backlog,
            'done' => $done,
        ];
    }
}
