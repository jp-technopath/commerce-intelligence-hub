@php
    $record = $getRecord();
    $prep = $record->prep;

    // Pre-fill subject from effective (user-edited or generated)
    $subject = $prep?->effectiveSubject() ?? '';

    // Pre-fill body — strip html/body wrappers
    $body = $prep?->effectiveBody() ?? '';
    $body = preg_replace('/<!DOCTYPE[^>]*>/i', '', $body);
    $body = preg_replace('/<\/?html[^>]*>/i', '', $body);
    $body = preg_replace('/<\/?body[^>]*>/i', '', $body);
    $body = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $body);
    $body = trim($body);

    // Pre-fill recipient & CC — prefer stored values, fall back to attendees
    $defaultExternalEmails = collect($record->external_attendees ?? [])->pluck('email')->filter()->values()->toArray();
    $defaultInternalEmails = collect($record->internal_attendees ?? [])
        ->pluck('email')
        ->filter()
        ->reject(fn ($e) => $e === auth()->user()?->email)
        ->values()
        ->toArray();

    $recipientEmail = $prep?->email_to ?? ($defaultExternalEmails[0] ?? '');
    $ccEmails = $prep?->email_cc ?? array_merge(array_slice($defaultExternalEmails, 1), $defaultInternalEmails);

    $hasDraft = ! empty($prep?->gmail_draft_id);
    $emailSentAt = $prep?->email_sent_at;
    $emailSentAtFormatted = $emailSentAt ? $emailSentAt->format('M j, Y g:i A') : null;

    // Check Gmail permission
    $user = auth()->user();
    $gmailScope = config('meeting_agent.google.scopes.gmail_compose');
    $hasGmailScope = $user?->hasMeetingAgentScope($gmailScope) ?? false;
@endphp

<div
    wire:key="prep-email-form-{{ $prep?->id ?? 'none' }}-{{ $prep?->updated_at?->timestamp ?? 'none' }}"
    class="space-y-4"
    x-data="{
        recipient: @js($recipientEmail),
        ccInput: '',
        ccList: @js($ccEmails),
        subject: @js($subject),
        creatingDraft: false,
        sendingEmail: false,
        hasDraft: @js($hasDraft),

        get isBusy() { return this.creatingDraft || this.sendingEmail; },
        get canSubmit() { return this.recipient && this.subject && !this.isBusy; },

        addCc() {
            const email = this.ccInput.trim();
            if (email && email.includes('@') && !this.ccList.includes(email)) {
                this.ccList.push(email);
                this.ccInput = '';
            }
        },

        removeCc(index) {
            this.ccList.splice(index, 1);
        },

        handleCcKeydown(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                this.addCc();
            }
            if (e.key === 'Backspace' && this.ccInput === '' && this.ccList.length > 0) {
                this.ccList.pop();
            }
        },

        execCmd(cmd, value = null) {
            document.execCommand(cmd, false, value);
            this.$refs.editor.focus();
        },

        insertLink() {
            const url = prompt('Enter URL:');
            if (url) {
                document.execCommand('createLink', false, url);
                this.$refs.editor.focus();
            }
        },

        async createDraft() {
            this.creatingDraft = true;
            const bodyHtml = this.$refs.editor.innerHTML;
            try {
                await $wire.sendPrepEmailDraft(this.recipient, this.subject, bodyHtml, this.ccList);
                this.hasDraft = true;
            } catch (e) {
                console.error(e);
            }
            this.creatingDraft = false;
        },

        async sendEmail() {
            if (!confirm('Are you sure you want to send this email status report directly to ' + this.recipient + '?')) {
                return;
            }
            this.sendingEmail = true;
            const bodyHtml = this.$refs.editor.innerHTML;
            try {
                await $wire.sendPrepEmail(this.recipient, this.subject, bodyHtml, this.ccList);
            } catch (e) {
                console.error(e);
            }
            this.sendingEmail = false;
        }
    }"
>
    {{-- Recipient --}}
    <div class="space-y-1.5">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">To</label>
        <input
            type="email"
            x-model="recipient"
            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
            placeholder="recipient@example.com"
        />
    </div>

    {{-- CC --}}
    <div class="space-y-1.5">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">CC</label>
        <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 shadow-sm transition duration-75 focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-inset focus-within:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
            <template x-for="(email, idx) in ccList" :key="idx">
                <span class="inline-flex items-center gap-x-1 rounded-md bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/30">
                    <span x-text="email"></span>
                    <button type="button" @click="removeCc(idx)" class="ml-0.5 text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-200">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                    </button>
                </span>
            </template>
            <input
                type="text"
                x-model="ccInput"
                @keydown="handleCcKeydown"
                @blur="addCc()"
                class="min-w-[120px] flex-1 border-none bg-transparent p-0 text-sm text-gray-900 placeholder-gray-400 focus:ring-0 dark:text-white dark:placeholder-gray-500"
                placeholder="Add CC email..."
            />
        </div>
    </div>

    {{-- Subject --}}
    <div class="space-y-1.5">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Subject</label>
        <input
            type="text"
            x-model="subject"
            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
            placeholder="Email subject"
        />
    </div>

    {{-- WYSIWYG Toolbar + Editor --}}
    <div class="space-y-1.5">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Body</label>
        <div class="overflow-hidden rounded-lg border border-gray-300 shadow-sm focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-inset focus-within:ring-primary-500 dark:border-gray-600">
            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50 px-2 py-1.5 dark:border-gray-600 dark:bg-gray-800">
                <button type="button" @click="execCmd('bold')" title="Bold"
                    class="rounded p-1.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h8a4 4 0 014 4 4 4 0 01-4 4H6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 12h9a4 4 0 014 4 4 4 0 01-4 4H6z"/></svg>
                </button>
                <button type="button" @click="execCmd('italic')" title="Italic"
                    class="rounded p-1.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10 4h4m-2 0v16m-4 0h8"/></svg>
                </button>
                <button type="button" @click="execCmd('underline')" title="Underline"
                    class="rounded p-1.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4v7a5 5 0 0010 0V4M5 20h14"/></svg>
                </button>
                <div class="mx-1 h-5 w-px bg-gray-300 dark:bg-gray-600"></div>
                <button type="button" @click="execCmd('insertUnorderedList')" title="Bullet List"
                    class="rounded p-1.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
                </button>
                <button type="button" @click="execCmd('insertOrderedList')" title="Numbered List"
                    class="rounded p-1.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75V5.25m0 1.5h1.5m-1.5 0h-1.5m1.5 5.25V10.5m0 1.5h1.5m-1.5 0h-1.5m1.5 5.25V15m0 1.5h1.5m-1.5 0h-1.5"/></svg>
                </button>
                <div class="mx-1 h-5 w-px bg-gray-300 dark:bg-gray-600"></div>
                <button type="button" @click="insertLink()" title="Insert Link"
                    class="rounded p-1.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.435-3.04a4.5 4.5 0 00-1.242-7.244l-4.5-4.5a4.5 4.5 0 00-6.364 6.364l1.757 1.757"/></svg>
                </button>
                <button type="button" @click="execCmd('removeFormat')" title="Clear Formatting"
                    class="rounded p-1.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75L14.25 12m0 0l2.25 2.25M14.25 12l2.25-2.25M14.25 12L12 14.25m-2.58 4.92l-6.375-6.375a1.125 1.125 0 010-1.59L9.42 4.83a1.125 1.125 0 011.59 0l6.375 6.375a1.125 1.125 0 010 1.59L11.01 19.17a1.125 1.125 0 01-1.59 0z"/></svg>
                </button>
            </div>

            {{-- Editable Content --}}
            <div
                x-ref="editor"
                contenteditable="true"
                class="prose prose-sm dark:prose-invert max-w-none min-h-[250px] max-h-[500px] overflow-y-auto bg-white px-4 py-3 text-sm text-gray-900 focus:outline-none dark:bg-gray-700 dark:text-white"
            >{!! $body !!}</div>
        </div>
    </div>

    {{-- ── Action Bar ────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 pt-2 flex-wrap">
        @if ($hasGmailScope)
            {{-- Create Draft --}}
            <button
                type="button"
                @click="createDraft()"
                :disabled="!canSubmit"
                class="inline-flex items-center gap-x-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:opacity-50 disabled:cursor-not-allowed dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
            >
                <template x-if="creatingDraft">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </template>
                <template x-if="!creatingDraft">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                    </svg>
                </template>
                <span x-text="creatingDraft ? 'Saving Draft...' : (hasDraft ? 'Update Draft' : 'Save as Draft')"></span>
            </button>

            {{-- Send Email --}}
            <button
                type="button"
                @click="sendEmail()"
                :disabled="!canSubmit"
                class="inline-flex items-center gap-x-2 rounded-lg border border-transparent bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-primary-500 dark:hover:bg-primary-400"
            >
                <template x-if="sendingEmail">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </template>
                <template x-if="!sendingEmail">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                    </svg>
                </template>
                <span x-text="sendingEmail ? 'Sending Email...' : 'Send Email'"></span>
            </button>
        @else
            <p class="text-sm text-danger-600 dark:text-danger-400">
                <svg class="inline h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                Gmail compose permission not granted. Please reconnect your Google Workspace account.
            </p>
        @endif
    </div>
</div>
