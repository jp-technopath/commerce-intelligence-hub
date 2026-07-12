<?php

namespace App\Filament\Resources\FindingResource\Pages;

use App\Enums\FindingStatus;
use App\Filament\Resources\FindingResource;
use App\Models\Finding;
use App\Models\InvestigationNote;
use App\Services\Intelligence\AIInvestigator;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewFinding extends ViewRecord
{
    protected static string $resource = FindingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ── Dig Deeper (AI Investigation) ────────────────────────────────
            Actions\Action::make('dig_deeper')
                ->label('Dig Deeper')
                ->icon('heroicon-o-magnifying-glass-plus')
                ->color('primary')
                ->modalHeading('🔍 Dig Deeper — AI Investigation')
                ->modalDescription('Ask the AI to investigate this finding using data from GA4, Adobe Commerce, Clarity, deployments, and the knowledge base.')
                ->modalWidth('xl')
                ->form([
                    Forms\Components\Placeholder::make('finding_context')
                        ->label('Finding')
                        ->content(fn () => "**{$this->record->title}** — {$this->record->severity->label()} severity, detected {$this->record->detected_at?->diffForHumans()}"),

                    Forms\Components\Textarea::make('instructions')
                        ->label('Investigation Instructions')
                        ->placeholder("Examples:\n• Check if this correlates with mobile traffic changes\n• Look for similar patterns in the last 30 days\n• Is this related to any recent deployments?\n• Compare conversion rates by traffic source\n• What pages have the highest friction scores?")
                        ->rows(5)
                        ->required()
                        ->helperText('Be specific about what you want to investigate. The AI will use data from all connected sources.'),
                ])
                ->action(function (array $data): void {
                    $investigator = new AIInvestigator();

                    Notification::make()
                        ->title('Investigation started...')
                        ->body('Checking data completeness and fetching fresh data from APIs if needed. Then running AI analysis across all sources.')
                        ->info()
                        ->send();

                    $result = $investigator->investigate($this->record, $data['instructions']);

                    if (! $result) {
                        Notification::make()
                            ->title('Investigation failed')
                            ->body('Check that GEMINI_API_KEY is configured in .env')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Save as investigation note — store the full AI response
                    $fullResponse = $result['full_text'] ?? $result['analysis'] ?? '';
                    InvestigationNote::create([
                        'finding_id'     => $this->record->id,
                        'user_id'        => auth()->id(),
                        'root_cause'     => "**AI Investigation** (prompted: \"{$data['instructions']}\")\n\n{$fullResponse}",
                        'fix_implemented' => null,
                        'outcome'        => null,
                        'lessons_learned' => null,
                    ]);

                    // Auto-set status to investigating if still new
                    if ($this->record->status === FindingStatus::New) {
                        $this->record->update(['status' => FindingStatus::Investigating->value]);
                    }

                    Notification::make()
                        ->title('Investigation complete')
                        ->body('AI analysis has been saved as an investigation note. Check the Investigation Notes tab below.')
                        ->success()
                        ->duration(8000)
                        ->send();

                    $this->redirect(FindingResource::getUrl('view', ['record' => $this->record]));
                }),

            // ── Status workflow actions ───────────────────────────────────────
            Actions\Action::make('mark_investigating')
                ->label('Investigate')
                ->icon('heroicon-o-eye')
                ->color('warning')
                ->visible(fn () => $this->record->status === FindingStatus::New)
                ->action(function (): void {
                    $this->record->update(['status' => FindingStatus::Investigating->value]);
                    $this->refreshFormData(['status']);
                    Notification::make()->title('Marked as investigating')->warning()->send();
                }),

            Actions\Action::make('mark_accepted')
                ->label('Accept')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->visible(fn () => $this->record->status === FindingStatus::Investigating)
                ->action(function (): void {
                    $this->record->update(['status' => FindingStatus::Accepted->value]);
                    $this->refreshFormData(['status']);
                    Notification::make()->title('Finding accepted')->success()->send();
                }),

            Actions\Action::make('mark_resolved')
                ->label('Mark Resolved')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [
                    FindingStatus::Investigating, FindingStatus::Accepted,
                ]))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => FindingStatus::Resolved->value]);
                    $this->refreshFormData(['status']);
                    Notification::make()->title('Finding resolved — knowledge base updated')->success()->send();
                }),

            Actions\Action::make('generate_ai')
                ->label('Generate AI Analysis')
                ->icon('heroicon-o-sparkles')
                ->color('warning')
                ->visible(fn () => ! $this->record->recommendations()->exists())
                ->form(fn () => [
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
                        ->visible(fn () => config('meeting_agent.ai.provider') === 'openrouter')
                        ->required(fn () => config('meeting_agent.ai.provider') === 'openrouter'),
                ])
                ->modalHeading('🧠 Generate AI Analysis')
                ->modalDescription('This will gather live data from all connected integrations (GA4, Adobe Commerce, Clarity, New Relic, Klaviyo), cross-reference the data, and generate a comprehensive AI analysis. This may take 30-60 seconds.')
                ->modalSubmitActionLabel('Start Analysis')
                ->action(function (array $data): void {
                    \App\Jobs\Intelligence\GenerateAIAnalysis::dispatchSync(
                        finding: $this->record,
                        model: $data['model'] ?? null
                    );

                    if ($this->record->fresh()->recommendations()->exists()) {
                        Notification::make()
                            ->title('AI analysis generated')
                            ->body('Live data from all integrations was cross-referenced to produce the analysis. Check the AI Recommendations section below.')
                            ->success()
                            ->duration(8000)
                            ->send();
                        $this->redirect(FindingResource::getUrl('view', ['record' => $this->record]));
                    } else {
                        Notification::make()
                            ->title('AI analysis failed')
                            ->body('Check that your AI provider API key is configured correctly and integrations are connected.')
                            ->warning()
                            ->send();
                    }
                }),

            Actions\Action::make('ignore')
                ->label('Ignore')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => ! in_array($this->record->status, [
                    FindingStatus::Resolved, FindingStatus::Ignored,
                ]))
                ->action(function (): void {
                    $this->record->update(['status' => FindingStatus::Ignored->value]);
                    $this->redirectRoute('filament.admin.resources.findings.index');
                }),
        ];
    }
}
