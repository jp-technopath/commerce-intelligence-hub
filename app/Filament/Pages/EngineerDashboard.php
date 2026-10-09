<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Engineer\EngineerActiveTasksWidget;
use App\Filament\Widgets\Engineer\EngineerMonthlyHoursWidget;
use App\Filament\Widgets\Engineer\EngineerWorkPlanWidget;
use App\Models\PmWorkItem;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class EngineerDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';
    protected static ?string $navigationLabel = 'Engineer Dashboard';
    protected static ?string $title = 'Engineer Operational Dashboard';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.engineer-dashboard';

    public ?int $selected_user_id = null;

    public function mount(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if ($user && $user->isClientOnly()) {
            redirect()->to(CustomerDashboard::getUrl());
        }

        $sessionUserId = session('engineer_dashboard_user_id');
        $this->selected_user_id = $sessionUserId ? (int) $sessionUserId : (int) Auth::id();

        $this->form->fill([
            'selected_user_id' => $this->selected_user_id,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selected_user_id')
                    ->label('')
                    ->placeholder('Switch engineer...')
                    ->options($this->getUserOptions())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        $userId = $state ? (int) $state : (int) Auth::id();
                        session(['engineer_dashboard_user_id' => $userId]);
                        $this->selected_user_id = $userId;
                        $this->dispatch('engineer-user-changed', userId: $userId);
                    }),
            ]);
    }

    public function resetToMe(): void
    {
        $myId = (int) Auth::id();
        session(['engineer_dashboard_user_id' => $myId]);
        $this->selected_user_id = $myId;
        $this->form->fill(['selected_user_id' => $myId]);
        $this->dispatch('engineer-user-changed', userId: $myId);
    }

    public function getUserOptions(): array
    {
        $currentAuthId = Auth::id();
        $assignedUserIds = PmWorkItem::whereNotNull('user_id')->distinct()->pluck('user_id');

        return User::query()
            ->where(function ($q) use ($assignedUserIds) {
                $q->whereIn('id', $assignedUserIds)
                  ->orWhere('is_admin', true)
                  ->orWhereDoesntHave('roles', fn ($r) => $r->where('name', 'Client'));
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => ! $u->isClientOnly() || $assignedUserIds->contains($u->id))
            ->mapWithKeys(function (User $u) use ($currentAuthId) {
                $suffix = ($u->id === $currentAuthId) ? ' (You)' : '';
                return [$u->id => "{$u->name}{$suffix}"];
            })
            ->toArray();
    }

    public function getSelectedUser(): ?User
    {
        $id = $this->selected_user_id ?? session('engineer_dashboard_user_id') ?? Auth::id();

        return User::find($id) ?? Auth::user();
    }

    public function getHeaderWidgets(): array
    {
        return [];
    }

    public function getFooterWidgets(): array
    {
        return [
            EngineerWorkPlanWidget::class,
            EngineerMonthlyHoursWidget::class,
            EngineerActiveTasksWidget::class,
        ];
    }

    public function getWidgetData(): array
    {
        return [
            'userId' => $this->selected_user_id,
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return ! $user->isClientOnly();
    }

    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return ! $user->isClientOnly();
    }
}
