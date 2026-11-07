@php
    $record = $getRecord();
    $record->refresh();
    $record->load(['recommendations']);

    $hasRecommendations = $record->recommendations->isNotEmpty();
    $status = $record->metadata_json['ai_analysis_status'] ?? null;
    $startedAt = $record->metadata_json['ai_analysis_started_at'] ?? null;
    $error = $record->metadata_json['ai_analysis_error'] ?? null;

    // Treat as pending if explicitly marked pending within the last 10 minutes and no recommendations exist yet
    $isPending = ! $hasRecommendations 
        && $status === 'pending' 
        && $startedAt 
        && now()->parse($startedAt)->diffInMinutes() < 10;

    $hasFailed = ! $hasRecommendations && $status === 'failed';
@endphp

@if ($isPending)
    <div wire:poll.4s class="flex items-center gap-3 p-4 mb-6 border rounded-xl bg-amber-50 text-amber-900 border-amber-200 dark:bg-amber-950/50 dark:text-amber-200 dark:border-amber-800 animate-pulse shadow-sm">
        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 animate-spin shrink-0" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.791 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <div class="flex flex-col">
            <span class="font-semibold text-sm">🧠 AI Analysis in Progress</span>
            <span class="text-xs opacity-90">Gathering live data across integrations (GA4, Adobe Commerce, Clarity, New Relic, Klaviyo) and generating recommendations. This page will update automatically when complete.</span>
        </div>
    </div>
@elseif ($hasFailed)
    <div class="flex items-center gap-3 p-4 mb-6 border rounded-xl bg-danger-50 text-danger-900 border-danger-200 dark:bg-danger-950/50 dark:text-danger-200 dark:border-danger-800 shadow-sm">
        <x-filament::icon
            icon="heroicon-o-exclamation-triangle"
            class="w-5 h-5 text-danger-600 dark:text-danger-400 shrink-0"
        />
        <div class="flex flex-col">
            <span class="font-semibold text-sm">AI Analysis Failed</span>
            <span class="text-xs opacity-90">{{ $error ?? 'The AI analysis could not be generated. Check your AI provider configuration or retry.' }}</span>
        </div>
    </div>
@endif
