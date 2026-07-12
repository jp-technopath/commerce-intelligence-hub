<?php

namespace App\Http\Controllers;

use App\Enums\ConnectedAccountStatus;
use App\Enums\IntegrationStatus;
use App\Models\ConnectedAccount;
use App\Models\Integration;
use App\Models\User;
use Google\Client as GoogleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleOAuthController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // GA4 OAuth flow (existing — unchanged)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build and return a configured Google Client for GA4 OAuth.
     */
    private function buildClient(): GoogleClient
    {
        $client = new GoogleClient();

        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->setScopes(config('google.scopes'));
        $client->setAccessType(config('google.access_type'));
        $client->setPrompt(config('google.prompt'));

        return $client;
    }

    /**
     * Redirect the user to Google's OAuth2 consent screen.
     * Encodes the integration ID in the state parameter so we know
     * which integration to update on callback.
     */
    public function redirect(Request $request, Integration $integration)
    {
        // Only allow GA4 integrations
        if ($integration->integration_type->value !== 'ga4') {
            abort(400, 'OAuth flow is only supported for GA4 integrations.');
        }

        $client = $this->buildClient();

        // State encodes integration ID + CSRF token
        $state = base64_encode(json_encode([
            'integration_id' => $integration->id,
            'csrf'           => csrf_token(),
        ]));

        $client->setState($state);

        $authUrl = $client->createAuthUrl();

        return redirect()->away($authUrl);
    }

    /**
     * Handle the OAuth2 callback from Google.
     * Dispatches to the appropriate handler based on state.purpose.
     */
    public function callback(Request $request)
    {
        // Validate we have a code
        if ($request->has('error')) {
            return redirect('/admin/integrations')
                ->with('filament.notifications', [[
                    'title'  => 'Google Authorization Failed',
                    'body'   => 'Authorization was denied or cancelled.',
                    'status' => 'danger',
                ]]);
        }

        // Decode state
        $state = json_decode(base64_decode($request->input('state', '')), true);

        // Dispatch based on purpose
        $purpose = $state['purpose'] ?? null;

        if ($purpose === 'login') {
            return $this->handleLoginCallback($request, $state);
        }

        if ($purpose === 'workspace') {
            return $this->handleWorkspaceCallback($request, $state);
        }

        // Default: GA4 flow (existing logic, untouched)
        return $this->handleGa4Callback($request, $state);
    }

    /**
     * Revoke the stored OAuth token and reset the integration to Pending.
     */
    public function revoke(Integration $integration)
    {
        $credentials = $integration->credentials_json ?? [];
        $refreshToken = $credentials['refresh_token'] ?? null;

        if ($refreshToken) {
            try {
                $client = $this->buildClient();
                $client->revokeToken($refreshToken);
            } catch (\Exception $e) {
                Log::warning('Failed to revoke Google token remotely', [
                    'integration_id' => $integration->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // Clear credentials except property_id
        $integration->update([
            'credentials_json' => [
                'auth_method'  => 'oauth2_user',
                'property_id'  => data_get($credentials, 'property_id'),
            ],
            'status' => IntegrationStatus::Pending,
        ]);

        return redirect("/admin/integrations/{$integration->id}/edit")
            ->with('success', 'Google account disconnected.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Google Login (Sign-in with Google)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a Google Client configured for login (id_token only, no offline access).
     */
    private function buildLoginClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->setScopes(config('meeting_agent.google.login_scopes', [
            'openid',
            'email',
            'profile',
        ]));
        // Login flow: no offline access needed (no refresh token)
        $client->setAccessType('online');

        return $client;
    }

    /**
     * Redirect unauthenticated users to Google for sign-in.
     */
    public function redirectLogin(Request $request)
    {
        $nonce = Str::random(40);
        $request->session()->put('google_login_nonce', $nonce);

        $client = $this->buildLoginClient();

        $state = base64_encode(json_encode([
            'purpose'     => 'login',
            'nonce'       => $nonce,
            'redirect_to' => '/admin',
        ]));

        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    /**
     * Handle the Google login callback.
     */
    private function handleLoginCallback(Request $request, array $state): \Illuminate\Http\RedirectResponse
    {
        // Verify nonce against session
        $sessionNonce = $request->session()->pull('google_login_nonce');
        if (! $sessionNonce || ($state['nonce'] ?? null) !== $sessionNonce) {
            Log::warning('Google login: nonce mismatch');
            return redirect('/admin/login')
                ->with('error', 'Authentication failed: invalid session state. Please try again.');
        }

        $client = $this->buildLoginClient();

        try {
            $tokenData = $client->fetchAccessTokenWithAuthCode($request->input('code'));
        } catch (\Exception $e) {
            Log::error('Google login: token exchange failed', ['error' => $e->getMessage()]);
            return redirect('/admin/login')
                ->with('error', 'Authentication failed. Please try again.');
        }

        if (isset($tokenData['error'])) {
            Log::error('Google login: error response', [
                'error' => $tokenData['error_description'] ?? $tokenData['error'],
            ]);
            return redirect('/admin/login')
                ->with('error', 'Google returned an error. Please try again.');
        }

        // Decode id_token JWT to extract user info
        $claims = $this->decodeIdToken($tokenData['id_token'] ?? '');

        if (! $claims) {
            return redirect('/admin/login')
                ->with('error', 'Could not verify your identity. Please try again.');
        }

        // REJECT if email_verified is not true
        if (empty($claims['email_verified']) || $claims['email_verified'] !== true) {
            Log::warning('Google login: unverified email', ['email' => $claims['email'] ?? 'unknown']);
            return redirect('/admin/login')
                ->with('error', 'Your Google account email is not verified.');
        }

        $email = $claims['email'] ?? null;
        $googleId = $claims['sub'] ?? null;
        $name = $claims['name'] ?? $email;
        $picture = $claims['picture'] ?? null;

        if (! $email) {
            return redirect('/admin/login')
                ->with('error', 'Could not retrieve your email from Google.');
        }

        // Check if user exists
        $user = User::where('email', $email)->first();

        if ($user) {
            // Update Google fields
            $user->update([
                'google_id'  => $googleId,
                'avatar_url' => $picture,
            ]);
        } else {
            // Check domain against allowed company domains
            $domain = substr(strrchr($email, '@'), 1);
            $allowedDomains = config('meeting_agent.calendar.company_domains', []);

            if (! in_array(strtolower($domain), array_map('strtolower', $allowedDomains), true)) {
                Log::warning('Google login: domain not allowed', [
                    'email'  => $email,
                    'domain' => $domain,
                ]);
                return redirect('/admin/login')
                    ->with('error', 'Your email domain is not authorized. Contact your administrator.');
            }

            // Create new user
            $user = User::create([
                'name'       => $name,
                'email'      => $email,
                'google_id'  => $googleId,
                'avatar_url' => $picture,
                'password'   => bcrypt(Str::random(32)),
            ]);
        }

        Auth::guard('web')->login($user, remember: true);

        $redirectTo = $state['redirect_to'] ?? '/admin';

        return redirect($redirectTo);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Google Workspace connect (Calendar, Gmail, Docs)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a Google Client configured for Workspace scope authorization.
     */
    private function buildWorkspaceClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->setScopes(config('meeting_agent.google.workspace_scopes', [
            'openid',
            'email',
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/gmail.compose',
            'https://www.googleapis.com/auth/drive.file',
        ]));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /**
     * Redirect authenticated users to Google to connect Workspace scopes.
     */
    public function redirectWorkspace(Request $request)
    {
        $nonce = Str::random(40);
        $request->session()->put('google_workspace_nonce', $nonce);

        $client = $this->buildWorkspaceClient();

        $state = base64_encode(json_encode([
            'purpose' => 'workspace',
            'user_id' => Auth::id(),
            'nonce'   => $nonce,
        ]));

        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    /**
     * Handle the Google Workspace callback.
     */
    private function handleWorkspaceCallback(Request $request, array $state): \Illuminate\Http\RedirectResponse
    {
        // Verify nonce against session
        $sessionNonce = $request->session()->pull('google_workspace_nonce');
        if (! $sessionNonce || ($state['nonce'] ?? null) !== $sessionNonce) {
            Log::warning('Google workspace: nonce mismatch');
            return redirect('/admin/client-meetings')
                ->with('error', 'Authorization failed: invalid session state. Please try again.');
        }

        // Verify authenticated user matches state
        if (! Auth::check() || Auth::id() !== ($state['user_id'] ?? null)) {
            Log::warning('Google workspace: user_id mismatch', [
                'auth_id'  => Auth::id(),
                'state_id' => $state['user_id'] ?? null,
            ]);
            return redirect('/admin/client-meetings')
                ->with('error', 'Authorization failed: user mismatch. Please try again.');
        }

        $client = $this->buildWorkspaceClient();

        try {
            $tokenData = $client->fetchAccessTokenWithAuthCode($request->input('code'));
        } catch (\Exception $e) {
            Log::error('Google workspace: token exchange failed', ['error' => $e->getMessage()]);
            return redirect('/admin/client-meetings')
                ->with('error', 'Token exchange failed. Please try again.');
        }

        if (isset($tokenData['error'])) {
            Log::error('Google workspace: error response', [
                'error' => $tokenData['error_description'] ?? $tokenData['error'],
            ]);
            return redirect('/admin/client-meetings')
                ->with('error', 'Google returned an error. Please try again.');
        }

        // Verify we got a refresh token
        if (empty($tokenData['refresh_token'])) {
            return redirect('/admin/client-meetings')
                ->with('error', 'No refresh token received. Please revoke access at myaccount.google.com/permissions and try again.');
        }

        // Decode id_token for email
        $claims = $this->decodeIdToken($tokenData['id_token'] ?? '');
        $authorizedEmail = $claims['email'] ?? 'Unknown';

        // Extract granted scopes from token response
        $grantedScopes = [];
        if (! empty($tokenData['scope'])) {
            $grantedScopes = explode(' ', $tokenData['scope']);
        }

        // Create or update ConnectedAccount
        ConnectedAccount::updateOrCreate(
            [
                'user_id'  => Auth::id(),
                'provider' => 'google_workspace',
            ],
            [
                'authorized_email' => $authorizedEmail,
                'credentials_json' => [
                    'refresh_token' => $tokenData['refresh_token'],
                    'token_type'    => $tokenData['token_type'] ?? 'Bearer',
                    'authorized_at' => now()->toISOString(),
                ],
                'granted_scopes'   => $grantedScopes,
                'status'           => ConnectedAccountStatus::Active,
                'last_error'       => null,
            ]
        );

        return redirect('/admin/client-meetings')
            ->with('success', "Google Workspace connected: {$authorizedEmail}");
    }

    /**
     * Revoke the user's Google Workspace connected account.
     */
    public function revokeWorkspace(Request $request)
    {
        $account = ConnectedAccount::where('user_id', Auth::id())
            ->where('provider', 'google_workspace')
            ->first();

        if (! $account) {
            return redirect('/admin/client-meetings')
                ->with('error', 'No Google Workspace account connected.');
        }

        $credentials = $account->credentials_json ?? [];
        $refreshToken = $credentials['refresh_token'] ?? null;

        if ($refreshToken) {
            try {
                $client = $this->buildWorkspaceClient();
                $client->revokeToken($refreshToken);
            } catch (\Exception $e) {
                Log::warning('Failed to revoke Google Workspace token remotely', [
                    'user_id' => Auth::id(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $account->update([
            'status'           => ConnectedAccountStatus::Revoked,
            'credentials_json' => [],
            'last_error'       => null,
        ]);

        return redirect('/admin/client-meetings')
            ->with('success', 'Google Workspace account disconnected.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // GA4 callback handler (extracted from original callback, unchanged logic)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Handle the GA4 OAuth callback — original logic, preserved as-is.
     */
    private function handleGa4Callback(Request $request, array $state): \Illuminate\Http\RedirectResponse
    {
        $integrationId = $state['integration_id'] ?? null;

        if (! $integrationId) {
            abort(400, 'Invalid OAuth state.');
        }

        $integration = Integration::findOrFail($integrationId);

        // Exchange authorization code for tokens
        $client = $this->buildClient();

        try {
            $tokenData = $client->fetchAccessTokenWithAuthCode($request->input('code'));
        } catch (\Exception $e) {
            Log::error('Google OAuth token exchange failed', [
                'integration_id' => $integrationId,
                'error'          => $e->getMessage(),
            ]);

            return redirect("/admin/integrations/{$integrationId}/edit")
                ->with('error', 'Token exchange failed. Please try again.');
        }

        if (isset($tokenData['error'])) {
            Log::error('Google OAuth error response', [
                'integration_id' => $integrationId,
                'error'          => $tokenData['error_description'] ?? $tokenData['error'],
            ]);

            return redirect("/admin/integrations/{$integrationId}/edit")
                ->with('error', 'Google returned an error. Please try again.');
        }

        // Verify we got a refresh token
        if (empty($tokenData['refresh_token'])) {
            return redirect("/admin/integrations/{$integrationId}/edit")
                ->with('error', 'No refresh token received. Please revoke access at myaccount.google.com/permissions and try again.');
        }

        // Extract authorized email from the id_token JWT payload
        // Google includes this when openid + email scopes are requested
        // No extra API call needed — avoids 401 from missing userinfo scope
        $authorizedEmail = 'Unknown';
        $idToken = $tokenData['id_token'] ?? null;
        if ($idToken) {
            $parts = explode('.', $idToken);
            if (count($parts) === 3) {
                $payload = json_decode(
                    base64_decode(str_pad(
                        strtr($parts[1], '-_', '+/'),
                        strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + 4 - (strlen($parts[1]) % 4),
                        '='
                    )),
                    true
                );
                $authorizedEmail = $payload['email'] ?? 'Unknown';
            }
        }

        // Build credentials payload — stored encrypted via model cast
        $credentials = [
            'auth_method'      => 'oauth2_user',
            'authorized_email' => $authorizedEmail,
            'refresh_token'    => $tokenData['refresh_token'],
            'token_type'       => $tokenData['token_type'] ?? 'Bearer',
            'authorized_at'    => now()->toISOString(),
            // Keep existing property_id if already set
            'property_id'      => data_get($integration->credentials_json, 'property_id'),
        ];

        // Update integration
        $integration->update([
            'credentials_json' => $credentials,
            'status'           => IntegrationStatus::Active,
        ]);

        return redirect("/admin/integrations/{$integrationId}/edit")
            ->with('success', "Google account authorized: {$authorizedEmail}");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Decode a Google id_token JWT and return the payload claims.
     * Uses manual base64url decoding (same pattern as existing GA4 flow).
     */
    private function decodeIdToken(string $idToken): ?array
    {
        if (empty($idToken)) {
            return null;
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(
            base64_decode(str_pad(
                strtr($parts[1], '-_', '+/'),
                strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + 4 - (strlen($parts[1]) % 4),
                '='
            )),
            true
        );

        return is_array($payload) ? $payload : null;
    }
}
