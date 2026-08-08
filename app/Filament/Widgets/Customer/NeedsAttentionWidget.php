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
                        ->size(Tables\Columns\TextColumn\TextColumnSize::Large),

                    Tables\Columns\TextColumn::make('description')
                        ->color('gray')
                        ->limit(120),
                ])->space(3),
            ])
            ->actions([
                Action::make('complete_action_item')
                    ->label('Mark Complete')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (CustomerAttentionItem $record): bool => ! in_array($record->category, ['estimate_approval', 'estimate_reapproval'], true))
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
                        $prevApproved = $workItem->estimateVersions()
                            ->where('version', '<', $version->version)
                            ->get()
                            ->first(fn ($v) => $v->latestEvent?->event_type === 'approved');

                        $prevHours = $prevApproved ? $prevApproved->estimated_hours : 0;
                        $diff = $version->estimated_hours - $prevHours;
                        $diffFormatted = ($diff >= 0 ? '+' : '') . $diff . ' hrs';

                        return [
                            Placeholder::make('task_summary')
                                ->label('Task / Story')
                                ->content("{$workItem->external_item_key}: {$workItem->summary}"),

                            Placeholder::make('estimate_details')
                                ->label('Proposed Estimate (v' . $version->version . ')')
                                ->content("{$version->estimated_hours} hrs" . ($prevHours > 0 ? " (Previous: {$prevHours} hrs | Diff: {$diffFormatted})" : '')),

                            Placeholder::make('po_notes')
                                ->label('Product Owner Notes / Estimate Breakdown')
                                ->content(fn () => new \Illuminate\Support\HtmlString($version->po_notes ?: 'No additional notes provided.')),

                            Textarea::make('revision_notes')
                                ->label('Feedback / Notes')
                                ->placeholder('Required if requesting a revision...')
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
    }
}
