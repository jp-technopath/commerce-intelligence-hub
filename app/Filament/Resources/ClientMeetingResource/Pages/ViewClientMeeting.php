<?php

namespace App\Filament\Resources\ClientMeetingResource\Pages;

use App\Enums\ActionItemSource;
use App\Enums\ActionItemStatus;
use App\Filament\Resources\ClientMeetingResource;
use App\Filament\Resources\ClientMeetingResource\Actions\CreateGmailDraftAction;
use App\Filament\Resources\ClientMeetingResource\Actions\GenerateFollowUpAction;
use App\Filament\Resources\ClientMeetingResource\Actions\GeneratePrepAction;
use App\Models\MeetingActionItem;
use App\Services\MeetingAgent\GoogleDriveService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Components\Actions as InfolistActions;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewClientMeeting extends ViewRecord
{
    protected static string $resource = ClientMeetingResource::class;

    // ── Header Actions ──────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            GeneratePrepAction::make(),
            GenerateFollowUpAction::make(),
            Actions\EditAction::make(),
        ];
    }

    public function hydrate(): void
    {
        if ($this->record) {
            $this->record->refresh();
        }
    }

    // ── Infolist ────────────────────────────────────────────────────────

    public function infolist(Infolist $infolist): Infolist
    {
        // Freshly reload the record and its relationships from the database on every render/polling request
        $this->getRecord()->refresh();

        return $infolist
            ->schema([
                ViewEntry::make('polling_indicator')
                    ->view('filament.resources.client-meeting-resource.entries.polling-indicator')
                    ->columnSpanFull(),

                Tabs::make('MeetingTabs')
                    ->tabs([
                        $this->meetingInfoTab(),
                        $this->preMeetingPrepTab(),
                        $this->meetingNotesTab(),
                        $this->followUpTab(),
                    ])
                    ->columnSpanFull()
                    ->contained()
                    ->persistTabInQueryString(),
            ]);
    }

    // ── Tab 1: Meeting Info ─────────────────────────────────────────────

    private function meetingInfoTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Meeting Info')
            ->icon('heroicon-o-information-circle')
            ->schema([
                Section::make('Details')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('title')
                                    ->label('Title')
                                    ->columnSpanFull()
                                    ->weight('bold')
                                    ->size(TextEntry\TextEntrySize::Large),

                                TextEntry::make('meeting_start_at')
                                    ->label('Start')
                                    ->dateTime('M j, Y g:i A'),

                                TextEntry::make('meeting_end_at')
                                    ->label('End')
                                    ->dateTime('M j, Y g:i A'),

                                TextEntry::make('timezone')
                                    ->label('Timezone'),
                            ]),
                    ]),

                Section::make('Assignment')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('client.name')
                                    ->label('Client')
                                    ->badge()
                                    ->color('primary')
                                    ->default('Unmapped'),

                                TextEntry::make('project_key')
                                    ->label('Jira Project Key')
                                    ->default('—'),

                                TextEntry::make('owner.name')
                                    ->label('Owner')
                                    ->default('Unassigned'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn ($state) => $state?->color() ?? 'gray')
                                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—'),

                                TextEntry::make('source')
                                    ->badge()
                                    ->color(fn ($state) => $state?->color() ?? 'gray')
                                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—'),
                            ]),
                    ]),

                Section::make('Attendees')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('external_attendees')
                                    ->label('External Attendees')
                                    ->badge()
                                    ->color('info')
                                    ->getStateUsing(function ($record) {
                                        $attendees = $record->external_attendees ?? [];
                                        return collect($attendees)->map(fn ($a) => is_array($a) ? ($a['email'] ?? $a['name'] ?? '?') : $a)->toArray();
                                    })
                                    ->listWithLineBreaks()
                                    ->default('None'),

                                TextEntry::make('internal_attendees')
                                    ->label('Internal Attendees')
                                    ->badge()
                                    ->color('gray')
                                    ->getStateUsing(function ($record) {
                                        $attendees = $record->internal_attendees ?? [];
                                        return collect($attendees)->map(fn ($a) => is_array($a) ? ($a['email'] ?? $a['name'] ?? '?') : $a)->toArray();
                                    })
                                    ->listWithLineBreaks()
                                    ->default('None'),
                            ]),
                    ])
                    ->collapsible(),
            ]);
    }

    // ── Tab 2: Pre-Meeting Prep ─────────────────────────────────────────

    private function preMeetingPrepTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Pre-Meeting Prep')
            ->icon('heroicon-o-document-text')
            ->badge(fn ($record) => $record->prep ? ($record->prep->ai_error ? '⚠' : '✓') : null)
            ->badgeColor(fn ($record) => $record->prep?->ai_error ? 'danger' : 'success')
            ->schema(function () {
                /** @var \App\Models\ClientMeeting $record */
                $record = $this->getRecord();

                if (! $record->prep) {
                    return [
                        Section::make()
                            ->schema([
                                TextEntry::make('no_prep_placeholder')
                                    ->label('')
                                    ->default('No meeting prep has been generated yet. Use the "Generate Prep" button in the header.')
                                    ->columnSpanFull(),
                            ]),
                    ];
                }

                if ($record->prep->ai_error) {
                    return [
                        Section::make('AI Generation Failed')
                            ->description('An error occurred during meeting prep generation.')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->schema([
                                TextEntry::make('prep.ai_error')
                                    ->label('Error Message')
                                    ->color('danger')
                                    ->weight('bold')
                                    ->columnSpanFull(),
                                TextEntry::make('prep_error_instructions')
                                    ->label('')
                                    ->default('Please try generating the prep again by clicking the "Generate Prep" button in the page header. If the issue persists, please check your AI provider configuration or select a different model in the "Generate Prep" modal.')
                                    ->columnSpanFull(),
                            ]),

                        Section::make('AI Generation Info')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextEntry::make('prep.ai_provider')
                                            ->label('Provider'),

                                        TextEntry::make('prep.ai_model')
                                            ->label('Model'),

                                        TextEntry::make('prep.generated_at')
                                            ->label('Failed At')
                                            ->dateTime('M j, Y g:i A'),
                                    ]),
                            ])
                            ->collapsible()
                            ->collapsed(),
                    ];
                }

                return [
                    // Jira Snapshot
                    Section::make('Jira Snapshot')
                        ->icon('heroicon-o-circle-stack')
                        ->schema([
                            TextEntry::make('prep.jira_project_key')
                                ->label('Project Key')
                                ->badge()
                                ->color('primary'),

                            TextEntry::make('prep.jira_jql')
                                ->label('JQL Used')
                                ->columnSpanFull()
                                ->fontFamily('mono')
                                ->size(TextEntry\TextEntrySize::Small),

                            ViewEntry::make('prep.jira_snapshot')
                                ->label('Ticket Snapshot')
                                ->view('filament.resources.client-meeting-resource.entries.jira-snapshot')
                                ->columnSpanFull(),
                        ])
                        ->collapsible()
                        ->collapsed(),

                    // Internal Summary
                    Section::make('Internal Summary')
                        ->icon('heroicon-o-document-magnifying-glass')
                        ->schema([
                            TextEntry::make('prep.internal_summary')
                                ->label('')
                                ->markdown()
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),

                    // Recommended Agenda
                    Section::make('Recommended Agenda')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->schema([
                            TextEntry::make('prep.recommended_agenda')
                                ->label('')
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),

                    // Customer Status Email — Inline Composer
                    Section::make('Customer Status Email')
                        ->icon('heroicon-o-envelope')
                        ->schema([
                            ViewEntry::make('prep_email_form')
                                ->view('filament.resources.client-meeting-resource.entries.prep-email-form')
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),


                    // AI Metadata
                    Section::make('AI Generation Info')
                        ->icon('heroicon-o-cpu-chip')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    TextEntry::make('prep.ai_provider')
                                        ->label('Provider'),

                                    TextEntry::make('prep.ai_model')
                                        ->label('Model'),

                                    TextEntry::make('prep.generated_at')
                                        ->label('Generated At')
                                        ->dateTime('M j, Y g:i A'),
                                ]),

                            TextEntry::make('prep.ai_error')
                                ->label('Error')
                                ->color('danger')
                                ->visible(fn ($state) => filled($state)),
                        ])
                        ->collapsible()
                        ->collapsed(),
                ];
            });
    }

    // ── Tab 3: Meeting Notes ────────────────────────────────────────────

    private function meetingNotesTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Meeting Notes')
            ->icon('heroicon-o-pencil-square')
            ->schema([
                Section::make('Meeting Notes & Transcript')
                    ->description('Enter your meeting notes here. You can save them independently, or use them to generate a follow-up.')
                    ->schema([
                        ViewEntry::make('meeting_notes_form')
                            ->view('filament.resources.client-meeting-resource.entries.meeting-notes-form')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    // ── Tab 4: Follow-Up ────────────────────────────────────────────────

    private function followUpTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Follow-Up')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->badge(fn ($record) => $record->followUp ? ($record->followUp->ai_error ? '⚠' : '✓') : null)
            ->badgeColor(fn ($record) => $record->followUp?->ai_error ? 'danger' : 'success')
            ->schema(function () {
                /** @var \App\Models\ClientMeeting $record */
                $record = $this->getRecord();

                if (! $record->followUp) {
                    return [
                        Section::make()
                            ->schema([
                                TextEntry::make('no_followup_placeholder')
                                    ->label('')
                                    ->default('No follow-up has been generated yet. Add meeting notes in the "Meeting Notes" tab, then use the "Generate Follow-Up" button.')
                                    ->columnSpanFull(),
                            ]),
                    ];
                }

                if ($record->followUp->ai_error) {
                    return [
                        Section::make('AI Generation Failed')
                            ->description('An error occurred during follow-up generation.')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->schema([
                                TextEntry::make('followUp.ai_error')
                                    ->label('Error Message')
                                    ->color('danger')
                                    ->weight('bold')
                                    ->columnSpanFull(),
                                TextEntry::make('followup_error_instructions')
                                    ->label('')
                                    ->default('Please try generating the follow-up again by clicking the "Generate Follow-Up" button in the page header. If the issue persists, please check your AI provider configuration or select a different model in the "Generate Follow-Up" modal.')
                                    ->columnSpanFull(),
                            ]),

                        Section::make('AI Generation Info')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextEntry::make('followUp.ai_provider')
                                            ->label('Provider'),

                                        TextEntry::make('followUp.ai_model')
                                            ->label('Model'),

                                        TextEntry::make('followUp.generated_at')
                                            ->label('Failed At')
                                            ->dateTime('M j, Y g:i A'),
                                    ]),
                            ])
                            ->collapsible()
                            ->collapsed(),
                    ];
                }

                return [
                    // Summary
                    Section::make('Summary')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            TextEntry::make('followUp.summary')
                                ->label('')
                                ->markdown()
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),

                    // Decisions
                    Section::make('Decisions')
                        ->icon('heroicon-o-check-badge')
                        ->schema([
                            TextEntry::make('followUp.decisions')
                                ->label('')
                                ->getStateUsing(fn ($record) => is_array($record->followUp?->decisions) ? $record->followUp->decisions : array_filter(array_map('trim', explode("\n", $record->followUp?->decisions ?? ''))))
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),
                        ])
                        ->collapsible()
                        ->visible(fn () => filled($record->followUp?->decisions)),

                    // Open Questions
                    Section::make('Open Questions')
                        ->icon('heroicon-o-question-mark-circle')
                        ->schema([
                            TextEntry::make('followUp.open_questions')
                                ->label('')
                                ->getStateUsing(fn ($record) => is_array($record->followUp?->open_questions) ? $record->followUp->open_questions : array_filter(array_map('trim', explode("\n", $record->followUp?->open_questions ?? ''))))
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),
                        ])
                        ->collapsible()
                        ->visible(fn () => filled($record->followUp?->open_questions)),

                    // Follow-Up Email — Inline Composer
                    Section::make('Follow-Up Email')
                        ->icon('heroicon-o-envelope')
                        ->schema([
                            ViewEntry::make('followup_email_form')
                                ->view('filament.resources.client-meeting-resource.entries.followup-email-form')
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),

                    // Accepted Action Items
                    Section::make('Action Items')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->schema([
                            ViewEntry::make('action_items_table')
                                ->view('filament.resources.client-meeting-resource.entries.action-items-table')
                                ->columnSpanFull(),
                        ])
                        ->collapsible()
                        ->visible(fn () => $record->actionItems()->count() > 0),

                    // Suggested Action Items (AI)
                    Section::make('Suggested Action Items (AI)')
                        ->icon('heroicon-o-light-bulb')
                        ->description('AI-suggested action items. Accept to create them as tracked action items.')
                        ->schema([
                            ViewEntry::make('suggested_action_items')
                                ->view('filament.resources.client-meeting-resource.entries.suggested-action-items')
                                ->columnSpanFull(),
                        ])
                        ->collapsible()
                        ->visible(fn () => ! empty($record->followUp->suggested_action_items)),

                    // AI Metadata
                    Section::make('AI Generation Info')
                        ->icon('heroicon-o-cpu-chip')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    TextEntry::make('followUp.ai_provider')
                                        ->label('Provider'),

                                    TextEntry::make('followUp.ai_model')
                                        ->label('Model'),

                                    TextEntry::make('followUp.generated_at')
                                        ->label('Generated At')
                                        ->dateTime('M j, Y g:i A'),
                                ]),

                            TextEntry::make('followUp.ai_error')
                                ->label('Error')
                                ->color('danger')
                                ->visible(fn ($state) => filled($state)),
                        ])
                        ->collapsible()
                        ->collapsed(),
                ];
            });
    }

    // ── Livewire Actions for Notes & Suggested Items ────────────────────

    public function saveMeetingNotes(string $rawNotes, string $transcriptText): void
    {
        $record = $this->getRecord();

        $followUp = $record->followUp;

        if ($followUp) {
            $followUp->update([
                'raw_notes'       => $rawNotes,
                'transcript_text' => $transcriptText ?: null,
            ]);
        } else {
            $record->followUp()->create([
                'raw_notes'       => $rawNotes,
                'transcript_text' => $transcriptText ?: null,
            ]);
        }

        Notification::make()
            ->title('Meeting notes saved')
            ->success()
            ->send();
    }

    public function acceptSuggestedItem(int $index): void
    {
        $record = $this->getRecord();
        $followUp = $record->followUp;

        if (! $followUp) {
            return;
        }

        $suggestions = $followUp->suggested_action_items ?? [];

        if (! isset($suggestions[$index])) {
            return;
        }

        $item = $suggestions[$index];

        MeetingActionItem::create([
            'client_meeting_id'    => $record->getKey(),
            'meeting_follow_up_id' => $followUp->getKey(),
            'title'                => $item['title'] ?? 'Untitled',
            'description'          => $item['description'] ?? null,
            'owner_name'           => $item['owner_name'] ?? $item['owner'] ?? null,
            'due_date'             => isset($item['due_date']) ? $item['due_date'] : null,
            'status'               => ActionItemStatus::Open,
            'source'               => ActionItemSource::Ai,
            'is_customer_facing'   => $item['is_customer_facing'] ?? false,
        ]);

        // Remove the accepted item from suggestions
        unset($suggestions[$index]);
        $followUp->update([
            'suggested_action_items' => array_values($suggestions),
        ]);

        Notification::make()
            ->title('Action item accepted')
            ->success()
            ->send();
    }

    public function dismissSuggestedItem(int $index): void
    {
        $record = $this->getRecord();
        $followUp = $record->followUp;

        if (! $followUp) {
            return;
        }

        $suggestions = $followUp->suggested_action_items ?? [];

        if (! isset($suggestions[$index])) {
            return;
        }

        unset($suggestions[$index]);
        $followUp->update([
            'suggested_action_items' => array_values($suggestions),
        ]);

        Notification::make()
            ->title('Suggestion dismissed')
            ->info()
            ->send();
    }

    /**
     * Pull meeting transcript and notes from Google Drive.
     * Searches for Google Meet transcript docs matching the meeting title.
     */
    public function pullGoogleMeetTranscript(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (! $user->hasGoogleWorkspace()) {
            Notification::make()
                ->title('Google Workspace not connected')
                ->body('Connect your Google Workspace to pull transcripts.')
                ->danger()
                ->send();

            return ['transcript' => '', 'notes' => '', 'files' => []];
        }

        try {
            $driveService = new GoogleDriveService($user);
            $result = $driveService->pullMeetingTranscript($this->getRecord());

            $fileCount = count($result['files_found']);
            $fileNames = collect($result['files_found'])->pluck('name')->join(', ');

            if ($fileCount > 0) {
                Notification::make()
                    ->title("Found {$fileCount} document(s)")
                    ->body($fileNames)
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('No transcript found')
                    ->body('No Google Meet transcript or notes documents were found matching this meeting. Make sure Google Meet transcription was enabled.')
                    ->warning()
                    ->send();
            }

            return [
                'transcript' => $result['transcript'],
                'notes'      => $result['notes'],
                'files'      => $result['files_found'],
            ];
        } catch (\Exception $e) {
            Notification::make()
                ->title('Failed to pull transcript')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return ['transcript' => '', 'notes' => '', 'files' => []];
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Strip <html>, <body>, <head>, and <!DOCTYPE> wrapper tags from
     * AI-generated email content so it can be safely rendered inline
     * without corrupting the page DOM structure.
     */
    private static function stripHtmlWrapper(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?html[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?body[^>]*>/i', '', $html);
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);

        return trim($html);
    }

    /**
     * Create a Gmail draft from the inline prep email form.
     * Called via $wire from the prep-email-form blade.
     */
    public function sendPrepEmailDraft(string $to, string $subject, string $body, array $cc = []): void
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $record = $this->getRecord();

        $gmailScope = config('meeting_agent.google.scopes.gmail_compose');
        if (! $user->hasMeetingAgentScope($gmailScope)) {
            Notification::make()
                ->title('Missing Gmail permission')
                ->body('Your Google Workspace account does not have the Gmail compose permission.')
                ->danger()
                ->send();

            return;
        }

        try {
            $gmailService = new \App\Services\MeetingAgent\GmailService($user);

            $draftId = $gmailService->createDraft(
                to: $to,
                subject: $subject,
                body: $body,
                cc: $cc,
            );

            // Store the draft ID and save edited content
            if ($record->prep) {
                $record->prep->update([
                    'gmail_draft_id' => $draftId,
                    'edited_status_email_subject' => $subject,
                    'edited_status_email_body' => $body,
                    'email_to' => $to,
                    'email_cc' => $cc,
                ]);
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
    }


    // ── Follow-Up Email Methods ─────────────────────────────────────────

    /**
     * Create a Gmail draft from the inline follow-up email form.
     */
    public function sendFollowUpEmailDraft(string $to, string $subject, string $body, array $cc = []): void
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $record = $this->getRecord();

        $gmailScope = config('meeting_agent.google.scopes.gmail_compose');
        if (! $user->hasMeetingAgentScope($gmailScope)) {
            Notification::make()
                ->title('Missing Gmail permission')
                ->body('Your Google Workspace account does not have the Gmail compose permission.')
                ->danger()
                ->send();

            return;
        }

        try {
            $gmailService = new \App\Services\MeetingAgent\GmailService($user);

            $draftId = $gmailService->createDraft(
                to: $to,
                subject: $subject,
                body: $body,
                cc: $cc,
            );

            if ($record->followUp) {
                $record->followUp->update([
                    'gmail_draft_id' => $draftId,
                    'edited_followup_email_subject' => $subject,
                    'edited_followup_email_body' => $body,
                    'email_to' => $to,
                    'email_cc' => $cc,
                ]);
            }

            Notification::make()
                ->title('Gmail draft created')
                ->body('The follow-up draft has been created in your Gmail mailbox.')
                ->success()
                ->send();

        } catch (\RuntimeException $e) {
            Notification::make()
                ->title('Draft creation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

}
