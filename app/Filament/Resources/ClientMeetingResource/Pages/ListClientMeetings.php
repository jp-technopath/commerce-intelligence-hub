<?php

namespace App\Filament\Resources\ClientMeetingResource\Pages;

use App\Filament\Resources\ClientMeetingResource;
use App\Filament\Resources\ClientMeetingResource\Widgets\GoogleWorkspaceStatusWidget;
use App\Jobs\MeetingAgent\ScanUpcomingClientMeetings;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Filament\Forms;

class ListClientMeetings extends ListRecords
{
    protected static string $resource = ClientMeetingResource::class;

    public function getTabs(): array
    {
        $tz = config('app.timezone', 'UTC');
        
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

            Actions\Action::make('scannerSettings')
                ->label('Scanner Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('secondary')
                ->form([
                    Forms\Components\Select::make('scan_mode')
                        ->label('Scanning Mode')
                        ->options([
                            'auto' => 'Auto-Scan (All external meetings and meetings with known clients)',
                            'hashtag' => 'Hashtag/Keyword Only (Only scan meetings containing specific inclusion keywords)',
                        ])
                        ->default('auto')
                        ->required()
                        ->live(),

                    Forms\Components\TagsInput::make('include_keywords')
                        ->label('Inclusion Keywords / Hashtags')
                        ->placeholder('Add hashtag or keyword, e.g. #client')
                        ->default(['#client', '#customer', '#customer-meeting'])
                        ->helperText('Only meetings containing these keywords will be scanned if Hashtag Mode is selected.')
                        ->visible(fn (Forms\Get $get) => $get('scan_mode') === 'hashtag'),

                    Forms\Components\TagsInput::make('exclude_keywords')
                        ->label('Exclusion Keywords / Regex')
                        ->placeholder('Add keyword to exclude, e.g. personal, dentist')
                        ->default(['standup', 'stand-up', 'daily sync', 'sprint planning', 'retro', 'retrospective', '1:1', '1-on-1', 'internal', 'team meeting', 'holiday', 'out of office', 'ooo', 'lunch', 'personal', 'private'])
                        ->helperText('Meetings containing any of these keywords in the title will be automatically skipped.'),

                    Forms\Components\Toggle::make('skip_internal')
                        ->label('Skip Internal Meetings')
                        ->default(true)
                        ->helperText('Automatically skip meetings where all attendees share your company email domains.'),

                    Forms\Components\Toggle::make('skip_without_external')
                        ->label('Skip Meetings Without External Attendees')
                        ->default(false)
                        ->helperText('Skip meetings that have no external participants (e.g. personal notes, solo timeblocks).'),
                ])
                ->mountUsing(function (Forms\Form $form) {
                    $account = auth()->user()->googleWorkspaceAccount();
                    $settings = $account?->settings_json ?? [];

                    $form->fill([
                        'scan_mode'             => $settings['scan_mode'] ?? 'auto',
                        'include_keywords'      => $settings['include_keywords'] ?? ['#client', '#customer', '#customer-meeting'],
                        'exclude_keywords'      => $settings['exclude_keywords'] ?? ['standup', 'stand-up', 'daily sync', 'sprint planning', 'retro', 'retrospective', '1:1', '1-on-1', 'internal', 'team meeting', 'holiday', 'out of office', 'ooo', 'lunch', 'personal', 'private'],
                        'skip_internal'         => $settings['skip_internal'] ?? true,
                        'skip_without_external' => $settings['skip_without_external'] ?? false,
                    ]);
                })
                ->action(function (array $data) {
                    $account = auth()->user()->googleWorkspaceAccount();
                    if (! $account) {
                        Notification::make()
                            ->title('Google Workspace not connected')
                            ->body('Please connect your Google Workspace first.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $settings = $account->settings_json ?? [];
                    $settings = array_merge($settings, [
                        'scan_mode'             => $data['scan_mode'],
                        'include_keywords'      => $data['include_keywords'],
                        'exclude_keywords'      => $data['exclude_keywords'],
                        'skip_internal'         => $data['skip_internal'],
                        'skip_without_external' => $data['skip_without_external'],
                    ]);

                    $account->update(['settings_json' => $settings]);

                    Notification::make()
                        ->title('Scanner Settings Saved')
                        ->body('Your calendar scanner preferences have been successfully updated.')
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
