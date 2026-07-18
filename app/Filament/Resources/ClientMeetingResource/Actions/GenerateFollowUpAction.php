<?php

namespace App\Filament\Resources\ClientMeetingResource\Actions;

use App\Filament\Resources\ClientMeetingResource;
use App\Jobs\MeetingAgent\GenerateMeetingFollowUp;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

class GenerateFollowUpAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generateFollowUp';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Generate Follow-Up')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('warning')
            ->modalHeading('Generate Meeting Follow-Up')
            ->modalDescription('Provide your meeting notes and an optional transcript to generate an AI-powered follow-up.')
            ->modalSubmitActionLabel('Generate')
            ->form(function () {
                $record = $this->getRecord();
                $followUp = $record?->followUp;

                $fields = [];

                if (! ($followUp && (filled($followUp->raw_notes) || filled($followUp->transcript_text)))) {
                    $fields[] = Forms\Components\Textarea::make('notes')
                        ->label('Meeting Notes')
                        ->rows(8);

                    $fields[] = Forms\Components\Textarea::make('transcript')
                        ->label('Transcript (optional)')
                        ->rows(6);
                }

                $fields[] = Forms\Components\Select::make('model')
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
                    ->required(fn () => config('meeting_agent.ai.provider') === 'openrouter');

                return $fields;
            })
            ->modalFooterActionsAlignment(function () {
                $record = $this->getRecord();
                $followUp = $record?->followUp;

                if ($followUp && (filled($followUp->raw_notes) || filled($followUp->transcript_text))) {
                    if (config('meeting_agent.ai.provider') !== 'openrouter') {
                        return null;
                    }
                }

                return 'left';
            })
            ->requiresConfirmation(function () {
                $record = $this->getRecord();
                $followUp = $record?->followUp;

                if (config('meeting_agent.ai.provider') === 'openrouter') {
                    return false;
                }

                // If notes already saved, just show a quick confirmation instead of a form
                if ($followUp && (filled($followUp->raw_notes) || filled($followUp->transcript_text))) {
                    return true;
                }

                return false;
            })
            ->modalDescription(function () {
                $record = $this->getRecord();
                $followUp = $record?->followUp;

                if ($followUp && (filled($followUp->raw_notes) || filled($followUp->transcript_text))) {
                    $parts = [];
                    if (filled($followUp->raw_notes)) {
                        $parts[] = 'meeting notes';
                    }
                    if (filled($followUp->transcript_text)) {
                        $parts[] = 'transcript';
                    }
                    return 'Generate AI follow-up using the saved ' . implode(' and ', $parts) . '?';
                }

                return 'Provide your meeting notes and an optional transcript to generate an AI-powered follow-up.';
            })
            ->action(function (array $data) {
                $record = $this->getRecord();
                $followUp = $record?->followUp;

                // Use saved notes if available, otherwise use modal input
                $notes = $data['notes'] ?? '';
                $transcript = $data['transcript'] ?? '';

                if ($followUp) {
                    // Clear previous AI error so that the UI can start fresh and hide old errors
                    $followUp->update(['ai_error' => null]);

                    if (filled($followUp->raw_notes)) {
                        $notes = $followUp->raw_notes;
                    }
                    if (filled($followUp->transcript_text)) {
                        $transcript = $followUp->transcript_text;
                    }
                }

                // Set meeting status to FollowUpPending to initiate polling and show the loading indicator
                $record->update(['status' => \App\Enums\MeetingStatus::FollowUpPending]);

                GenerateMeetingFollowUp::dispatch(
                    clientMeetingId: $record->getKey(),
                    notes: $notes ?: '',
                    transcript: $transcript ?: null,
                    model: $data['model'] ?? null,
                );

                Notification::make()
                    ->title('Follow-up generation started')
                    ->body('This page will update when complete. Processing may take a minute.')
                    ->success()
                    ->send();

                $this->redirect(ClientMeetingResource::getUrl('view', ['record' => $record, 'tab' => 'follow-up-tab']));
            })
            ->disabled(function () {
                $provider = config('meeting_agent.ai.provider');
                if (! $provider) {
                    return true;
                }

                $keyMap = [
                    'openrouter' => config('meeting_agent.ai.openrouter_key'),
                    'openai'     => config('meeting_agent.ai.openai_key'),
                    'gemini'     => config('meeting_agent.ai.gemini_key'),
                ];

                return empty($keyMap[$provider] ?? null);
            })
            ->tooltip(fn () => $this->isDisabled() ? 'AI provider is not configured. Check meeting_agent.ai settings.' : null);
    }
}
