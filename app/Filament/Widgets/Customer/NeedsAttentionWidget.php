<?php

namespace App\Filament\Widgets\Customer;

use App\Models\CustomerAttentionItem;
use App\Models\ForgeEstimateVersion;
use App\Services\EstimateApprovalService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class NeedsAttentionWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected $listeners = ['client-changed' => '$refresh'];

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Needs Your Attention';

    public static function canView(): bool
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        self::syncCustomerActionItems($clientId);

        return CustomerAttentionItem::where('client_id', $clientId)->unresolved()->exists();
    }

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        self::syncCustomerActionItems($clientId);

        return $table
            ->paginated(false)
            ->contentGrid([
                'md' => 2,
                'xl' => 2,
            ])
            ->query(
                CustomerAttentionItem::query()
                    ->where('client_id', $clientId)
                    ->unresolved()
                    ->orderBy('created_at', 'desc')
            )
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('category')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'estimate_approval', 'estimate_reapproval' => 'warning',
                                'action_item_overdue', 'task_blocked'      => 'danger',
                                'customer_action_item', 'action_item'      => 'primary',
                                default                                     => 'info',
                            })
                            ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                        Tables\Columns\TextColumn::make('created_at')
                            ->dateTime('M d, H:i')
                            ->color('gray')
                            ->alignEnd(),
                    ]),

                    Tables\Columns\TextColumn::make('title')
                        ->weight('bold')
                        ->size(Tables\Columns\TextColumn\TextColumnSize::Large)
                        ->formatStateUsing(function (string $state, CustomerAttentionItem $record): string {
                            if ($record->source_type === 'forge_estimate_version' && $record->source_id) {
                                $version = ForgeEstimateVersion::find($record->source_id);
                                if ($version && $version->workItem) {
                                    return "{$version->workItem->external_item_key}: {$version->workItem->summary}";
                                }
                            }
                            return str_replace('Action Item: ', '', $state);
                        }),

                    Tables\Columns\TextColumn::make('description')
                        ->color('gray')
                        ->html()
                        ->formatStateUsing(function (string $state, CustomerAttentionItem $record): string {
                            if ($record->source_type === 'forge_estimate_version' && $record->source_id) {
                                $version = ForgeEstimateVersion::find($record->source_id);
                                if ($version) {
                                    $hours = $version->estimated_hours;
                                    $verNum = $version->version;
                                    $notes = e(\Illuminate\Support\Str::limit($version->po_notes ?: 'No additional notes', 100));

                                    return "<div class='space-y-1 mt-1'>
                                        <div class='inline-flex items-center gap-2'>
                                            <span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20'>
                                                Proposed Budget: {$hours} hrs (v{$verNum})
                                            </span>
                                        </div>
                                        <div class='text-xs text-gray-500 dark:text-gray-400 mt-0.5'>{$notes}</div>
                                    </div>";
                                }
                            }
                            return e(\Illuminate\Support\Str::limit($state, 120));
                        }),
                ])->space(3),
            ])
            ->actions([
                Action::make('complete_action_item')
                    ->label('Mark Complete')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(function (CustomerAttentionItem $record): bool {
                        if (in_array($record->category, ['estimate_approval', 'estimate_reapproval'], true)) {
                            return false;
                        }
                        if ($record->source_type === 'pm_work_item' || in_array($record->category, ['task_blocked', 'waiting_on_customer'], true)) {
                            return false;
                        }
                        return true;
                    })
                    ->form(fn (CustomerAttentionItem $record): array => [
                        Placeholder::make('title')
                            ->label('Action Item')
                            ->content($record->title),

                        Placeholder::make('description')
                            ->label('Details')
                            ->content($record->description),

                        Textarea::make('completion_notes')
                            ->label('Completion Note / Updates')
                            ->placeholder('Provide any details, notes, or links regarding the completion of this action item...')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (CustomerAttentionItem $record, array $data) {
                        $note = trim($data['completion_notes'] ?? '');
                        $record->update([
                            'is_resolved' => true,
                            'resolved_at' => now(),
                            'description' => $record->description . "\n\nCompletion Note: " . $note,
                        ]);

                        if ($record->source_type === 'meeting_action_item' && $record->source_id) {
                            \App\Models\MeetingActionItem::where('id', $record->source_id)->update(['status' => 'completed']);
                        }

                        Notification::make()->title('Action Item Marked Complete Successfully')->success()->send();
                        $this->dispatch('client-changed');
                    }),

                Action::make('view_jira_ticket')
                    ->label('View Ticket')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('primary')
                    ->visible(function (CustomerAttentionItem $record): bool {
                        if (in_array($record->category, ['estimate_approval', 'estimate_reapproval'], true)) {
                            return false;
                        }
                        return $record->source_type === 'pm_work_item'
                            || in_array($record->category, ['task_blocked', 'waiting_on_customer'], true)
                            || ! empty($record->action_url);
                    })
                    ->url(function (CustomerAttentionItem $record): ?string {
                        if (! empty($record->action_url)) {
                            return $record->action_url;
                        }
                        if ($record->source_type === 'pm_work_item' && $record->source_id) {
                            $workItem = \App\Models\PmWorkItem::find($record->source_id);
                            if ($workItem && $workItem->external_item_key) {
                                return "https://technopath.atlassian.net/servicedesk/customer/portal/4/{$workItem->external_item_key}";
                            }
                        }
                        if (preg_match('/([A-Z0-9]+-\d+)/i', $record->title, $m)) {
                            return "https://technopath.atlassian.net/servicedesk/customer/portal/4/{$m[1]}";
                        }
                        return null;
                    })
                    ->openUrlInNewTab(),

                Action::make('review_estimate')
                    ->label('Review & Respond')
                    ->icon('heroicon-m-eye')
                    ->modalWidth(MaxWidth::SevenExtraLarge)
                    ->extraModalWindowAttributes(['class' => '!max-w-[85vw] !w-[85vw]'])
                    ->visible(fn (CustomerAttentionItem $record): bool => in_array($record->category, ['estimate_approval', 'estimate_reapproval'], true))
                    ->form(function (CustomerAttentionItem $record) {
                        $version = ForgeEstimateVersion::find($record->source_id);
                        if (! $version) {
                            return [Placeholder::make('info')->content('Estimate details unavailable.')];
                        }

                        $workItem = $version->workItem;
                        $prevApproved = $workItem ? $workItem->estimateVersions()
                            ->where('version', '<', $version->version)
                            ->get()
                            ->first(fn ($v) => $v->latestEvent?->event_type === 'approved') : null;

                        $prevHours = $prevApproved ? $prevApproved->estimated_hours : 0;
                        $diff = $version->estimated_hours - $prevHours;
                        $diffFormatted = ($diff >= 0 ? '+' : '') . $diff . ' hrs';

                        // 1. Format Task Title Header Card
                        $taskKey = $workItem ? e($workItem->external_item_key) : 'N/A';
                        $taskSummary = $workItem ? e($workItem->summary) : 'N/A';
                        $issueType = $workItem ? e(ucfirst($workItem->issue_type ?: 'Task')) : 'Task';

                        // 2. Format Description from Markdown / ADF if available
                        $descriptionMarkdown = 'No task description details provided.';
                        if ($workItem && ! empty($workItem->description)) {
                            $desc = $workItem->description;
                            if (str_starts_with(trim($desc), '{') && str_contains($desc, '"type":"doc"')) {
                                try {
                                    $json = json_decode($desc, true);
                                    $parsed = \App\Services\PM\Providers\JiraProvider::parseAdfToMarkdown($json);
                                    $descriptionMarkdown = $parsed ?: $desc;
                                } catch (\Throwable $e) {
                                    $descriptionMarkdown = $desc;
                                }
                            } else {
                                $descriptionMarkdown = $desc;
                            }
                        }

                        // Parse section titles into primary header badges
                        $descriptionMarkdown = preg_replace(
                            '/^(Action Required|Key Information|Acceptance Criteria|Description Background):\s*/im',
                            "### $1\n",
                            $descriptionMarkdown
                        );

                        $descriptionHtml = \Illuminate\Support\Str::markdown($descriptionMarkdown);
                        $poNotesHtml = $version->po_notes ?: 'No additional breakdown notes provided.';

                        $headerCardHtml = "
                            <div class='bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5 shadow-sm space-y-4'>
                                <div class='flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-gray-200 dark:border-gray-800'>
                                    <div class='flex items-center gap-2.5'>
                                        <span class='px-3 py-1 bg-primary-500/10 text-primary-600 dark:text-primary-400 border border-primary-500/20 font-mono font-bold text-xs rounded-lg uppercase tracking-wider'>{$taskKey}</span>
                                        <span class='px-2.5 py-0.5 bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium text-xs rounded-md'>{$issueType}</span>
                                    </div>
                                    <div class='inline-flex items-center gap-2 px-3 py-1 bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 rounded-lg text-xs font-bold'>
                                        <span>Proposed Estimate: v{$version->version}</span>
                                        <span class='text-sm font-extrabold'>{$version->estimated_hours} hrs</span>
                                    </div>
                                </div>

                                <div>
                                    <h2 class='text-xl font-extrabold text-gray-900 dark:text-white leading-snug'>{$taskSummary}</h2>
                                    " . ($prevHours > 0 ? "
                                    <p class='text-xs text-amber-600 dark:text-amber-400 font-medium mt-1.5'>
                                        ⚡ Reapproval Request — Previous Approved Budget: <strong>{$prevHours} hrs</strong> (Diff: <strong>{$diffFormatted}</strong>)
                                    </p>" : '') . "
                                </div>
                            </div>";

                        $poNotesCardHtml = "
                            <div class='bg-amber-500/5 dark:bg-amber-500/10 border border-amber-500/20 rounded-xl p-4 space-y-2'>
                                <div class='flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400'>
                                    <svg class='w-4 h-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'></path></svg>
                                    Product Owner Notes / Estimate Breakdown
                                </div>
                                <div class='text-sm text-gray-800 dark:text-gray-200 font-sans leading-relaxed'>" . $poNotesHtml . "</div>
                            </div>";

                        $scopeCardHtml = "
                            <div class='bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5 shadow-sm space-y-3'>
                                <div class='flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 pb-2 border-b border-gray-100 dark:border-gray-800'>
                                    <svg class='w-4 h-4 text-primary-500' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'></path></svg>
                                    Task Scope & Technical Requirements
                                </div>
                                <div class='prose prose-sm dark:prose-invert max-w-none text-gray-800 dark:text-gray-200 leading-relaxed font-sans max-h-[350px] overflow-y-auto pr-3 pb-2'>
                                    " . $descriptionHtml . "
                                </div>
                            </div>";

                        return [
                            Placeholder::make('task_header')
                                ->hiddenLabel()
                                ->content(fn () => new \Illuminate\Support\HtmlString($headerCardHtml)),

                            Placeholder::make('po_notes')
                                ->hiddenLabel()
                                ->content(fn () => new \Illuminate\Support\HtmlString($poNotesCardHtml)),

                            Placeholder::make('task_scope')
                                ->hiddenLabel()
                                ->content(fn () => new \Illuminate\Support\HtmlString($scopeCardHtml)),

                            Textarea::make('revision_notes')
                                ->label('Approval or Revision Feedback Notes')
                                ->placeholder('Optional note if approving, or required reason if requesting a revision...')
                                ->rows(3),
                        ];
                    })
                    ->action(function (CustomerAttentionItem $record, array $data, array $arguments, EstimateApprovalService $approvalService) {
                        $version = ForgeEstimateVersion::find($record->source_id);
                        if (! $version) {
                            return;
                        }

                        $user = Auth::user();
                        $action = $arguments['btn_action'] ?? 'approve';

                        if ($action === 'approve') {
                            $approvalService->approveEstimate($version, $user, $data['revision_notes'] ?? null);
                            Notification::make()->title('Estimate Approved Successfully')->success()->send();
                        } else {
                            if (empty($data['revision_notes'])) {
                                Notification::make()->title('Feedback required when requesting revision')->danger()->send();
                                return;
                            }
                            $approvalService->requestRevision($version, $user, $data['revision_notes']);
                            Notification::make()->title('Revision Requested')->warning()->send();
                        }

                        $this->dispatch('client-changed');
                    })
                    ->modalSubmitActionLabel('Approve Estimate')
                    ->extraModalFooterActions([
                        Action::make('request_revision')
                            ->label('Request Revision')
                            ->color('warning')
                            ->action(function (CustomerAttentionItem $record, array $data, EstimateApprovalService $approvalService) {
                                $version = ForgeEstimateVersion::find($record->source_id);
                                if (! $version) {
                                    return;
                                }
                                if (empty($data['revision_notes'])) {
                                    Notification::make()->title('Feedback notes required to request revision.')->danger()->send();
                                    return;
                                }
                                $user = Auth::user();
                                $approvalService->requestRevision($version, $user, $data['revision_notes']);
                                Notification::make()->title('Revision Requested')->warning()->send();
                                $this->dispatch('client-changed');
                            }),
                    ]),
            ]);
    }

    public static function syncCustomerActionItems(int $clientId): void
    {
        // 1. Clean up non-customer-facing attention items if any exist
        $nonCustomerFacingIds = \App\Models\MeetingActionItem::whereHas('meeting', fn ($q) => $q->where('client_id', $clientId))
            ->where('is_customer_facing', false)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        if ($nonCustomerFacingIds->isNotEmpty()) {
            CustomerAttentionItem::where('client_id', $clientId)
                ->where('source_type', 'meeting_action_item')
                ->whereIn('source_id', $nonCustomerFacingIds)
                ->delete();
        }

        // 2. Only sync items where is_customer_facing is true
        $actionItems = \App\Models\MeetingActionItem::whereHas('meeting', fn ($q) => $q->where('client_id', $clientId))
            ->where('is_customer_facing', true)
            ->whereNotIn('status', ['completed', 'cancelled', 'resolved'])
            ->get();

        foreach ($actionItems as $item) {
            CustomerAttentionItem::firstOrCreate(
                [
                    'client_id'   => $clientId,
                    'source_type' => 'meeting_action_item',
                    'source_id'   => (string) $item->id,
                ],
                [
                    'category'    => 'customer_action_item',
                    'title'       => $item->title,
                    'description' => "Assigned to {$item->owner_name} from " . ($item->meeting?->title ?? 'Client Meeting') . ($item->due_date ? " (Due: {$item->due_date->format('M d, Y')})" : ''),
                    'severity'    => 'high',
                    'is_resolved' => false,
                ]
            );
        }

        // 2b. Check estimate approval needed ONLY for work items with approval-needed or approval_needed label
        $approvalService = app(\App\Services\EstimateApprovalService::class);
        $clientWorkItems = \App\Models\PmWorkItem::where('client_id', $clientId)->get();
        foreach ($clientWorkItems as $wi) {
            $approvalService->checkInitialEstimateApprovalNeeded($wi);
        }

        // Clean up estimate approval items for work items that DO NOT have approval-needed or approval_needed label
        $estimateAttentionItems = CustomerAttentionItem::where('client_id', $clientId)
            ->whereIn('category', ['estimate_approval', 'estimate_reapproval'])
            ->get();

        foreach ($estimateAttentionItems as $attItem) {
            $version = \App\Models\ForgeEstimateVersion::find($attItem->source_id);
            if (! $version) {
                $attItem->delete();
                continue;
            }

            $workItem = $version->workItem;
            if (! $workItem || (! $workItem->hasLabel('approval-needed') && ! $workItem->hasLabel('approval_needed'))) {
                $attItem->delete();
                if ($workItem && $workItem->estimateVersions()->count() === 1) {
                    $version->approvalEvents()->delete();
                    $version->delete();
                }
            }
        }

        // 3. Sync blocked tasks and customer review tasks
        $attentionWorkItems = \App\Models\PmWorkItem::where('client_id', $clientId)
            ->where(function ($q) {
                $q->where('is_blocked', true)
                  ->orWhere('normalized_delivery_status', 'customer_review');
            })
            ->get();

        $activeWorkItemIds = $attentionWorkItems->pluck('id')->map(fn ($id) => (string) $id)->toArray();

        // Resolve attention items for work items that are no longer blocked or in customer review
        CustomerAttentionItem::where('client_id', $clientId)
            ->where('source_type', 'pm_work_item')
            ->whereNotIn('source_id', $activeWorkItemIds)
            ->update([
                'is_resolved' => true,
                'resolved_at' => now(),
            ]);

        foreach ($attentionWorkItems as $item) {
            $cat = $item->is_blocked ? 'task_blocked' : 'waiting_on_customer';
            CustomerAttentionItem::updateOrCreate(
                [
                    'client_id'   => $clientId,
                    'source_type' => 'pm_work_item',
                    'source_id'   => (string) $item->id,
                ],
                [
                    'category'    => $cat,
                    'title'       => "{$item->external_item_key}: {$item->summary}",
                    'description' => $item->is_blocked
                        ? ("Task is blocked: " . ($item->blocked_reason ?: 'Requires clarification or customer action'))
                        : "Waiting on customer review or input for {$item->external_item_key}",
                    'severity'    => $item->is_blocked ? 'warning' : 'info',
                    'action_url'  => "https://technopath.atlassian.net/servicedesk/customer/portal/4/{$item->external_item_key}",
                    'is_resolved' => false,
                ]
            );
        }
    }
}
