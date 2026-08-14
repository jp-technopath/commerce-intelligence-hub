<?php

namespace App\Filament\Widgets\Customer;

use App\Models\PmWorkItem;
use Filament\Forms\Components\Placeholder;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class ApprovedTasksWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected $listeners = ['client-changed' => '$refresh'];

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Approved Tasks';

    public static function canView(): bool
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        return PmWorkItem::where('client_id', $clientId)
            ->where('normalized_delivery_status', '!=', 'completed')
            ->whereHas('estimateVersions.approvalEvents', function ($q) {
                $q->where('event_type', 'approved');
            })
            ->exists();
    }

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        return $table
            ->paginated(false)
            ->contentGrid([
                'md' => 2,
                'xl' => 2,
            ])
            ->query(
                PmWorkItem::query()
                    ->where('client_id', $clientId)
                    ->where('normalized_delivery_status', '!=', 'completed')
                    ->whereHas('estimateVersions.approvalEvents', function ($q) {
                        $q->where('event_type', 'approved');
                    })
                    ->orderBy('updated_at', 'desc')
            )
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('external_item_key')
                            ->weight('bold'),

                        Tables\Columns\TextColumn::make('latestEstimateVersion.version')
                            ->formatStateUsing(fn ($state) => "APPROVED v{$state}")
                            ->badge()
                            ->color('success')
                            ->alignEnd(),
                    ]),

                    Tables\Columns\TextColumn::make('summary')
                        ->weight('bold')
                        ->size(Tables\Columns\TextColumn\TextColumnSize::Large),

                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('estimated_hours')
                            ->formatStateUsing(fn ($state) => "⚡ {$state} hrs budget")
                            ->color('primary')
                            ->weight('bold'),

                        Tables\Columns\TextColumn::make('latestEstimateVersion.latestEvent.created_at')
                            ->dateTime('M d, Y')
                            ->color('gray')
                            ->alignEnd(),
                    ]),
                ])->space(3),
            ])
            ->actions([
                Action::make('view_details')
                    ->label('Details')
                    ->icon('heroicon-m-information-circle')
                    ->modalWidth(\Filament\Support\Enums\MaxWidth::SixExtraLarge)
                    ->form(function (PmWorkItem $record) {
                        $user = Auth::user();
                        $canViewTaskHours = $user?->hasPermission('hours.view_task_totals') ?? false;

                        $approvedVersion = $record->estimateVersions()
                            ->whereHas('approvalEvents', fn ($q) => $q->where('event_type', 'approved'))
                            ->latest('version')
                            ->first();

                        $approvalEvent = $approvedVersion?->latestEvent;
                        $approverName = $approvalEvent?->actor?->name ?? 'Customer Approver';
                        $approvedDate = $approvalEvent?->created_at ? $approvalEvent->created_at->format('F d, Y \a\t H:i T') : 'N/A';
                        $feedback = $approvalEvent?->notes ?: 'No customer feedback notes provided.';

                        $statusColors = match ($record->normalized_delivery_status) {
                            'completed'       => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
                            'customer_review' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
                            'review_qa'       => 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800',
                            'in_progress'     => 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800',
                            default           => 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700',
                        };

                        $poBreakdown = $approvedVersion?->po_notes ? \Illuminate\Support\Str::markdown($approvedVersion->po_notes) : '<p class="text-gray-500 italic">No detailed breakdown provided.</p>';

                        $cardHtml = new \Illuminate\Support\HtmlString("
                            <div class='space-y-6 text-gray-900 dark:text-gray-100 p-1'>
                                <div class='p-6 bg-gradient-to-r from-emerald-50/60 via-emerald-50/20 to-white dark:from-emerald-950/30 dark:to-gray-900 border border-emerald-200/80 dark:border-emerald-800/50 rounded-2xl shadow-sm space-y-3'>
                                    <div class='flex items-center justify-between gap-2 mb-2'>
                                        <div class='flex items-center gap-2.5'>
                                            <span class='px-3 py-1 text-xs font-black tracking-wider uppercase rounded-md bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800'>
                                                {$record->external_item_key}
                                            </span>
                                            <span class='px-3 py-1 text-xs font-semibold rounded-md bg-gray-200/70 text-gray-700 dark:bg-gray-800 dark:text-gray-300'>
                                                {$record->item_type}
                                            </span>
                                        </div>
                                        <span class='px-3 py-1 text-xs font-bold rounded-full bg-emerald-500 text-white shadow-sm'>
                                            APPROVED v{$approvedVersion->version}
                                        </span>
                                    </div>
                                    <h3 class='text-xl font-extrabold text-gray-900 dark:text-gray-100 leading-snug tracking-tight'>
                                        {$record->summary}
                                    </h3>
                                </div>

                                <div class='p-5 bg-emerald-50/40 dark:bg-emerald-950/20 border border-emerald-200/60 dark:border-emerald-800/40 rounded-2xl space-y-2 text-xs'>
                                    <div class='flex items-center gap-2 text-emerald-800 dark:text-emerald-300 font-bold text-sm'>
                                        <svg class='w-4 h-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'></path></svg>
                                        Forge Customer Sign-Off Verified
                                    </div>
                                    <p class='text-gray-600 dark:text-gray-300 leading-relaxed'>
                                        Approved Version <strong>v{$approvedVersion->version}</strong> (<strong>{$approvedVersion->estimated_hours} hrs budget</strong>) by <strong>{$approverName}</strong> on <strong>{$approvedDate}</strong>.
                                    </p>
                                    <p class='text-gray-600 dark:text-gray-300 leading-relaxed'>
                                        <strong>Customer Feedback:</strong> {$feedback}
                                    </p>
                                </div>

                                <div>
                                    <h4 class='text-xs font-black tracking-widest text-gray-400 uppercase mb-3 flex items-center gap-2'>
                                        <svg class='w-4 h-4 text-emerald-600 dark:text-emerald-400' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'></path></svg>
                                        Approved Estimate Breakdown & Scope
                                    </h4>
                                    <div class='prose dark:prose-invert max-w-none text-sm text-gray-800 dark:text-gray-200 bg-white dark:bg-gray-900 border border-gray-200/90 dark:border-gray-800 rounded-2xl p-6 shadow-sm space-y-4 leading-relaxed [&_h3]:text-xs [&_h3]:font-black [&_h3]:tracking-wider [&_h3]:uppercase [&_h3]:text-emerald-700 [&_h3]:dark:text-emerald-300 [&_h3]:bg-emerald-50/80 [&_h3]:dark:bg-emerald-950/60 [&_h3]:px-3 [&_h3]:py-1.5 [&_h3]:rounded-lg [&_h3]:border [&_h3]:border-emerald-200/60 [&_h3]:dark:border-emerald-800/50 [&_h3]:mt-6 [&_h3]:mb-3 [&_h3]:inline-block [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:my-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:my-2 [&_li]:leading-relaxed [&_a]:text-emerald-600 [&_a]:underline [&_strong]:font-bold [&_strong]:text-gray-900 [&_strong]:dark:text-gray-100'>
                                        {$poBreakdown}
                                    </div>
                                </div>
                            </div>
                        ");

                        return [
                            Placeholder::make('formatted_approved_card')
                                ->hiddenLabel()
                                ->content($cardHtml),
                        ];
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }
}
