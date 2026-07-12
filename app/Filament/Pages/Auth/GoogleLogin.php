<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class GoogleLogin extends BaseLogin
{
    protected static string $view = 'filament.pages.auth.google-login';
}
