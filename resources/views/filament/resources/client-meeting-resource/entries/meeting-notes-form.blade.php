@php
    $record = $getRecord();
    $followUp = $record->followUp;
    $rawNotes = old('raw_notes', $followUp?->raw_notes ?? '');
    $transcriptText = old('transcript_text', $followUp?->transcript_text ?? '');
@endphp

<div class="space-y-4" x-data="{
    rawNotes: @js($rawNotes),
    transcriptText: @js($transcriptText),
    saving: false,
    pulling: false,
}">
    <div class="space-y-1.5">
        <label for="raw_notes" class="text-sm font-medium text-gray-700 dark:text-gray-300">
            Meeting Notes
        </label>
        <textarea
            id="raw_notes"
            x-model="rawNotes"
            rows="8"
            class="fi-textarea block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-primary-500 sm:text-sm"
            placeholder="Enter your meeting notes here..."
        ></textarea>
    </div>

    <div class="space-y-1.5">
        <label for="transcript_text" class="text-sm font-medium text-gray-700 dark:text-gray-300">
            Transcript <span class="text-xs text-gray-400">(optional)</span>
        </label>
        <textarea
            id="transcript_text"
            x-model="transcriptText"
            rows="6"
            class="fi-textarea block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-primary-500 sm:text-sm"
            placeholder="Paste meeting transcript here (optional)..."
        ></textarea>
    </div>

    <div class="flex items-center gap-x-3 flex-wrap">
        {{-- Save Notes Button --}}
        <button
            type="button"
            x-on:click="
                saving = true;
                $wire.saveMeetingNotes(rawNotes, transcriptText).then(() => { saving = false; });
            "
            x-bind:disabled="saving"
            class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 disabled:opacity-50 disabled:cursor-not-allowed"
        >
            <template x-if="saving">
                <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </template>
            <template x-if="!saving">
                <x-heroicon-m-check class="h-4 w-4" />
            </template>
            <span x-text="saving ? 'Saving...' : 'Save Notes'"></span>
        </button>

        {{-- Pull from Google Meet Button --}}
        <button
            type="button"
            x-on:click="
                pulling = true;
                $wire.pullGoogleMeetTranscript().then((result) => {
                    pulling = false;
                    if (result.transcript) {
                        transcriptText = transcriptText ? transcriptText + '\n\n---\n\n' + result.transcript : result.transcript;
                    }
                    if (result.notes) {
                        rawNotes = rawNotes ? rawNotes + '\n\n---\n\n' + result.notes : result.notes;
                    }
                }).catch(() => { pulling = false; });
            "
            x-bind:disabled="pulling"
            style="display: inline-flex; align-items: center; gap: 0.375rem; border-radius: 0.5rem; background-color: #4285f4; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600; color: white; border: none; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.1);"
            onmouseover="this.style.backgroundColor='#3367d6'"
            onmouseout="this.style.backgroundColor='#4285f4'"
        >
            <template x-if="pulling">
                <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </template>
            <template x-if="!pulling">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z" fill="currentColor"/>
                </svg>
            </template>
            <span x-text="pulling ? 'Searching Drive...' : 'Pull from Google Meet'"></span>
        </button>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Notes are saved to the follow-up record. Use the header "Generate Follow-Up" button to generate AI analysis.
        </p>
    </div>
</div>
