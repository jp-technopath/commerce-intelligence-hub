<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google OAuth2 Credentials
    |--------------------------------------------------------------------------
    | Created in Google Cloud Console → APIs & Services → Credentials
    | → OAuth 2.0 Client ID (Web application type)
    |
    */

    'client_id'     => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri'  => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://127.0.0.1:8080') . '/google/oauth/callback'),

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Scopes
    |--------------------------------------------------------------------------
    | GA4 Data API read-only scope. Add Search Console scope here in future.
    |
    */

    'scopes' => [
        'openid',
        'email',
        'https://www.googleapis.com/auth/analytics.readonly',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Settings
    |--------------------------------------------------------------------------
    */

    'access_type'   => 'offline',   // Required to receive a refresh token
    'prompt'        => 'consent',   // Force consent screen to always get refresh token

];
