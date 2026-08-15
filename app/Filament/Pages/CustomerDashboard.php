<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasScopedClientFilter;
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

use App\Filament\Widgets\Customer\ReadyForDeploymentWidget;

class CustomerDashboard extends Page implements HasForms
{
    use InteractsWithForms;
    use HasScopedClientFilter;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Customer Dashboard';

    protected static ?string $title = 'Technopath Forge — Customer Dashboard';

    protected static ?string $navigationGroup = 'Customer Portal';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.customer-dashboard';

    public ?int $selected_client_id = null;

    public function mount(): void
    {
        $this->selected_client_id = $this->resolveScopedClientId();

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
            ReadyForDeploymentWidget::class,
        ];
    }
}
