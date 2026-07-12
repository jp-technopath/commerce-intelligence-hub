<?php

use App\Http\Controllers\GoogleOAuthController;
use Illuminate\Support\Facades\Route;

// Redirect root to the Filament admin panel
Route::get('/', fn () => redirect('/admin'));

// Named 'login' route required by Laravel's auth middleware
Route::get('/login', fn () => redirect('/admin/login'))->name('login');

// Google OAuth2 — GA4 authorization flow
// Protected by Filament's auth: only logged-in panel users can initiate
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/google/oauth/ga4/{integration}', [GoogleOAuthController::class, 'redirect'])
        ->name('google.oauth.redirect');

    Route::get('/google/oauth/ga4/{integration}/revoke', [GoogleOAuthController::class, 'revoke'])
        ->name('google.oauth.revoke');
});

// Google Sign-in (unauthenticated users)
Route::get('/google/login', [GoogleOAuthController::class, 'redirectLogin'])
    ->name('google.login');

// Google Workspace connect (authenticated users only)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/google/workspace/connect', [GoogleOAuthController::class, 'redirectWorkspace'])
        ->name('google.workspace.connect');
    Route::get('/google/workspace/revoke', [GoogleOAuthController::class, 'revokeWorkspace'])
        ->name('google.workspace.revoke');
});

// Google OAuth callback — must be outside auth middleware (Google redirects here unauthenticated)
Route::get('/google/oauth/callback', [GoogleOAuthController::class, 'callback'])
    ->name('google.oauth.callback');
