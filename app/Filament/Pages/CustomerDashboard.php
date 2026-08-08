<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Customer\ApprovedTasksWidget;
use App\Filament\Widgets\Customer\CustomerKpiWidget;
use App\Filament\Widgets\Customer\HoursCapacityWidget;
use App\Filament\Widgets\Customer\MeetingsCommitmentsWidget;
use App\Filament\Widgets\Customer\NeedsAttentionWidget;
use App\Models\Client;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class CustomerDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Customer Dashboard';

    protected static ?string $title = 'Technopath Forge — Customer Dashboard';

    protected static ?string $navigationGroup = 'Customer Portal';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.customer-dashboard';

    public ?int $selected_client_id = null;

    public function mount(): void
    {
        $this->selected_client_id = session('current_client_id') ?? Client::first()?->id ?? 1;
        session(['current_client_id' => $this->selected_client_id]);

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
                    ->options(Client::all()->pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        session(['current_client_id' => (int) $state]);
                        $this->dispatch('client-changed');
                    }),
            ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getFooterWidgets(): array
    {
        return [
            NeedsAttentionWidget::class,
            MeetingsCommitmentsWidget::class,
            ApprovedTasksWidget::class,
        ];
    }
}
