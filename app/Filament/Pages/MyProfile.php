<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use App\Models\ConnectedAccount;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class MyProfile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user';
    protected static ?string $navigationLabel = 'My Profile';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.my-profile';

    public ?array $data = [];

    public function mount(): void
    {
        $user = auth()->user();
        $account = ConnectedAccount::where('user_id', $user->id)
            ->where('provider', 'google_workspace')
            ->first();

        $scannerSettings = [];
        if ($account) {
            $scannerSettings = $account->settings_json ?? [];
        }

        $this->form->fill([
            'name'                  => $user->name,
            'email'                 => $user->email,
            'scan_mode'             => $scannerSettings['scan_mode'] ?? 'auto',
            'include_keywords'      => $scannerSettings['include_keywords'] ?? ['#client', '#customer', '#customer-meeting'],
            'exclude_keywords'      => $scannerSettings['exclude_keywords'] ?? [],
            'skip_internal'         => $scannerSettings['skip_internal'] ?? false,
            'skip_without_external' => $scannerSettings['skip_without_external'] ?? false,
        ]);

        if (session()->has('error')) {
            Notification::make()
                ->title('Integration Error')
                ->body(session('error'))
                ->danger()
                ->persistent()
                ->send();
        }

        if (session()->has('success')) {
            Notification::make()
                ->title('Success')
                ->body(session('success'))
                ->success()
                ->send();
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Profile Information')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(table: 'users', column: 'email', ignorable: auth()->user())
                            ->maxLength(255),
                        TextInput::make('new_password')
                            ->label('New Password')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->nullable(),
                        TextInput::make('new_password_confirmation')
                            ->label('Confirm New Password')
                            ->password()
                            ->revealable()
                            ->same('new_password')
                            ->requiredWith('new_password')
                            ->nullable(),
                    ]),

                Section::make('Calendar Scanner Settings')
                    ->description('Configure your preferences for automated meeting scanning and preparation.')
                    ->schema([
                        Select::make('scan_mode')
                            ->label('Scanning Mode')
                            ->options([
                                'auto' => 'Auto-Scan (Recommended)',
                                'hashtag' => 'Hashtag / Keyword Only',
                            ])
                            ->live()
                            ->required(),

                        TagsInput::make('include_keywords')
                            ->label('Inclusion Hashtags / Keywords')
                            ->placeholder('Add hashtag...')
                            ->visible(fn (callable $get) => $get('scan_mode') === 'hashtag'),

                        TagsInput::make('exclude_keywords')
                            ->label('Exclusion Keywords / Patterns')
                            ->placeholder('Add keyword or /pattern/i...'),

                        Toggle::make('skip_internal')
                            ->label('Skip Internal Meetings')
                            ->helperText('Do not scan meetings where all attendees belong to your company domain(s).'),

                        Toggle::make('skip_without_external')
                            ->label('Skip Solo/Personal Events')
                            ->helperText('Do not scan meetings that have no external invitees.'),
                    ]),

                Section::make('Jira Integration')
                    ->description('Connect your individual Atlassian Jira account to generate prep materials on your behalf.')
                    ->schema([
                        Placeholder::make('jira_status')
                            ->label('Integration Status')
                            ->content(fn () => view('filament.components.jira-status-placeholder')),

                        Actions::make([
                            Action::make('connect_jira')
                                ->label('Connect Jira Account')
                                ->icon('heroicon-m-link')
                                ->color('primary')
                                ->url(route('jira.oauth.connect'))
                                ->visible(fn () => ! auth()->user()?->hasJira()),

                            Action::make('disconnect_jira')
                                ->label('Disconnect Jira Account')
                                ->icon('heroicon-m-trash')
                                ->color('danger')
                                ->url(route('jira.oauth.revoke'))
                                ->visible(fn () => auth()->user()?->hasJira()),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $user = auth()->user();
        $state = $this->form->getState();

        // Update User Profile
        $userUpdates = [
            'name'  => $state['name'],
            'email' => $state['email'],
        ];

        if (! empty($state['new_password'])) {
            $userUpdates['password'] = Hash::make($state['new_password']);
        }

        $user->update($userUpdates);

        // Update Google Workspace Settings
        $account = ConnectedAccount::where('user_id', $user->id)
            ->where('provider', 'google_workspace')
            ->first();

        if ($account) {
            $account->update([
                'settings_json' => [
                    'scan_mode'             => $state['scan_mode'] ?? 'auto',
                    'include_keywords'      => $state['include_keywords'] ?? [],
                    'exclude_keywords'      => $state['exclude_keywords'] ?? [],
                    'skip_internal'         => $state['skip_internal'] ?? false,
                    'skip_without_external' => $state['skip_without_external'] ?? false,
                ],
            ]);
        }

        Notification::make()
            ->title('Profile saved successfully.')
            ->success()
            ->send();
    }
}
