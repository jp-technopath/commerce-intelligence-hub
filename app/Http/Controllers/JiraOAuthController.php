<?php

namespace App\Http\Controllers;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JiraOAuthController extends Controller
{
    /**
     * Redirect authenticated users to Atlassian Jira to authorize individual access.
     */
    public function redirect(Request $request)
    {
        $clientId = config('meeting_agent.jira.client_id');
        $redirectUri = config('meeting_agent.jira.redirect_uri');

        if (empty($clientId) || empty($redirectUri)) {
            return redirect('/admin/my-profile')
                ->with('error', 'Jira OAuth client_id or redirect_uri is not configured in environment.');
        }

        $nonce = Str::random(40);
        $request->session()->put('jira_oauth_nonce', $nonce);

        $state = base64_encode(json_encode([
            'user_id' => Auth::id(),
            'nonce'   => $nonce,
        ]));

        $queries = http_build_query([
            'audience'      => 'api.atlassian.com',
            'client_id'     => $clientId,
            'scope'         => 'read:jira-work write:jira-work read:jira-user offline_access',
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'response_type' => 'code',
            'prompt'        => 'consent',
        ]);

        $authUrl = 'https://auth.atlassian.com/authorize?' . $queries;

        return redirect()->away($authUrl);
    }

    /**
     * Handle the Jira OAuth 2.0 callback.
     */
    public function callback(Request $request)
    {
        if ($request->has('error')) {
            Log::error('Jira OAuth callback error', [
                'error'             => $request->input('error'),
                'error_description' => $request->input('error_description'),
            ]);
            return redirect('/admin/my-profile')
                ->with('error', 'Jira Authorization failed: ' . $request->input('error_description', $request->input('error')));
        }

        $stateRaw = $request->input('state', '');
        $state = json_decode(base64_decode($stateRaw), true);

        if (! $state) {
            Log::warning('Jira OAuth callback: invalid or missing state');
            return redirect('/admin/my-profile')
                ->with('error', 'Authorization failed: invalid state.');
        }

        // Validate session nonce
        $sessionNonce = $request->session()->pull('jira_oauth_nonce');
        if (! $sessionNonce || ($state['nonce'] ?? null) !== $sessionNonce) {
            Log::warning('Jira OAuth callback: nonce mismatch');
            return redirect('/admin/my-profile')
                ->with('error', 'Authorization failed: invalid session state. Please try again.');
        }

        // Validate authenticated user matches state
        if (! Auth::check() || Auth::id() !== ($state['user_id'] ?? null)) {
            Log::warning('Jira OAuth callback: user_id mismatch', [
                'auth_id'  => Auth::id(),
                'state_id' => $state['user_id'] ?? null,
            ]);
            return redirect('/admin/my-profile')
                ->with('error', 'Authorization failed: user session mismatch. Please try again.');
        }

        $clientId = config('meeting_agent.jira.client_id');
        $clientSecret = config('meeting_agent.jira.client_secret');
        $redirectUri = config('meeting_agent.jira.redirect_uri');

        // Exchange Authorization Code for Access & Refresh Tokens
        $response = Http::post('https://auth.atlassian.com/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $request->input('code'),
            'redirect_uri'  => $redirectUri,
        ]);

        if (! $response->successful()) {
            Log::error('Jira OAuth token exchange failed', ['body' => $response->body()]);
            return redirect('/admin/my-profile')
                ->with('error', 'Token exchange failed. Please try again.');
        }

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        if (! $accessToken || ! $refreshToken) {
            Log::error('Jira OAuth token exchange response incomplete', ['response' => $tokenData]);
            return redirect('/admin/my-profile')
                ->with('error', 'Received incomplete token package from Atlassian.');
        }

        // Fetch Accessible Resources (Sites) to get cloudId and Site details
        $resourcesResponse = Http::withToken($accessToken)
            ->get('https://api.atlassian.com/oauth/token/accessible-resources');

        if (! $resourcesResponse->successful() || empty($resourcesResponse->json())) {
            Log::error('Jira OAuth failed to fetch accessible resources', [
                'status' => $resourcesResponse->status(),
                'body'   => $resourcesResponse->body(),
            ]);
            return redirect('/admin/my-profile')
                ->with('error', 'Could not retrieve accessible Jira sites for your account.');
        }

        $sites = $resourcesResponse->json();
        $primarySite = $sites[0]; // Connect primary site automatically

        $cloudId = $primarySite['id'] ?? null;
        $siteUrl = $primarySite['url'] ?? null;
        $siteName = $primarySite['name'] ?? 'Jira Cloud Site';

        if (! $cloudId) {
            Log::error('Jira OAuth primary site missing id/cloudId', ['site' => $primarySite]);
            return redirect('/admin/my-profile')
                ->with('error', 'Retrieve Jira cloud site identifier failed.');
        }

        // Save or update the user's Jira ConnectedAccount
        ConnectedAccount::updateOrCreate(
            [
                'user_id'  => Auth::id(),
                'provider' => 'jira',
            ],
            [
                'authorized_email' => $siteUrl,
                'credentials_json' => [
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                    'cloud_id'      => $cloudId,
                    'site_url'      => $siteUrl,
                    'site_name'     => $siteName,
                ],
                'granted_scopes'   => $primarySite['scopes'] ?? ['read:jira-work', 'read:jira-user'],
                'status'           => ConnectedAccountStatus::Active,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'last_error'       => null,
            ]
        );

        return redirect('/admin/my-profile')
            ->with('success', "Jira connected successfully to: {$siteName}");
    }

    /**
     * Revoke the user's Jira Integration.
     */
    public function revoke(Request $request)
    {
        $account = ConnectedAccount::where('user_id', Auth::id())
            ->where('provider', 'jira')
            ->first();

        if (! $account) {
            return redirect('/admin/my-profile')
                ->with('error', 'No Jira integration connected.');
        }

        $account->delete();

        return redirect('/admin/my-profile')
            ->with('success', 'Jira account disconnected successfully.');
    }
}
