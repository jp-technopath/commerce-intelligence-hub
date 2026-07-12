<?php

namespace App\Filament\Resources\ClientMeetingResource\Actions;

use App\Services\MeetingAgent\GmailService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

class CreateGmailDraftAction extends Action
{
    protected string $draftType = 'prep';

    public static function getDefaultName(): ?string
    {
        return 'createGmailDraft';
    }

    public function draftType(string $type): static
    {
        $this->draftType = $type;

        return $this;
    }

    public static function makeForPrep(): static
    {
        return static::make('createPrepDraft')
            ->draftType('prep');
    }

    public static function makeForFollowUp(): static
    {
        return static::make('createFollowUpDraft')
            ->draftType('followup');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(fn () => $this->draftType === 'prep' ? 'Create Prep Email Draft' : 'Create Follow-Up Email Draft')
            ->icon('heroicon-o-envelope')
            ->color('success')
            ->modalHeading(fn () => $this->draftType === 'prep' ? 'Create Prep Email Draft' : 'Create Follow-Up Email Draft')
            ->modalDescription('Review the email content below. A Gmail draft will be created in your mailbox — it will NOT be sent automatically.')
            ->modalSubmitActionLabel('Create Draft')
            ->form([
                Forms\Components\TextInput::make('recipient_email')
                    ->label('Recipient Email')
                    ->email()
                    ->required(),

                Forms\Components\TagsInput::make('cc_emails')
                    ->label('CC')
                    ->placeholder('Add CC emails')
                    ->nestedRecursiveRules(['email']),

                Forms\Components\TextInput::make('subject')
                    ->label('Subject')
                    ->required(),

                Forms\Components\Textarea::make('email_body')
                    ->label('Email Body')
                    ->required()
                    ->rows(12)
                    ->extraAlpineAttributes([
                        'x-init' => '$nextTick(() => { if ($wire.mountedActionsData && $wire.mountedActionsData[0] && $wire.mountedActionsData[0].email_body) { $el.value = $wire.mountedActionsData[0].email_body; $el.dispatchEvent(new Event("input")); } })',
                    ]),

                Forms\Components\Checkbox::make('confirmed')
                    ->label('I have reviewed this email and want to create a Gmail draft')
                    ->required()
                    ->accepted()
                    ->validationMessages([
                        'accepted' => 'You must confirm you have reviewed the email before creating a draft.',
                    ]),
            ])
            ->mountUsing(function (?Forms\Form $form) {
                if (! $form) {
                    return;
                }

                $record = $this->getRecord();

                if ($this->draftType === 'prep') {
                    $subject = $record->prep?->effectiveSubject() ?? '';
                    $body = $record->prep?->effectiveBody() ?? '';
                } else {
                    $subject = $record->followUp?->effectiveSubject() ?? '';
                    $body = $record->followUp?->effectiveBody() ?? '';
                }

                $plainBody = $this->htmlToPlainText($body);

                // Auto-fill emails from meeting attendees
                $externalEmails = collect($record->external_attendees ?? [])
                    ->pluck('email')
                    ->filter()
                    ->values()
                    ->toArray();

                $internalEmails = collect($record->internal_attendees ?? [])
                    ->pluck('email')
                    ->filter()
                    ->reject(fn ($e) => $e === auth()->user()?->email) // exclude current user
                    ->values()
                    ->toArray();

                // First external attendee → recipient, rest + internal → CC
                $recipientEmail = $externalEmails[0] ?? '';
                $ccEmails = array_merge(
                    array_slice($externalEmails, 1),
                    $internalEmails,
                );

                $form->fill([
                    'recipient_email' => $recipientEmail,
                    'cc_emails'       => $ccEmails,
                    'subject'         => $subject,
                    'email_body'      => $plainBody,
                ]);
            })
            ->action(function (array $data) {
                $record = $this->getRecord();

                /** @var \App\Models\User $user */
                $user = auth()->user();

                // Double-check scope (belt-and-suspenders)
                $gmailScope = config('meeting_agent.google.scopes.gmail_compose');
                if (! $user->hasMeetingAgentScope($gmailScope)) {
                    Notification::make()
                        ->title('Missing Gmail permission')
                        ->body('Your Google Workspace account does not have the Gmail compose permission. Please reconnect.')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $gmailService = new GmailService($user);

                    $draftId = $gmailService->createDraft(
                        to: $data['recipient_email'],
                        subject: $data['subject'],
                        body: $data['email_body'],
                        cc: $data['cc_emails'] ?? [],
                    );

                    // Store the draft ID on the appropriate record
                    if ($this->draftType === 'prep' && $record->prep) {
                        $record->prep->update(['gmail_draft_id' => $draftId]);
                    } elseif ($this->draftType === 'followup' && $record->followUp) {
                        $record->followUp->update(['gmail_draft_id' => $draftId]);
                    }

                    Notification::make()
                        ->title('Gmail draft created')
                        ->body('The draft has been created in your Gmail mailbox. Open Gmail to review and send.')
                        ->success()
                        ->send();

                } catch (\RuntimeException $e) {
                    Notification::make()
                        ->title('Draft creation failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            })
            ->hidden(function () {
                $record = $this->getRecord();

                if ($this->draftType === 'prep') {
                    return ! $record->prep || ! $record->prep->effectiveSubject();
                }

                return ! $record->followUp || ! $record->followUp->effectiveSubject();
            })
            ->disabled(function () {
                /** @var \App\Models\User $user */
                $user = auth()->user();
                $gmailScope = config('meeting_agent.google.scopes.gmail_compose');

                return ! $user->hasMeetingAgentScope($gmailScope);
            })
            ->tooltip(function () {
                if ($this->isDisabled()) {
                    return 'Your Google Workspace account does not have the Gmail compose permission. Please reconnect.';
                }

                return null;
            });
    }

    private function htmlToPlainText(string $html): string
    {
        if (empty($html) || ! str_contains($html, '<')) {
            return $html;
        }

        $text = $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/li>/i', "\n", $text);
        $text = preg_replace('/<li>/i', "• ", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
