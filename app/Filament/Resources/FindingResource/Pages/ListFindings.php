<?php

namespace App\Filament\Resources\FindingResource\Pages;

use App\Filament\Resources\FindingResource;
use App\Models\Client;
use App\Services\Intelligence\AIAnalyst;
use App\Services\Intelligence\ChangeDetectionEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListFindings extends ListRecords
{
    protected static string $resource = FindingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_analysis')
                ->label('Run Analysis')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('client_id')
                        ->label('Client')
                        ->options(Client::where('status', 'active')->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->helperText('Select a client to run change detection and AI analysis for.'),
                    Forms\Components\Toggle::make('include_ai')
                        ->label('Generate AI recommendations for new findings')
                        ->default(true),
                ])
                ->modalHeading('Run Intelligence Analysis')
                ->modalDescription('Analyzes recent metric changes and generates findings with severity and recommendations.')
                ->modalSubmitActionLabel('Run Analysis')
                ->action(function (array $data): void {
                    $client = Client::findOrFail($data['client_id']);
                    $engine = new ChangeDetectionEngine();

                    $newFindings = $engine->run($client);

                    // Optionally run AI on new findings
                    if ($data['include_ai'] && $newFindings > 0) {
                        $analyst = new AIAnalyst();

                        $client->findings()
                            ->whereDoesntHave('recommendations')
                            ->where('detected_at', '>=', now()->subHours(1))
                            ->get()
                            ->each(fn ($finding) => $analyst->analyse($finding));
                    }

                    if ($newFindings > 0) {
                        Notification::make()
                            ->title("Analysis Complete")
                            ->body("{$newFindings} new finding(s) detected for {$client->name}." . ($data['include_ai'] ? ' AI analysis generated.' : ''))
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
                ->requiresConfirmation()
                ->modalHeading('Refresh Findings for All Clients')
                ->modalDescription('This will run change detection across all active clients. This may take a few minutes.')
                ->action(function (): void {
                    $clients = Client::where('status', 'active')->get();
                    $engine  = new ChangeDetectionEngine();
                    $analyst = new AIAnalyst();
                    $total   = 0;

                    foreach ($clients as $client) {
                        try {
                            $newFindings = $engine->run($client);
                            $total += $newFindings;

                            if ($newFindings > 0) {
                                $client->findings()
                                    ->whereDoesntHave('recommendations')
                                    ->where('detected_at', '>=', now()->subHours(1))
                                    ->get()
                                    ->each(fn ($f) => $analyst->analyse($f));
                            }
                        } catch (\Exception $e) {
                            // Continue with other clients
                        }
                    }

                    Notification::make()
                        ->title('Analysis Complete')
                        ->body("{$total} new finding(s) across {$clients->count()} clients.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
