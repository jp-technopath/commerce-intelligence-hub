<?php

namespace App\Filament\Resources\ClientMeetingResource\Pages;

use App\Filament\Resources\ClientMeetingResource;
use App\Filament\Resources\ClientMeetingResource\Widgets\GoogleWorkspaceStatusWidget;
use App\Jobs\MeetingAgent\ScanUpcomingClientMeetings;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;

class ListClientMeetings extends ListRecords
{
    protected static string $resource = ClientMeetingResource::class;

    public function getTabs(): array
    {
        $tz = 'America/New_York';
        
        $todayStart = now($tz)->startOfDay()->utc();
        $todayEnd = now($tz)->endOfDay()->utc();
        
        $tomorrowStart = now($tz)->addDay()->startOfDay()->utc();
        $tomorrowEnd = now($tz)->addDay()->endOfDay()->utc();

        return [
            'today' => Tab::make("Today's Meetings")
                ->modifyQueryUsing(fn ($query) => $query->whereBetween('meeting_start_at', [$todayStart, $todayEnd])),
            'tomorrow' => Tab::make("Tomorrow's Meetings")
                ->modifyQueryUsing(fn ($query) => $query->whereBetween('meeting_start_at', [$tomorrowStart, $tomorrowEnd])),
            'all' => Tab::make("All Meetings"),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('scanCalendar')
                ->label('Scan Calendar')
                ->icon('heroicon-o-calendar-days')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Scan Google Calendar')
                ->modalDescription('This will scan your Google Calendar for upcoming customer meetings in the next 7 days.')
                ->modalSubmitActionLabel('Scan Now')
                ->action(function () {
                    /** @var \App\Models\User $user */
                    $user = auth()->user();

                    if (! $user->hasGoogleWorkspace()) {
                        Notification::make()
                            ->title('Google Workspace not connected')
                            ->body('Please connect your Google Workspace first.')
                            ->danger()
                            ->send();
                        return;
                    }

                    ScanUpcomingClientMeetings::dispatchSync();

                    Notification::make()
                        ->title('Calendar Scan Complete')
                        ->body('Successfully scanned and synced upcoming customer meetings from your Google Calendar.')
                        ->success()
                        ->send();
                })
                ->disabled(fn () => ! auth()->user()->hasGoogleWorkspace()),

            Actions\CreateAction::make(),
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            GoogleWorkspaceStatusWidget::class,
        ];
    }
}
