<?php

namespace App\Filament\Widgets\Customer;

use App\Models\ClientMeeting;
use Filament\Forms\Components\Placeholder;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class MeetingsCommitmentsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected $listeners = ['client-changed' => '$refresh'];

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Meetings & Commitments';

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $clientId = $user?->client_id ?? session('current_client_id') ?? 1;

        // 1. Get 1 upcoming meeting ID
        $upcomingId = ClientMeeting::where('client_id', $clientId)
            ->where('meeting_start_at', '>=', now())
            ->orderBy('meeting_start_at', 'asc')
            ->value('id');

        // 2. Get last 2 past meeting IDs
        $pastIds = ClientMeeting::where('client_id', $clientId)
            ->where('meeting_start_at', '<', now())
            ->orderBy('meeting_start_at', 'desc')
            ->limit(2)
            ->pluck('id')
            ->toArray();

        $targetIds = array_filter(array_merge([$upcomingId], $pastIds));

        // If no upcoming meeting exists for this client, fall back to last 3 meetings
        if (empty($targetIds)) {
            $targetIds = ClientMeeting::where('client_id', $clientId)
                ->orderBy('meeting_start_at', 'desc')
                ->limit(3)
                ->pluck('id')
                ->toArray();
        }

        return $table
            ->paginated(false)
            ->contentGrid([
                'md' => 3,
                'xl' => 3,
            ])
            ->query(
                ClientMeeting::query()
                    ->whereIn('id', count($targetIds) > 0 ? $targetIds : [0])
                    ->orderBy('meeting_start_at', 'desc')
            )
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(function (ClientMeeting $record): string {
                                return $record->meeting_start_at >= now() ? 'UPCOMING SYNC' : 'PAST SYNC';
                            })
                            ->color(function (ClientMeeting $record): string {
                                return $record->meeting_start_at >= now() ? 'success' : 'gray';
                            }),

                        Tables\Columns\TextColumn::make('meeting_start_at')
                            ->dateTime('M d, H:i')
                            ->color('gray')
                            ->alignEnd(),
                    ]),

                    Tables\Columns\TextColumn::make('title')
                        ->weight('bold')
                        ->size(Tables\Columns\TextColumn\TextColumnSize::Large),

                    Tables\Columns\TextColumn::make('action_items_count')
                        ->counts('actionItems')
                        ->formatStateUsing(fn ($state) => "📌 {$state} Action Items")
                        ->color('primary'),
                ])->space(3),
            ])
            ->actions([
                Action::make('view_meeting_details')
                    ->label('View Details')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (ClientMeeting $record): string => "/admin/client-meetings/{$record->id}?tab=-summary-tab")
                    ->openUrlInNewTab(),
            ]);
    }
}
