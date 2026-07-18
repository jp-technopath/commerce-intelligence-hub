@php
    $record = $getRecord();
    $record->refresh();
    $record->load(['prep', 'followUp']);

    $hasPrepError = $record->prep?->ai_error !== null;
    $hasFollowUpError = $record->followUp?->ai_error !== null;

    $isPrepPending = $record->status === \App\Enums\MeetingStatus::PrepPending;
    $isFollowUpPending = $record->status === \App\Enums\MeetingStatus::FollowUpPending;

    $shouldPoll = ($isPrepPending && ! $hasPrepError) || ($isFollowUpPending && ! $hasFollowUpError);
@endphp

@if ($shouldPoll)
    <div wire:poll.3s class="flex items-center gap-3 p-4 mb-6 border rounded-xl bg-primary-50 text-primary-800 border-primary-200 dark:bg-primary-950 dark:text-primary-300 dark:border-primary-800 animate-pulse shadow-sm">
        <svg class="w-5 h-5 text-primary-600 dark:text-primary-400 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.791 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <div class="flex flex-col">
            @if ($isPrepPending)
                <span class="font-semibold text-sm">AI prep generation in progress</span>
                <span class="text-xs opacity-90">Please wait while the meeting preparation materials are generated. The page will update automatically.</span>
            @else
                <span class="font-semibold text-sm">AI follow-up generation in progress</span>
                <span class="text-xs opacity-90">Please wait while the meeting summary and action items are generated. The page will update automatically.</span>
            @endif
        </div>
    </div>
@endif
