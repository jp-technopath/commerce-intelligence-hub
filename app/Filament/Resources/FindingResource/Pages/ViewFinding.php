<?php

namespace App\Filament\Resources\FindingResource\Pages;

use App\Enums\FindingStatus;
use App\Filament\Resources\FindingResource;
use App\Models\Finding;
use App\Models\InvestigationNote;
use App\Services\Intelligence\AIInvestigator;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewFinding extends ViewRecord
{
    protected static string $resource = FindingResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        $this->getRecord()->refresh();
        $this->getRecord()->load(['recommendations']);

        return FindingResource::infolist($infolist);
    }

    protected function getHeaderActions(): array
    {
        return [
            // ── Dig Deeper (AI Investigation) ────────────────────────────────
            Actions\Action::make('dig_deeper')
                ->label('Dig Deeper')
                ->icon('heroicon-o-magnifying-glass-plus')
                ->color('primary')
                ->visible(fn () => ! auth()->user()?->isClientOnly())
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
                ->visible(fn () => ! auth()->user()?->isClientOnly() && $this->record->status === FindingStatus::New)
                ->action(function (): void {
                    $this->record->update(['status' => FindingStatus::Investigating->value]);
                    $this->refreshFormData(['status']);
                    Notification::make()->title('Marked as investigating')->warning()->send();
                }),

            Actions\Action::make('mark_accepted')
                ->label('Accept')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->visible(fn () => ! auth()->user()?->isClientOnly() && $this->record->status === FindingStatus::Investigating)
                ->action(function (): void {
                    $this->record->update(['status' => FindingStatus::Accepted->value]);
                    $this->refreshFormData(['status']);
                    Notification::make()->title('Finding accepted')->success()->send();
                }),

            Actions\Action::make('mark_resolved')
                ->label('Mark Resolved')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => ! auth()->user()?->isClientOnly() && in_array($this->record->status, [
                    FindingStatus::Investigating, FindingStatus::Accepted,
                ]))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => FindingStatus::Resolved->value]);
                    $this->refreshFormData(['status']);
                    Notification::make()->title('Finding resolved — knowledge base updated')->success()->send();
                }),

            Actions\Action::make('generate_ai')
                ->label(fn () => ($this->record->metadata_json['ai_analysis_status'] ?? null) === 'failed' ? 'Retry AI Analysis' : 'Generate AI Analysis')
                ->icon('heroicon-o-sparkles')
                ->color('warning')
                ->visible(function () {
                    if (auth()->user()?->isClientOnly()) {
                        return false;
                    }
                    if ($this->record->recommendations()->exists()) {
                        return false;
                    }
                    $status = $this->record->metadata_json['ai_analysis_status'] ?? null;
                    $startedAt = $this->record->metadata_json['ai_analysis_started_at'] ?? null;
                    if ($status === 'pending' && $startedAt && now()->parse($startedAt)->diffInMinutes() < 10) {
                        return false;
                    }
                    return true;
                })
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
                ->modalDescription('This will gather live data from all connected integrations (GA4, Adobe Commerce, Clarity, New Relic, Klaviyo), cross-reference the data, and generate a comprehensive AI analysis in the background.')
                ->modalSubmitActionLabel('Start Analysis')
                ->action(function (array $data): void {
                    $meta = $this->record->metadata_json ?? [];
                    $meta['ai_analysis_status'] = 'pending';
                    $meta['ai_analysis_started_at'] = now()->toIso8601String();
                    unset($meta['ai_analysis_error']);
                    $this->record->update(['metadata_json' => $meta]);

                    \App\Jobs\Intelligence\GenerateAIAnalysis::dispatch(
                        finding: $this->record,
                        model: $data['model'] ?? null
                    );

                    Notification::make()
                        ->title('AI analysis started')
                        ->body('Live data is being gathered and cross-referenced in the background. This page will update automatically when complete.')
                        ->info()
                        ->duration(8000)
                        ->send();
                }),

            Actions\Action::make('ignore')
                ->label('Ignore')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => ! auth()->user()?->isClientOnly() && ! in_array($this->record->status, [
                    FindingStatus::Resolved, FindingStatus::Ignored,
                ]))
                ->action(function (): void {
                    $this->record->update(['status' => FindingStatus::Ignored->value]);
                    $this->redirectRoute('filament.admin.resources.findings.index');
                }),
        ];
    }
}
