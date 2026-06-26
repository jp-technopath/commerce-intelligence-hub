<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use Google\Client as GoogleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleOAuthController extends Controller
{
    /**
     * Build and return a configured Google Client.
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
     * Exchanges the authorization code for tokens and stores the
     * refresh token encrypted in the integration's credentials_json.
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
}
