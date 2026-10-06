<?php

namespace App\Filament\Resources\SystemPromptResource\Pages;

use App\Filament\Resources\SystemPromptResource;
use App\Models\SystemPrompt;
use App\Services\SystemPrompt\PromptManager;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

/**
 * @property SystemPrompt $record
 */
class EditSystemPrompt extends EditRecord
{
    protected static string $resource = SystemPromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 1. Preview Draft Action
            Actions\Action::make('preview')
                ->label('Test / Preview Draft')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->modalHeading(fn () => "Preview Draft: {$this->record->name}")
                ->modalDescription('Inspect the system instructions and user prompt with sample variables substituted.')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('4xl')
                ->modalContent(function () {
                    $promptManager = app(PromptManager::class);

                    // Grab current working draft from live component data or record fallback
                    $systemPrompt = $this->data['draft_system_prompt'] ?? $this->record->draft_system_prompt ?? '';
                    $userTemplate = $this->data['draft_user_prompt_template'] ?? $this->record->draft_user_prompt_template ?? '';

                    $preview = $promptManager->previewDraft(
                        $this->record->key,
                        $systemPrompt,
                        $userTemplate
                    );

                    return view('filament.pages.system-prompt-preview-modal', [
                        'record'  => $this->record,
                        'preview' => $preview,
                    ]);
                }),

            // 2. Reset Draft to Factory Default
            Actions\Action::make('reset_default')
                ->label('Reset to Factory Default')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Reset Working Draft to Factory Default?')
                ->modalDescription('This will replace your working draft with the canonical prompt defined in the application codebase. This action cannot be undone unless you have published versions to rollback to.')
                ->action(function () {
                    $this->record->resetDraftToFactoryDefault(auth()->user());
                    $this->fillForm();

                    Notification::make()
                        ->title('Draft Reset')
                        ->body('Working draft has been restored to factory code defaults.')
                        ->success()
                        ->send();
                }),

            // 3. Publish Draft Action
            Actions\Action::make('publish')
                ->label('Publish Draft')
                ->icon('heroicon-o-arrow-up-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(fn () => "Publish New Version for {$this->record->name}")
                ->modalDescription('Publishing makes this draft the live active prompt used by production AI services immediately. An immutable version snapshot will be created.')
                ->form([
                    Forms\Components\Textarea::make('publish_notes')
                        ->label('Publish Notes / Changelog')
                        ->placeholder('e.g. Adjusted tone for commercial hospitality clients and added contact name fallback.')
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    try {
                        // Persist any unsaved edits in the form to the record first
                        $this->save(shouldRedirect: false);

                        $version = $this->record->publishDraft($data['publish_notes'] ?? null, auth()->user());

                        Notification::make()
                            ->title("Version {$version->version_number} Published Successfully")
                            ->body('This version is now live across all production AI operations.')
                            ->success()
                            ->send();

                        $this->fillForm();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Publish Blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['draft_updated_at'] = now();
        $data['draft_updated_by_user_id'] = auth()->id();

        return $data;
    }
}
