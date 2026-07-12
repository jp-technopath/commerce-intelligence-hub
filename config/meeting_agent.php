<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer Meeting Agent Configuration
    |--------------------------------------------------------------------------
    |
    | Central configuration for the meeting agent module, including AI
    | provider settings, Jira integration, calendar scanning rules,
    | and Google Workspace OAuth scopes.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | AI Provider Settings
    |--------------------------------------------------------------------------
    |
    | Multi-provider AI configuration. The 'provider' key selects which
    | service to use for meeting prep and follow-up generation.
    |
    */
    'ai' => [
        'provider'         => env('AI_PROVIDER', 'openrouter'),
        'openrouter_key'   => env('OPENROUTER_API_KEY'),
        'openrouter_model' => env('OPENROUTER_MODEL', 'openai/gpt-4o'),
        'openrouter_models' => array_filter(explode(',', env('OPENROUTER_AVAILABLE_MODELS', '~anthropic/claude-fable-latest,anthropic/claude-opus-4.8,openai/gpt-5.6-luna-pro,~google/gemini-pro-latest'))),
        'openai_key'       => env('OPENAI_API_KEY'),
        'openai_model'     => env('OPENAI_MODEL', 'gpt-4o'),
        'gemini_key'       => env('GEMINI_API_KEY'),
        'gemini_model'     => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'max_tokens'       => (int) env('AI_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jira Integration
    |--------------------------------------------------------------------------
    |
    | Connection details and status-mapping rules for pulling project
    | ticket data into meeting prep materials.
    |
    */
    'jira' => [
        'base_url'  => env('JIRA_BASE_URL'),
        'email'     => env('JIRA_EMAIL'),
        'api_token' => env('JIRA_API_TOKEN'),
        'status_mappings' => [
            'completed'            => ['Done', 'Closed', 'Resolved', 'Complete', 'Ready For Invoicing'],
            'in_progress'          => ['In Progress', 'Development', 'In Dev', 'IN PROGRESS', 'Ready For Dev', 'Rework'],
            'blocked'              => ['Blocked', 'On Hold', 'Backlog'],
            'ready_for_review'     => ['Ready for QA', 'Ready for Review', 'UAT', 'QA ON STAGING', 'QA on Staging', 'Ready For Review', 'Code Review'],
            'needs_customer_input' => ['Waiting on Customer', 'Needs Clarification', 'Waiting for Client'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar Scanning Rules
    |--------------------------------------------------------------------------
    |
    | Controls how the calendar scanner identifies customer meetings.
    | Events matching exclude_patterns are skipped unless they contain
    | the include_hashtag.
    |
    */
    'calendar' => [
        'scan_days_ahead'  => 7,
        'company_domains'  => array_filter(explode(',', env('COMPANY_EMAIL_DOMAINS', ''))),
        'exclude_patterns' => [
            '/standup/i', '/stand-up/i', '/daily sync/i',
            '/sprint planning/i', '/retro/i', '/retrospective/i',
            '/1:1/i', '/1-on-1/i', '/internal/i', '/team meeting/i',
            '/holiday/i', '/out of office/i', '/ooo/i', '/lunch/i',
        ],
        'include_hashtag' => '#customer-meeting',
    ],

    /*
    |--------------------------------------------------------------------------
    | Google OAuth Scopes
    |--------------------------------------------------------------------------
    |
    | Scopes requested during Google login and workspace authorization.
    | Login scopes are requested at sign-in; workspace scopes are
    | requested when the user connects their Google Workspace account.
    |
    */
    'google' => [
        'login_scopes' => ['openid', 'email', 'profile'],
        'workspace_scopes' => [
            'https://www.googleapis.com/auth/calendar.events.readonly',
            'https://www.googleapis.com/auth/gmail.compose',
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/drive.readonly',
        ],

        /*
        |----------------------------------------------------------------------
        | Named Scope Constants
        |----------------------------------------------------------------------
        |
        | Use these for reliable scope checking throughout the application.
        | Services should reference config('meeting_agent.google.scopes.*')
        | rather than hardcoding partial scope strings.
        |
        */
        'scopes' => [
            'calendar_readonly' => 'https://www.googleapis.com/auth/calendar.events.readonly',
            'gmail_compose'     => 'https://www.googleapis.com/auth/gmail.compose',
            'drive_file'        => 'https://www.googleapis.com/auth/drive.file',
            'drive_readonly'    => 'https://www.googleapis.com/auth/drive.readonly',
        ],
    ],

];
