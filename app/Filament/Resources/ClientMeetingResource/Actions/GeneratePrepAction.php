<?php

namespace App\Filament\Resources\ClientMeetingResource\Actions;

use App\Filament\Resources\ClientMeetingResource;
use App\Jobs\MeetingAgent\GenerateMeetingPrep;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

class GeneratePrepAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generatePrep';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Generate Prep')
            ->icon('heroicon-o-document-text')
            ->color('primary')
            ->modalHeading('Generate Meeting Prep')
            ->modalDescription('Pull Jira data and generate an AI-powered meeting prep package.')
            ->modalSubmitActionLabel('Generate')
            ->form(fn () => [
                Forms\Components\TextInput::make('jira_project_key')
                    ->label('Jira Project Key')
                    ->required()
                    ->default(fn () => $this->getRecord()?->project_key),

                Forms\Components\Textarea::make('custom_jql')
                    ->label('Custom JQL Override')
                    ->rows(3)
                    ->helperText('Leave empty to use the default project query.'),

                Forms\Components\DatePicker::make('since_date')
                    ->label('Changes Since')
                    ->helperText('Only include Jira issues updated since this date.'),

                Forms\Components\Select::make('model')
                    ->label('AI Model')
                    ->options(function () {
                        $models = config('meeting_agent.ai.openrouter_models', []);
                        $options = [];
                        foreach ($models as $model) {
                            $cleanModel = ltrim($model, '~');
                            $options[$cleanModel] = $cleanModel;
                        }
                        if (! isset($options['openai/gpt-5.6-luna-pro'])) {
                            $options['openai/gpt-5.6-luna-pro'] = 'openai/gpt-5.6-luna-pro';
                        }
                        return $options;
                    })
                    ->default(function () {
                        $models = config('meeting_agent.ai.openrouter_models', []);
                        foreach ($models as $model) {
                            $clean = ltrim($model, '~');
                            if (str_contains($clean, 'gpt-5.6-luna-pro')) {
                                return $clean;
                            }
                        }
                        return 'openai/gpt-5.6-luna-pro';
                    })
                    ->disableOptionWhen(fn (string $value): bool => ! str_contains($value, 'gpt-5.6-luna-pro'))
                    ->in(fn (Forms\Components\Select $component): array => array_keys($component->getEnabledOptions()))
                    ->visible(fn () => config('meeting_agent.ai.provider') === 'openrouter')
                    ->required(fn () => config('meeting_agent.ai.provider') === 'openrouter'),
            ])
            ->action(function (array $data) {
                $record = $this->getRecord();

                // Clear previous AI error so that the UI can start fresh and enable polling
                if ($record->prep) {
                    $record->prep->update(['ai_error' => null]);
                }

                // Set meeting status to PrepPending to initiate polling and show the loading indicator
                $record->update(['status' => \App\Enums\MeetingStatus::PrepPending]);

                GenerateMeetingPrep::dispatch(
                    clientMeetingId: $record->getKey(),
                    jiraProjectKey: $data['jira_project_key'],
                    customJql: $data['custom_jql'] ?: null,
                    sinceDateString: $data['since_date'] ?: null,
                    model: $data['model'] ?? null,
                );

                Notification::make()
                    ->title('Prep generation started')
                    ->body('This page will update when complete. Processing may take a minute.')
                    ->success()
                    ->send();

                $this->redirect(ClientMeetingResource::getUrl('view', ['record' => $record, 'tab' => 'pre-meeting-prep-tab']));
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
