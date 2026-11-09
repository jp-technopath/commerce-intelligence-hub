<?php

namespace App\Filament\Resources\FindingResource\Pages;

use App\Enums\FindingPillar;
use App\Enums\FindingStatus;
use App\Filament\Resources\FindingResource;
use App\Filament\Widgets\BusinessInitiativesOverviewWidget;
use App\Jobs\Intelligence\GenerateAIAnalysis;
use App\Models\Client;
use App\Models\Finding;
use App\Services\Intelligence\ChangeDetectionEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListFindings extends ListRecords
{
    protected static string $resource = FindingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            BusinessInitiativesOverviewWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return 1;
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'initiatives';
    }

    public function getTabs(): array
    {
        return [
            'initiatives' => Tab::make('Strategic Initiatives')
                ->icon('heroicon-m-briefcase')
                ->badge(Finding::whereNotIn('status', [FindingStatus::Resolved->value, FindingStatus::Dismissed->value])
                    ->where(fn($q) => $q->whereNull('finding_pillar')->orWhere('finding_pillar', '!=', FindingPillar::TechnicalHealth->value))
                    ->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn($q) => $q->whereNull('finding_pillar')->orWhere('finding_pillar', '!=', FindingPillar::TechnicalHealth->value))
                    ->whereNotIn('status', [FindingStatus::Resolved->value, FindingStatus::Dismissed->value])),

            'all_commercial' => Tab::make('All Commercial Opportunities')
                ->icon('heroicon-m-chart-bar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn($q) => $q->whereNull('finding_pillar')->orWhere('finding_pillar', '!=', FindingPillar::TechnicalHealth->value))),

            'technical_health' => Tab::make('Technical & Data Health')
                ->icon('heroicon-m-wrench-screwdriver')
                ->badge(Finding::where('finding_pillar', FindingPillar::TechnicalHealth->value)
                    ->whereNotIn('status', [FindingStatus::Resolved->value, FindingStatus::Dismissed->value])
                    ->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('finding_pillar', FindingPillar::TechnicalHealth->value)),

            'archived' => Tab::make('Resolved / Dismissed')
                ->icon('heroicon-m-archive-box')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [FindingStatus::Resolved->value, FindingStatus::Dismissed->value])),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_analysis')
                ->label('Run Analysis')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->visible(fn () => ! auth()->user()?->isClientOnly())
                ->form([
                    Forms\Components\Select::make('client_id')
                        ->label('Client')
                        ->options(Client::where('status', 'active')->external()->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->helperText('Select a client to run change detection and AI analysis for.'),
                    Forms\Components\Toggle::make('include_ai')
                        ->label('Generate AI recommendations for new findings')
                        ->live()
                        ->default(true),
                    Forms\Components\Select::make('model')
                        ->label('AI Model')
                        ->options(function () {
                            $models = config('meeting_agent.ai.openrouter_models', []);
                            $options = [];
                            foreach ($models as $model) {
                                $cleanModel = ltrim($model, '~');
                                $label = $model;
                                if (str_starts_with($model, '~')) {
                                    $label = $cleanModel . ' (Recommended)';
                                }
                                $options[$model] = $label;
                            }
                            return $options;
                        })
                        ->default(function () {
                            $models = config('meeting_agent.ai.openrouter_models', []);
                            foreach ($models as $model) {
                                if (str_starts_with($model, '~')) {
                                    return $model;
                                }
                            }
                            return config('meeting_agent.ai.openrouter_model', 'openai/gpt-4o');
                        })
                        ->visible(fn (Forms\Get $get) => $get('include_ai') && config('meeting_agent.ai.provider') === 'openrouter')
                        ->required(fn (Forms\Get $get) => $get('include_ai') && config('meeting_agent.ai.provider') === 'openrouter'),
                ])
                ->modalHeading('Run Intelligence Analysis')
                ->modalDescription('Analyzes recent metric changes and generates findings with severity and recommendations.')
                ->modalSubmitActionLabel('Run Analysis')
                ->action(function (array $data): void {
                    $client = Client::findOrFail($data['client_id']);
                    $engine = new ChangeDetectionEngine();

                    $newFindings = $engine->run($client);

                    // Optionally dispatch AI analysis for findings in the background
                    $pendingFindings = $client->findings()
                        ->whereDoesntHave('recommendations')
                        ->where('detected_at', '>=', now()->subHours(1))
                        ->get();

                    $aiDispatched = false;
                    if ($data['include_ai'] && $pendingFindings->isNotEmpty()) {
                        $pendingFindings->each(fn ($finding) => GenerateAIAnalysis::dispatch($finding, $data['model'] ?? null));
                        $aiDispatched = true;
                    }

                    if ($newFindings > 0) {
                        Notification::make()
                            ->title("Analysis Complete")
                            ->body("{$newFindings} new finding(s) detected for {$client->name}." . ($aiDispatched ? ' AI analysis dispatched in the background.' : ''))
                            ->success()
                            ->send();
                    } elseif ($aiDispatched) {
                        Notification::make()
                            ->title("AI Analysis Queued")
                            ->body("Dispatched AI analysis in the background for {$pendingFindings->count()} finding(s) on {$client->name}.")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('No New Findings')
                            ->body("No significant metric changes detected for {$client->name}.")
                            ->info()
                            ->send();
                    }
                }),

            Actions\Action::make('refresh_all')
                ->label('Refresh All Clients')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => ! auth()->user()?->isClientOnly())
                ->modalHeading('Refresh Findings for All Clients')
                ->modalDescription('This will run change detection across all active clients to find signals and anomalies in the synced data. This may take a few minutes.')
                ->modalSubmitActionLabel('Refresh All')
                ->action(function (): void {
                    $clients = Client::where('status', 'active')->external()->get();
                    $engine  = new ChangeDetectionEngine();
                    $total   = 0;

                    foreach ($clients as $client) {
                        try {
                            $newFindings = $engine->run($client);
                            $total += $newFindings;
                        } catch (\Exception $e) {
                            // Continue with other clients
                        }
                    }

                    Notification::make()
                        ->title('Refresh Complete')
                        ->body("{$total} new signal finding(s) detected across {$clients->count()} clients.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
